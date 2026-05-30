# Migrations Base de Données — serveur n3 IoT

Scripts SQL pour la base MySQL de production (`oliviera_iot` sur iot.olution.info) et la stack Docker locale.

## Audit production 2026-05 (oliviera_iot3)

Comparaison dump prod vs serveur **5.1.3** : voir le plan d'audit dans le dépôt parent.

### Checklist rapide prod

| Élément | Script | Priorité |
|---------|--------|----------|
| Diagnostic initial | `00_diagnostic_prod.sql` | Avant toute migration |
| Bundle complet (phases 1–4) | `APPLY_PROD_AUDIT_2026.sql` | **Recommandé** (phpMyAdmin) |
| `post_id` ffp3Data–4 | `001_add_post_id.sql` | Critique |
| `post_id` ffp3DataS3* | `001b_add_post_id_s3.sql` | Critique |
| Colonnes config FFP3 | `ADD_MISSING_COLUMNS_v11.36.sql` | Critique |
| Heartbeats msp/n3pp | `CREATE_LEGACY_HEARTBEAT_TABLES.sql` | Critique |
| Poissonglouton | `CREATE_PGL_TABLES.sql` | Si appareil déployé |
| Trigger OTA | `CREATE_FFP3_OTA_TRIGGER_TABLE.sql` | Recommandé (auto-créé en 5.1.3+) |
| Colonnes marée `tide*` | `002_add_tide_event_columns.sql` | Déjà OK sur prod (2026-05) |
| Doublons GPIO | `FIX_DUPLICATE_GPIO_ROWS.sql` puis `INIT_GPIO_BASE_ROWS.sql` | Si diagnostic le signale |
| Validation post-migration | `99_validate_prod.sql` | Après migration |

### Procédure phpMyAdmin (production)

1. **Backup** : export complet de `oliviera_iot`.
2. **Diagnostic** : exécuter `00_diagnostic_prod.sql` (lecture seule).
3. **Migration** : exécuter `APPLY_PROD_AUDIT_2026.sql`  
   — ou les scripts individuels dans l'ordre du tableau ci-dessus.
4. **Poissonglouton** : après `CREATE_PGL_TABLES`, remplacer le token placeholder :
   ```sql
   UPDATE pglBoards
   SET secret_url_token = '<token_fort_64_chars>'
   WHERE board_id = 'poissonglouton';
   ```
5. **Validation** : exécuter `99_validate_prod.sql`.
6. **Hors SQL** : vérifier `API_SIG_SECRET` dans le `.env` prod (aligné firmwares HMAC).

**Erreurs attendues si migration partielle** : `Duplicate column name`, `Duplicate key name` — ignorer le bloc concerné.

**Attention** : `ALTER TABLE ffp3Data` (~1 M lignes) peut prendre plusieurs minutes.

### Procédure CLI (SSH)

```bash
cd /home4/oliviera/iot.olution.info
mysql -u oliviera_iot -p oliviera_iot < migrations/00_diagnostic_prod.sql
mysql -u oliviera_iot -p oliviera_iot < migrations/APPLY_PROD_AUDIT_2026.sql
mysql -u oliviera_iot -p oliviera_iot < migrations/99_validate_prod.sql
```

### Docker local

Les init scripts `docker/mysql/init/` incluent désormais :

- `85-legacy-heartbeats.sql` — `msp1Heartbeat`, `n3ppHeartbeat`
- `95-ffp3-ota-trigger.sql` — `ffp3OtaTrigger`
- `90-poissonglouton.sql` — `pglBoards`, `pglEvents`
- `00-schema.sql` — schéma `ffp3Data*` avec `tide*`, config et `post_id`

Après modification des init scripts : `local-docker.ps1 -Action down -v` puis `up` (reset volume).

---

## Marées min/max — colonnes `tide*` (2026-05-25)

Le firmware FFP5CS v13.81+ envoie : `tideEvent`, `tideTrend`, `tideNoiseMm`, `tideWindowMs`, `tideExtremeMm`.

