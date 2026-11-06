<?php

declare(strict_types=1);

namespace App\Repository;

use App\Mcp\Support\FieldCatalog;
use PDO;

/**
 * Lectures dédiées au connecteur MCP : agrégations SQL par intervalle, dernières
 * mesures, heartbeat, outputs, alertes, nourrissages.
 *
 * Toutes les tables sont résolues via {@see FieldCatalog} → {@see \App\Config\TableConfig}
 * pour l'environnement COURANT et revalidées contre une liste blanche ; toutes les
 * colonnes proviennent du catalogue (jamais de l'appelant) ; chaque requête porte un
 * `LIMIT`. L'agrégation par intervalle se fait côté SQL (GROUP BY sur le bucket de
 * `reading_time`) et s'appuie sur l'index `reading_time` existant (filtre BETWEEN).
 *
 * Aucune méthode n'écrit : la classe est utilisée sous {@see \App\Mcp\Support\ReadOnlyTransaction}.
 */
class McpReadRepository extends AbstractRepository
{
    /** Plafond de lignes brutes ramenées en mémoire (analyses marée/énergie). */
    public const MAX_RAW_ROWS = 20000;

    private const ALLOWED_TABLES = [
        'ffp3Data', 'ffp3Data2', 'ffp3Data3', 'ffp3DataS3', 'ffp3DataS3Test',
        'n3ppData', 'n3ppDataTest', 'msp1Data', 'msp1DataTest', 'energieData', 'energieDataTest',
        'ffp3Outputs', 'ffp3Outputs2', 'ffp3Outputs3', 'ffp3OutputsS3', 'ffp3OutputsS3Test',
        'n3ppOutputs', 'n3ppOutputsTest', 'msp1Outputs', 'msp1OutputsTest',
        'ffp3Heartbeat', 'ffp3Heartbeat2', 'ffp3Heartbeat3', 'ffp3HeartbeatS3', 'ffp3HeartbeatS3Test',
        'n3ppHeartbeat', 'n3ppHeartbeatTest', 'msp1Heartbeat', 'msp1HeartbeatTest',
    ];

    /**
     * Dernière mesure (colonnes du catalogue + version firmware), ou null.
     *
     * @return array<string, mixed>|null
     */
    public function latest(string $family): ?array
    {
        $table = $this->table(FieldCatalog::dataTable($family));
        $columns = $this->columnList($family, array_keys(FieldCatalog::fields($family)));

        return $this->fetchOne(
            "SELECT {$columns}, `version`, `reading_time` FROM `{$table}` ORDER BY reading_time DESC LIMIT 1"
        );
    }

    public function lastReadingTime(string $family): ?string
    {
        $table = $this->table(FieldCatalog::dataTable($family));
        $value = $this->fetchScalar("SELECT MAX(reading_time) FROM `{$table}`");

        return $value !== null ? (string) $value : null;
    }

    /**
     * Série agrégée par bucket de `$bucketSeconds` secondes.
     *
     * @param list<string> $fields Champs validés contre le catalogue
     * @param 'avg'|'min'|'max' $agg
     * @return list<array<string, mixed>> lignes {bucket_ts:int, n:int, <champ>:float|null}
     */
    public function aggregate(string $family, array $fields, string $from, string $to, int $bucketSeconds, string $agg): array
    {
        $table = $this->table(FieldCatalog::dataTable($family));
        $function = match ($agg) {
            'min' => 'MIN',
            'max' => 'MAX',
            default => 'AVG',
        };
        $selects = [];
        foreach ($this->validFields($family, $fields, true) as $field) {
            $selects[] = "{$function}(`{$field}`) AS `{$field}`";
        }
        if ($selects === []) {
            return [];
        }
        $bucket = $this->bucketExpression($bucketSeconds);
        $sql = "SELECT {$bucket} AS bucket_ts, COUNT(*) AS n, " . implode(', ', $selects)
            . " FROM `{$table}` WHERE reading_time BETWEEN :from AND :to"
            . ' GROUP BY bucket_ts ORDER BY bucket_ts ASC LIMIT 1000';

        return $this->fetchAll($sql, [':from' => $from, ':to' => $to]);
    }

