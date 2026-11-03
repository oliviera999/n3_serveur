<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Lectures et stockage du rapport de santé du connecteur MCP.
 *
 *  - stockage / historique / purge : table `mcp_health_reports` (migrations/2026_11_mcp_exploitation.sql) ;
 *  - comptages d'erreurs (`error_alerts`) et de notifications (`notification_log`) ;
 *  - taille des tables (information_schema, MariaDB/MySQL seulement ; estimation InnoDB).
 *
 * Les noms de tables de mesures sont fournis par l'appelant depuis {@see \App\Config\TableConfig}
 * et passés en paramètres liés (jamais interpolés). Toutes les méthodes de lecture tolèrent une
 * table absente (migration non jouée) et renvoient alors une valeur neutre.
 */
class McpHealthRepository extends AbstractRepository
{
    /**
     * @param array<string, mixed> $report
     * @param list<array<string, string>> $reasons
     */
    public function store(string $generatedAt, string $status, string $summary, array $reasons, array $report, string $trigger, bool $emailed): int
    {
        $this->execute(
            'INSERT INTO mcp_health_reports (generated_at, status, summary, reasons, report, trigger_source, emailed)'
            . ' VALUES (:at, :status, :summary, :reasons, :report, :trigger, :emailed)',
            [
                ':at' => $generatedAt,
                ':status' => $status,
                ':summary' => mb_substr($summary, 0, 500),
                ':reasons' => self::json($reasons),
                ':report' => self::json($report),
                ':trigger' => $trigger,
                ':emailed' => $emailed ? 1 : 0,
            ]
        );

        return (int) $this->lastInsertId();
    }

    public function markEmailed(int $id): void
    {
        $this->execute('UPDATE mcp_health_reports SET emailed = 1 WHERE id = :id', [':id' => $id]);
    }

    /**
     * N derniers rapports (du plus récent au plus ancien), avec ou sans le rapport complet.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit, bool $withReport = false): array
    {
        $limit = max(1, min(90, $limit));
        $columns = 'id, generated_at, status, summary, reasons, trigger_source, emailed' . ($withReport ? ', report' : '');
        $rows = $this->fetchAll("SELECT {$columns} FROM mcp_health_reports ORDER BY generated_at DESC, id DESC LIMIT {$limit}");

        return array_map(static function (array $row) use ($withReport): array {
            $reasons = json_decode((string) $row['reasons'], true);
            $out = [
                'id' => (int) $row['id'],
                'generated_at' => (string) $row['generated_at'],
                'status' => (string) $row['status'],
                'summary' => (string) $row['summary'],
                'reasons' => is_array($reasons) ? $reasons : [],
                'trigger' => (string) $row['trigger_source'],
                'emailed' => (int) $row['emailed'] === 1,
            ];
            if ($withReport) {
                $report = json_decode((string) $row['report'], true);
                $out['report'] = is_array($report) ? $report : null;
            }

            return $out;
        }, $rows);
    }

    public function purgeOlderThan(string $before): int
    {
        return $this->executeWithRowCount('DELETE FROM mcp_health_reports WHERE generated_at < :before', [':before' => $before]);
    }

    /**
     * Erreurs serveur (`error_alerts`) depuis une date : occurrences et messages distincts.
     *
     * @return array{occurrences: int, distinct: int}|null null si la table est absente
     */
    public function errorCounts(string $since): ?array
    {
        try {
            $row = $this->fetchOne(
                'SELECT COUNT(*) AS n, COUNT(DISTINCT normalized_message) AS d FROM error_alerts WHERE created_at >= :since',
                [':since' => $since]
            );
        } catch (\PDOException) {
            return null;
        }

        return ['occurrences' => (int) ($row['n'] ?? 0), 'distinct' => (int) ($row['d'] ?? 0)];
    }

    /**
     * Notifications envoyées (`notification_log`) depuis une date, par gravité.
     *
     * @return array<string, int>|null null si la table est absente
     */
    public function notificationCounts(string $since): ?array
    {
        try {
            $rows = $this->fetchAll(
                'SELECT severity, COUNT(*) AS n FROM notification_log WHERE sent_at >= :since GROUP BY severity',
                [':since' => $since]
            );
        } catch (\PDOException) {
            return null;
        }
        $out = ['critical' => 0, 'alert' => 0, 'info' => 0, 'diagnostic' => 0];
        foreach ($rows as $row) {
            $out[(string) $row['severity']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Taille estimée des tables (MariaDB/MySQL) : lignes (estimation InnoDB) et octets
     * (données + index). Vide sous SQLite ou si information_schema est illisible.
     *
     * @param list<string> $tables
     * @return array<string, array{rows: int, bytes: int}>
     */
    public function tableSizes(array $tables): array
    {
        $tables = array_values(array_unique(array_filter($tables, static fn (string $t): bool => preg_match('/^[A-Za-z0-9_]+$/', $t) === 1)));
        if ($tables === [] || (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($tables as $i => $table) {
            $placeholders[] = ':t' . $i;
            $params[':t' . $i] = $table;
        }
        try {
            $rows = $this->fetchAll(
                'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_estimate, (DATA_LENGTH + INDEX_LENGTH) AS bytes'
                . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(', ', $placeholders) . ')',
                $params
            );
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['name']] = ['rows' => (int) $row['row_estimate'], 'bytes' => (int) $row['bytes']];
        }
        ksort($out);

        return $out;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '{}' : $json;
    }
}
