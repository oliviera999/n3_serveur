#!/usr/bin/env bash
# =============================================================================
# Élagage N3PP prod (niveaux 1a + 2a–2b) — exécution automatique sur le serveur
# =============================================================================
# Remplace l'exécution manuelle de :
#   migrations/2026_07_PROD_03b_prune_n3pp_double_post_batched.sql
#   migrations/2026_07_PROD_03c_prune_level2.sql
#
# Boucle les DELETE par lots jusqu'à reste = 0, puis applique le niveau 2.
#
# Usage (depuis la racine du dépôt serveur, ex. iot.olution.info) :
#   bash tools/run-prod-prune-n3pp.sh --confirm
#   bash tools/run-prod-prune-n3pp.sh --confirm --with-indexes
#   bash tools/run-prod-prune-n3pp.sh --confirm --with-indexes --with-validate
#   bash tools/run-prod-prune-n3pp.sh --dry-run
#
# Options :
#   --confirm          Obligatoire pour exécuter (sécurité)
#   --dry-run          Affiche le plan sans modifier la BDD
#   --with-indexes     Exécute aussi 2026_07_PROD_04_indexes.sql (idempotent)
#   --with-validate    Exécute 99_validate_prod.sql en fin de run
#   --insert-window N  Fenêtre d'id par INSERT (défaut : 100000)
#   --delete-batch N   Taille des lots DELETE (défaut : 3000)
#   --env-file PATH    Fichier .env (défaut : .env à la racine serveur)
# =============================================================================

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

INSERT_WINDOW=100000
DELETE_BATCH=3000
ENV_FILE="${ROOT}/.env"
DO_CONFIRM=0
DRY_RUN=0
WITH_INDEXES=0
WITH_VALIDATE=0
LOG_DIR="${ROOT}/var/log"
LOG_FILE=""
HELPER_TABLE="_n3pp_dup_delete"

usage() {
    sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --confirm) DO_CONFIRM=1 ;;
        --dry-run) DRY_RUN=1 ;;
        --with-indexes) WITH_INDEXES=1 ;;
        --with-validate) WITH_VALIDATE=1 ;;
        --insert-window) INSERT_WINDOW="$2"; shift ;;
        --delete-batch) DELETE_BATCH="$2"; shift ;;
        --env-file) ENV_FILE="$2"; shift ;;
        -h|--help) usage 0 ;;
        *) echo "Option inconnue : $1" >&2; usage 1 ;;
    esac
    shift
done

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$msg"
    if [[ -n "$LOG_FILE" ]]; then
        echo "$msg" >> "$LOG_FILE"
    fi
}

load_env_var() {
    local key="$1"
    local file="$2"
    if [[ ! -f "$file" ]]; then
        echo ""
        return
    fi
    local line
    line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"
    if [[ -z "$line" ]]; then
        echo ""
        return
    fi
    local val="${line#*=}"
    val="${val%$'\r'}"
    val="${val#\"}"
    val="${val%\"}"
    val="${val#\'}"
    val="${val%\'}"
    echo "$val"
}

DB_HOST="$(load_env_var DB_HOST "$ENV_FILE")"
DB_NAME="$(load_env_var DB_NAME "$ENV_FILE")"
DB_USER="$(load_env_var DB_USER "$ENV_FILE")"
DB_PASS="$(load_env_var DB_PASS "$ENV_FILE")"

DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-oliviera_iot}"
DB_USER="${DB_USER:-oliviera_iot}"

if [[ -z "$DB_PASS" && "$DRY_RUN" -eq 0 ]]; then
    echo "Erreur : DB_PASS introuvable dans ${ENV_FILE}" >&2
    echo "Copiez .env.example vers .env ou utilisez --env-file" >&2
    exit 1
fi

if [[ "$DO_CONFIRM" -eq 0 && "$DRY_RUN" -eq 0 ]]; then
    echo "Refus d'exécuter sans --confirm (modification BDD prod)." >&2
    echo "Simulation : ajoutez --dry-run" >&2
    exit 1
fi

mkdir -p "$LOG_DIR"
LOG_FILE="${LOG_DIR}/prune-n3pp-$(date '+%Y%m%d-%H%M%S').log"
touch "$LOG_FILE"
chmod 600 "$LOG_FILE" 2>/dev/null || true

MYSQL_CNF="$(mktemp)"
chmod 600 "$MYSQL_CNF"
cat > "$MYSQL_CNF" <<EOF
[client]
host=${DB_HOST}
user=${DB_USER}
password=${DB_PASS}
database=${DB_NAME}
EOF
trap 'rm -f "$MYSQL_CNF"' EXIT