    /**
     * Statistiques globales par champ sur la fenêtre (une seule requête).
     *
     * @param list<string> $fields
     * @return array<string, mixed> {rows:int, first:?string, last:?string, <champ>__min/max/avg/std/n}
     */
    public function stats(string $family, array $fields, string $from, string $to): array
    {
        $table = $this->table(FieldCatalog::dataTable($family));
        $selects = ['COUNT(*) AS `rows`', 'MIN(reading_time) AS `first`', 'MAX(reading_time) AS `last`'];
        foreach ($this->validFields($family, $fields, true) as $field) {
            $selects[] = "MIN(`{$field}`) AS `{$field}__min`";
            $selects[] = "MAX(`{$field}`) AS `{$field}__max`";
            $selects[] = "AVG(`{$field}`) AS `{$field}__avg`";
            $selects[] = "COUNT(`{$field}`) AS `{$field}__n`";
            $selects[] = $this->stddevExpression($field) . " AS `{$field}__std`";
        }
        $row = $this->fetchOne(
            'SELECT ' . implode(', ', $selects) . " FROM `{$table}` WHERE reading_time BETWEEN :from AND :to",
            [':from' => $from, ':to' => $to]
        );

        return $row ?? [];
    }

    /**
     * Lignes brutes (colonnes choisies) en ordre chronologique, plafonnées.
     *
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public function rawRows(string $family, array $fields, string $from, string $to, int $limit = self::MAX_RAW_ROWS): array
    {
        $table = $this->table(FieldCatalog::dataTable($family));
        $columns = $this->columnList($family, $fields);
        $limit = max(1, min(self::MAX_RAW_ROWS, $limit));

        return $this->fetchAll(
            "SELECT {$columns}, `reading_time` FROM `{$table}` WHERE reading_time BETWEEN :from AND :to"
            . " ORDER BY reading_time ASC LIMIT {$limit}",
            [':from' => $from, ':to' => $to]
        );
    }

    /**
     * Sommes par champ sur la fenêtre (énergies delta Wh…).
     *
     * @param list<string> $fields
     * @return array<string, float|null>
     */
    public function sums(string $family, array $fields, string $from, string $to): array
    {
        $table = $this->table(FieldCatalog::dataTable($family));
        $valid = $this->validFields($family, $fields, true);
        if ($valid === []) {
            return [];
        }
        $selects = array_map(static fn (string $f): string => "SUM(`{$f}`) AS `{$f}`", $valid);
        $row = $this->fetchOne(
            'SELECT ' . implode(', ', $selects) . " FROM `{$table}` WHERE reading_time BETWEEN :from AND :to",
            [':from' => $from, ':to' => $to]
        ) ?? [];
        $out = [];
        foreach ($valid as $field) {
            $out[$field] = isset($row[$field]) && is_numeric($row[$field]) ? (float) $row[$field] : null;
        }

        return $out;
    }

    /**
     * Valeur au début et à la fin de la fenêtre (première / dernière ligne non nulle).
     *
     * @return array{first: float|null, last: float|null}
     */
    public function firstAndLast(string $family, string $field, string $from, string $to): array
    {
        $table = $this->table(FieldCatalog::dataTable($family));
        if (!in_array($field, FieldCatalog::numericFields($family), true)) {
            return ['first' => null, 'last' => null];
        }
        $base = "SELECT `{$field}` AS v FROM `{$table}` WHERE reading_time BETWEEN :from AND :to AND `{$field}` IS NOT NULL";
        $first = $this->fetchScalar($base . ' ORDER BY reading_time ASC LIMIT 1', [':from' => $from, ':to' => $to]);
        $last = $this->fetchScalar($base . ' ORDER BY reading_time DESC LIMIT 1', [':from' => $from, ':to' => $to]);

        return [
            'first' => is_numeric($first) ? (float) $first : null,
            'last' => is_numeric($last) ? (float) $last : null,
        ];
    }