```bash
mysql -u oliviera_iot -p oliviera_iot < migrations/002_add_tide_event_columns.sql
```

Tables : `ffp3Data`, `ffp3Data2`, `ffp3Data3`, `ffp3Data4`, `ffp3DataS3`, `ffp3DataS3Test`.

Sur la prod auditée (mai 2026), ces colonnes étaient **déjà présentes** — ne pas réexécuter si `SHOW COLUMNS ... LIKE 'tide%'` retourne 5 colonnes.

---

## Correction des doublons GPIO (2025-10-13)

### Problème

Des lignes vides avec `gpio=16` (et autres GPIO) se créent dans `ffp3Outputs`.

### Procédure

1. `FIX_DUPLICATE_GPIO_ROWS.sql` (nettoyage + contrainte UNIQUE)
2. `INIT_GPIO_BASE_ROWS.sql` (initialisation GPIO 2, 15, 16, 18, 100–116)

**Sauvegarde recommandée** :

```bash
mysqldump -u oliviera_iot -p oliviera_iot ffp3Outputs ffp3Outputs2 > backup_outputs_$(date +%Y%m%d).sql
```

### Vérification

```sql
SELECT gpio, COUNT(*) AS nb FROM ffp3Outputs GROUP BY gpio HAVING nb > 1;
SHOW INDEXES FROM ffp3Outputs WHERE Key_name = 'unique_gpio';
```

---

## Inventaire des scripts

| Fichier | Description |
|---------|-------------|
| `00_diagnostic_prod.sql` | Requêtes lecture seule (audit prod) |
| `001_add_post_id.sql` | `post_id` + index UNIQUE sur ffp3Data–4 |
| `001b_add_post_id_s3.sql` | `post_id` sur ffp3DataS3 / S3Test |
| `002_add_tide_event_columns.sql` | Colonnes marée sur ffp3Data* |
| `ADD_MISSING_COLUMNS_v11.36.sql` | tempsGros, WakeUp, Pression, configSynced… |
| `ADD_LASTMODIFIEDBY_COLUMN.sql` | Sync bidirectionnelle GPIO (souvent déjà en prod) |
| `ADD_N3PP_WAKEUP_COLUMNS.sql` | WakeUp n3pp (souvent déjà en prod) |
| `APPLY_PROD_AUDIT_2026.sql` | Bundle phases 1–4 audit 2026-05 |
| `99_validate_prod.sql` | Contrôles post-migration |
| `CREATE_LEGACY_HEARTBEAT_TABLES.sql` | msp1Heartbeat, n3ppHeartbeat |
| `CREATE_PGL_TABLES.sql` | Poissonglouton |
| `CREATE_FFP3_OTA_TRIGGER_TABLE.sql` | Bouton « Vérifier OTA » |
| `CREATE_ERROR_ALERTS_TABLE.sql` | Alertes (auto-créée aussi par le code) |
| `CREATE_TEST_TABLES.sql` | ffp3Data2 / Outputs2 / Heartbeat2 legacy |
| `FIX_DUPLICATE_GPIO_ROWS.sql` | Nettoyage doublons GPIO |
| `INIT_GPIO_BASE_ROWS.sql` | Lignes GPIO de base |
| `CLEAN_NULL_OUTPUTS_v11.38.sql` | Nettoyage outputs NULL |

---

## Changelog migrations

- **2026-05-30** : Audit prod oliviera_iot3 — `APPLY_PROD_AUDIT_2026.sql`, `001b`, `ADD_MISSING_COLUMNS` consolidé, `00_diagnostic_prod`, `99_validate_prod`, init Docker 85/95
- **2026-05-25** : `002_add_tide_event_columns.sql`
- **2026-05** : `CREATE_LEGACY_HEARTBEAT_TABLES.sql`, `CREATE_PGL_TABLES.sql`
- **2025-10-13** : `FIX_DUPLICATE_GPIO_ROWS.sql`, `INIT_GPIO_BASE_ROWS.sql`