mysql_exec() {
    mysql --defaults-extra-file="$MYSQL_CNF" --batch --raw "$@"
}

mysql_scalar() {
    mysql_exec -N -e "$1" | tr -d '\r' | head -n 1
}

mysql_file() {
    mysql_exec < "$1"
}

run_sql() {
    local sql="$1"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        log "[dry-run] SQL (${#sql} octets)"
        return 0
    fi
    mysql_exec -e "$sql"
}

log "=== Élagage N3PP prod ==="
log "Racine : $ROOT"
log "BDD    : ${DB_USER}@${DB_HOST}/${DB_NAME}"
log "Fenêtre INSERT : ${INSERT_WINDOW} | lot DELETE : ${DELETE_BATCH}"

if [[ "$DRY_RUN" -eq 1 ]]; then
    log "Mode dry-run — aucune écriture"
else
    ROW_COUNT="$(mysql_scalar "SELECT COUNT(*) FROM n3ppData")"
    MAX_ID="$(mysql_scalar "SELECT COALESCE(MAX(id), 0) FROM n3ppData")"
    log "n3ppData : ${ROW_COUNT} lignes, max(id)=${MAX_ID}"
fi

# ---------------------------------------------------------------------------
# Phase A — table de travail
# ---------------------------------------------------------------------------
log "Phase A — table ${HELPER_TABLE}"
run_sql "DROP TABLE IF EXISTS \`${HELPER_TABLE}\`;"
run_sql "CREATE TABLE \`${HELPER_TABLE}\` (
    \`id\` BIGINT NOT NULL PRIMARY KEY
) ENGINE=InnoDB;"

if [[ "$DRY_RUN" -eq 1 ]]; then
    MAX_ID=1858087
fi

if [[ "${MAX_ID:-0}" -eq 0 && "$DRY_RUN" -eq 0 ]]; then
    log "Aucune ligne dans n3ppData — fin."
    exit 0
fi

# ---------------------------------------------------------------------------
# Phase B — collecte des ids doublons par fenêtres
# ---------------------------------------------------------------------------
log "Phase B — collecte des doublons (LEAD / Δt ≤ 2 s)"

lo=1
chunk=1
while [[ "$lo" -le "${MAX_ID:-0}" ]]; do
    hi=$((lo + INSERT_WINDOW - 1))
    if [[ "$hi" -gt "${MAX_ID:-0}" ]]; then
        hi="${MAX_ID:-0}"
    fi

    log "  B.${chunk} ids ${lo} .. ${hi}"

    insert_sql="
INSERT IGNORE INTO \`${HELPER_TABLE}\` (\`id\`)
SELECT \`dup_id\` FROM (
    SELECT
        \`id\`,
        LEAD(\`id\`) OVER (
            PARTITION BY \`TempAir\`, \`Humidite\`, \`Luminosite\`, \`HumidMoy\`, \`etatPompe\`, \`sensor\`, \`version\`
            ORDER BY \`id\`
        ) AS \`dup_id\`,
        \`reading_time\`,
        LEAD(\`reading_time\`) OVER (
            PARTITION BY \`TempAir\`, \`Humidite\`, \`Luminosite\`, \`HumidMoy\`, \`etatPompe\`, \`sensor\`, \`version\`
            ORDER BY \`id\`
        ) AS \`dup_rt\`
    FROM \`n3ppData\`
    WHERE \`id\` BETWEEN ${lo} AND ${hi}
) AS \`t\`
WHERE \`dup_id\` IS NOT NULL
  AND \`dup_rt\` BETWEEN \`reading_time\` AND DATE_ADD(\`reading_time\`, INTERVAL 2 SECOND);
"
    run_sql "$insert_sql"

    if [[ "$DRY_RUN" -eq 0 ]]; then
        pending="$(mysql_scalar "SELECT COUNT(*) FROM \`${HELPER_TABLE}\`")"
        log "      → cumul ids à supprimer : ${pending}"
    fi

    lo=$((hi + 1))
    chunk=$((chunk + 1))
done

if [[ "$DRY_RUN" -eq 1 ]]; then
    log "Phase B dry-run terminée (${chunk} fenêtres prévues)"
    rm -f "$MYSQL_CNF"
    trap - EXIT
    exit 0
fi

PENDING="$(mysql_scalar "SELECT COUNT(*) FROM \`${HELPER_TABLE}\`")"
log "Doublons identifiés : ${PENDING}"

if [[ "$PENDING" -eq 0 ]]; then
    log "Rien à supprimer (niveau 1a) — passage au niveau 2."
else
    # -----------------------------------------------------------------------
    # Phase C — DELETE par lots jusqu'à épuisement
    # -----------------------------------------------------------------------
    log "Phase C — suppression par lots de ${DELETE_BATCH}"
    iteration=0
    while [[ "$PENDING" -gt 0 ]]; do
        iteration=$((iteration + 1))
        mysql_exec -e "
DELETE FROM \`n3ppData\`
WHERE \`id\` IN (
    SELECT \`id\` FROM (
        SELECT \`id\` FROM \`${HELPER_TABLE}\` ORDER BY \`id\` LIMIT ${DELETE_BATCH}
    ) AS \`batch\`
);
DELETE FROM \`${HELPER_TABLE}\`
WHERE \`id\` IN (
    SELECT \`id\` FROM (
        SELECT \`id\` FROM \`${HELPER_TABLE}\` ORDER BY \`id\` LIMIT ${DELETE_BATCH}
    ) AS \`batch\`
);
"
        PENDING="$(mysql_scalar "SELECT COUNT(*) FROM \`${HELPER_TABLE}\`")"
        if (( iteration % 20 == 0 )) || [[ "$PENDING" -eq 0 ]]; then
            ROW_COUNT="$(mysql_scalar "SELECT COUNT(*) FROM n3ppData")"
            log "  lot #${iteration} — reste ${PENDING} ids | n3ppData=${ROW_COUNT} lignes"
        fi
    done
    log "Phase C terminée (${iteration} lots)"
fi

run_sql "DROP TABLE IF EXISTS \`${HELPER_TABLE}\`;"

# ---------------------------------------------------------------------------
# Phase D — niveaux 2a–2b
# ---------------------------------------------------------------------------
log "Phase D — niveau 2 (DHT 0/0, emails aberrants)"
if [[ -f "${ROOT}/migrations/2026_07_PROD_03c_prune_level2.sql" ]]; then
    mysql_file "${ROOT}/migrations/2026_07_PROD_03c_prune_level2.sql"
else
    run_sql "DELETE FROM \`n3ppData\` WHERE \`TempAir\` = 0 AND \`Humidite\` = 0;"
    run_sql "DELETE FROM \`n3ppDataTest\` WHERE \`TempAir\` = 0 AND \`Humidite\` = 0;"
    run_sql "DELETE FROM \`n3ppData\` WHERE \`mail\` LIKE '%gmailsdfg%';"
    run_sql "DELETE FROM \`n3ppDataTest\` WHERE \`mail\` LIKE '%gmailsdfg%';"
fi

# ---------------------------------------------------------------------------
# Phase E — indexes (optionnel)
# ---------------------------------------------------------------------------
if [[ "$WITH_INDEXES" -eq 1 ]]; then
    log "Phase E — indexes (04)"
    idx_file="${ROOT}/migrations/2026_07_PROD_04_indexes.sql"
    if [[ -f "$idx_file" ]]; then
        while IFS= read -r stmt; do
            [[ -z "$stmt" ]] && continue
            [[ "$stmt" =~ ^-- ]] && continue
            if mysql_exec -e "$stmt" 2>>"$LOG_FILE"; then
                log "  OK : ${stmt:0:80}..."
            else
                log "  Ignoré (probablement déjà présent) : ${stmt:0:80}..."
            fi
        done < <(grep -E '^ALTER TABLE' "$idx_file" || true)
    else
        log "  Fichier 04 introuvable — ignoré"
    fi
fi

# ---------------------------------------------------------------------------
# Phase F — validation (optionnel)
# ---------------------------------------------------------------------------
if [[ "$WITH_VALIDATE" -eq 1 ]]; then
    log "Phase F — validation 99_validate_prod.sql"
    validate_out="${LOG_DIR}/validate-prod-$(date '+%Y%m%d-%H%M%S').txt"
    mysql_file "${ROOT}/migrations/99_validate_prod.sql" | tee "$validate_out"
    log "Résultat validation : ${validate_out}"
fi

# ---------------------------------------------------------------------------
# Résumé
# ---------------------------------------------------------------------------
FINAL_COUNT="$(mysql_scalar "SELECT COUNT(*) FROM n3ppData")"
log "=== Terminé ==="
log "n3ppData final : ${FINAL_COUNT} lignes"
log "Journal : ${LOG_FILE}"