    /**
     * Dernière valeur NON NULLE d'un champ numérique depuis `$since` (recherche bornée dans le
     * temps : parcours descendant de l'index `reading_time`, jamais toute la table).
     *
     * @return array{value: mixed, reading_time: string}|null
     */
    public function lastNonNull(string $family, string $field, string $since): ?array
    {
        $table = $this->table(FieldCatalog::dataTable($family));
        if (!in_array($field, FieldCatalog::numericFields($family), true)) {
            return null;
        }
        $row = $this->fetchOne(
            "SELECT `{$field}` AS v, `reading_time` FROM `{$table}` WHERE reading_time >= :since AND `{$field}` IS NOT NULL"
            . ' ORDER BY reading_time DESC LIMIT 1',
            [':since' => $since]
        );

        return $row === null ? null : ['value' => $row['v'], 'reading_time' => (string) $row['reading_time']];
    }

    /**
     * Lignes reçues strictement après `$after` : nombre et date de la première.
     *
     * @return array{count: int, first: string|null}
     */
    public function rowsAfter(string $family, string $after): array
    {
        $table = $this->table(FieldCatalog::dataTable($family));
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS n, MIN(reading_time) AS first FROM `{$table}` WHERE reading_time > :after",
            [':after' => $after]
        ) ?? [];

        return [
            'count' => (int) ($row['n'] ?? 0),
            'first' => isset($row['first']) ? (string) $row['first'] : null,
        ];
    }

    /**
     * Lignes les plus RÉCENTES d'abord (colonnes choisies), plafonnées : pour analyser la fin
     * d'une fenêtre (valeur figée, série de NULL) sans tronquer les mesures récentes.
     *
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public function recentRows(string $family, array $fields, string $from, string $to, int $limit = self::MAX_RAW_ROWS): array
    {
        $table = $this->table(FieldCatalog::dataTable($family));
        $columns = $this->columnList($family, $fields);
        $limit = max(1, min(self::MAX_RAW_ROWS, $limit));

        return $this->fetchAll(
            "SELECT {$columns}, `reading_time` FROM `{$table}` WHERE reading_time BETWEEN :from AND :to"
            . " ORDER BY reading_time DESC LIMIT {$limit}",
            [':from' => $from, ':to' => $to]
        );
    }

    public function countBetween(string $family, string $from, string $to): int
    {
        $table = $this->table(FieldCatalog::dataTable($family));

        return (int) $this->fetchScalar(
            "SELECT COUNT(*) FROM `{$table}` WHERE reading_time BETWEEN :from AND :to",
            [':from' => $from, ':to' => $to]
        );
    }

    /**
     * Événements de marée signalés par le firmware (tideEvent non vide), plafonnés.
     *
     * @return list<array<string, mixed>>
     */
    public function tideEvents(string $from, string $to, int $limit = 200): array
    {
        $table = $this->table(FieldCatalog::dataTable('ffp3'));
        $limit = max(1, min(500, $limit));

        return $this->fetchAll(
            "SELECT tideEvent, EauAquarium, diffMaree, reading_time FROM `{$table}`"
            . " WHERE reading_time BETWEEN :from AND :to AND tideEvent IS NOT NULL AND tideEvent <> ''"
            . " ORDER BY reading_time DESC LIMIT {$limit}",
            [':from' => $from, ':to' => $to]
        );
    }

    /**
     * Dernier heartbeat (sans champ sensible), ou null si table vide/absente.
     *
     * @return array<string, mixed>|null
     */
    public function lastHeartbeat(string $family): ?array
    {
        $tableName = FieldCatalog::heartbeatTable($family);
        if ($tableName === null) {
            return null;
        }
        $table = $this->table($tableName);
        try {
            return $this->fetchOne("SELECT * FROM `{$table}` ORDER BY reading_time DESC LIMIT 1");
        } catch (\PDOException) {
            return null;
        }
    }

    /**
     * Sorties GPIO (hors données personnelles) de la famille pour l'environnement courant.
     *
     * @return list<array<string, mixed>>
     */
    public function outputs(string $family): array
    {
        $table = $this->table(FieldCatalog::outputsTable($family));
        $hasAudit = $family === 'ffp3';
        $columns = $hasAudit
            ? 'id, board, gpio, name, state, requestTime, lastModifiedBy'
            : 'id, board, gpio, name, state, requestTime';
        $where = '';
        $params = [];
        if (isset(FieldCatalog::BOARDS[$family])) {
            $where = ' WHERE board = :board';
            $params[':board'] = FieldCatalog::BOARDS[$family];
        }

        return $this->fetchAll("SELECT {$columns} FROM `{$table}`{$where} ORDER BY gpio ASC LIMIT 300", $params);
    }

    /**
     * Une sortie GPIO précise.
     *
     * @return array<string, mixed>|null
     */
    public function output(string $family, int $gpio): ?array
    {
        $table = $this->table(FieldCatalog::outputsTable($family));
        $columns = $family === 'ffp3'
            ? 'id, board, gpio, name, state, requestTime, lastModifiedBy'
            : 'id, board, gpio, name, state, requestTime';
        $sql = "SELECT {$columns} FROM `{$table}` WHERE gpio = :gpio";
        $params = [':gpio' => $gpio];
        if (isset(FieldCatalog::BOARDS[$family])) {
            $sql .= ' AND board = :board';
            $params[':board'] = FieldCatalog::BOARDS[$family];
        } else {
            $sql .= " AND name IS NOT NULL AND name <> ''";
        }

        return $this->fetchOne($sql . ' ORDER BY id ASC LIMIT 1', $params);
    }

    public function boardLastRequest(string $board): ?string
    {
        $value = $this->fetchScalar('SELECT last_request FROM Boards WHERE board = :board LIMIT 1', [':board' => $board]);

        return $value !== null ? (string) $value : null;
    }

    /**
     * Erreurs serveur agrégées (table error_alerts), plafonnées.
     *
     * @return list<array<string, mixed>>
     */
    public function errorAlerts(string $from, string $to, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        try {
            return $this->fetchAll(
                'SELECT normalized_message, COUNT(*) AS occurrences, MIN(created_at) AS first_seen,'
                . ' MAX(created_at) AS last_seen, MAX(alerted_at) AS last_alerted'
                . ' FROM error_alerts WHERE created_at BETWEEN :from AND :to'
                . " GROUP BY normalized_message ORDER BY last_seen DESC LIMIT {$limit}",
                [':from' => $from, ':to' => $to]
            );
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Journal des notifications envoyées (sans destinataire), plafonné.
     *
     * @return list<array<string, mixed>>
     */
    public function notificationLog(string $from, string $to, ?string $keyPrefix, ?string $severity, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT alert_key, severity, category, subject, sent_at FROM notification_log'
            . ' WHERE sent_at BETWEEN :from AND :to';
        $params = [':from' => $from, ':to' => $to];
        if ($keyPrefix !== null) {
            $sql .= ' AND alert_key LIKE :prefix';
            $params[':prefix'] = $keyPrefix . ':%';
        }
        if ($severity !== null) {
            $sql .= ' AND severity = :severity';
            $params[':severity'] = $severity;
        }
        try {
            return $this->fetchAll($sql . " ORDER BY sent_at DESC LIMIT {$limit}", $params);
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Statistiques PGL (Poissonglouton) : total, aujourd'hui, par jour / par heure.
     *
     * @return array<string, mixed>
     */
    public function pglStats(int $days, int $hours): array
    {
        try {
            $total = (int) $this->fetchScalar('SELECT COALESCE(SUM(count_delta), 0) FROM pglEvents');
            $today = (int) $this->fetchScalar(
                'SELECT COALESCE(SUM(count_delta), 0) FROM pglEvents WHERE event_time >= :start',
                [':start' => date('Y-m-d 00:00:00')]
            );
            $daily = $this->fetchAll(
                'SELECT DATE(event_time) AS day, SUM(count_delta) AS total FROM pglEvents'
                . ' WHERE event_time >= :since GROUP BY DATE(event_time) ORDER BY day ASC LIMIT 400',
                [':since' => date('Y-m-d 00:00:00', time() - $days * 86400)]
            );
            $hourly = $this->fetchAll(
                'SELECT ' . $this->bucketExpression(3600, 'event_time') . ' AS bucket_ts, SUM(count_delta) AS total'
                . ' FROM pglEvents WHERE event_time >= :since GROUP BY bucket_ts ORDER BY bucket_ts ASC LIMIT 500',
                [':since' => date('Y-m-d H:i:s', time() - $hours * 3600)]
            );
            $latest = $this->fetchOne(
                'SELECT event_time, count_delta, sensor_mode, battery_v, rssi, fw_version FROM pglEvents'
                . ' ORDER BY event_time DESC LIMIT 1'
            );
            $lastHeartbeat = $this->fetchScalar('SELECT MAX(reading_time) FROM pglHeartbeat');
        } catch (\PDOException $e) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'total' => $total,
            'today' => $today,
            'daily' => $daily,
            'hourly' => $hourly,
            'latest_event' => $latest,
            'last_heartbeat' => $lastHeartbeat !== null ? (string) $lastHeartbeat : null,
        ];
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Horodatage « mur » (secondes écoulées depuis {@see BUCKET_EPOCH}, sans fuseau) du
     * début d'un bucket d'agrégation. `reading_time` est un DATETIME en heure locale :
     * calculer sur l'heure murale évite tout décalage lié au fuseau de session (l'offset
     * fixé par Database à la connexion ne suit pas les changements d'heure passés).
     * Portable MySQL / MariaDB / SQLite (tests). Convertir avec {@see bucketToDate()}.
     */
    public const BUCKET_EPOCH = '2000-01-01 00:00:00';

    private const BUCKET_EPOCH_UNIX = 946684800;

    private function bucketExpression(int $seconds, string $column = 'reading_time'): string
    {
        $seconds = max(60, $seconds);

        return $this->driver() === 'sqlite'
            ? "((CAST(strftime('%s', {$column}) AS INTEGER) - " . self::BUCKET_EPOCH_UNIX . ") / {$seconds}) * {$seconds}"
            : "FLOOR(TIMESTAMPDIFF(SECOND, '" . self::BUCKET_EPOCH . "', {$column}) / {$seconds}) * {$seconds}";
    }

    /**
     * Convertit un bucket (secondes murales) en date locale `Y-m-d H:i:s`.
     */
    public static function bucketToDate(int|float|string $bucket): string
    {
        return gmdate('Y-m-d H:i:s', self::BUCKET_EPOCH_UNIX + (int) $bucket);
    }

    private function stddevExpression(string $field): string
    {
        return $this->driver() === 'sqlite'
            ? 'NULL'
            : "STDDEV_POP(`{$field}`)";
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function validFields(string $family, array $fields, bool $numericOnly): array
    {
        $allowed = $numericOnly ? FieldCatalog::numericFields($family) : array_keys(FieldCatalog::fields($family));

        return array_values(array_intersect($fields, $allowed));
    }

    /**
     * @param list<string> $fields
     */
    private function columnList(string $family, array $fields): string
    {
        $valid = $this->validFields($family, $fields, false);
        if ($valid === []) {
            throw new \InvalidArgumentException('Aucun champ valide pour ' . $family);
        }

        return implode(', ', array_map(static fn (string $f): string => '`' . $f . '`', $valid));
    }

    private function table(string $name): string
    {
        if (!in_array($name, self::ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException('Table non autorisée : ' . $name);
        }

        return $name;
    }
}
