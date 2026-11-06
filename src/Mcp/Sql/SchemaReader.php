<?php

declare(strict_types=1);

namespace App\Mcp\Sql;

use PDO;

/**
 * Lecture SEULE du schéma de la base courante (MariaDB/MySQL : INFORMATION_SCHEMA ; SQLite : PRAGMA,
 * pour les tests). Métadonnées uniquement — aucune valeur de ligne n'est lue.
 */
final class SchemaReader
{
    /** @var array<string, array<string, true>> */
    private array $columnsCache = [];

    public function __construct(private PDO $pdo)
    {
    }

    public static function isValidIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) === 1;
    }

    /**
     * @return list<array{name: string, engine: string|null, rows_estimate: int|null, size_bytes: int|null}>
     */
    public function tables(): array
    {
        if ($this->isSqlite()) {
            $rows = $this->queryAll("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

            return array_values(array_map(
                static fn (array $r): array => ['name' => (string) $r['name'], 'engine' => null, 'rows_estimate' => null, 'size_bytes' => null],
                $rows
            ));
        }
        $stmt = $this->pdo->query(
            'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS rows_estimate, (DATA_LENGTH + INDEX_LENGTH) AS size_bytes'
            . ' FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        );
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return array_values(array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'engine' => isset($r['engine']) ? (string) $r['engine'] : null,
            'rows_estimate' => isset($r['rows_estimate']) ? (int) $r['rows_estimate'] : null,
            'size_bytes' => isset($r['size_bytes']) ? (int) $r['size_bytes'] : null,
        ], $rows));
    }

    public function tableExists(string $table): bool
    {
        foreach ($this->tables() as $t) {
            if (strcasecmp($t['name'], $table) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, key: string}>
     */
    public function columns(string $table): array
    {
        if (!self::isValidIdentifier($table)) {
            return [];
        }
        if ($this->isSqlite()) {
            $rows = $this->queryAll("PRAGMA table_info(`{$table}`)");

            return array_values(array_map(static fn (array $r): array => [
                'name' => (string) $r['name'],
                'type' => (string) $r['type'],
                'nullable' => (int) $r['notnull'] === 0,
                'default' => isset($r['dflt_value']) ? (string) $r['dflt_value'] : null,
                'key' => (int) $r['pk'] > 0 ? 'PRI' : '',
            ], $rows));
        }
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt, COLUMN_KEY AS ckey'
            . ' FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([':t' => $table]);

        return array_values(array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'type' => (string) $r['type'],
            'nullable' => strtoupper((string) $r['nullable']) === 'YES',
            'default' => isset($r['dflt']) ? (string) $r['dflt'] : null,
            'key' => (string) ($r['ckey'] ?? ''),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return list<array{name: string, columns: list<string>, unique: bool}>
     */
    public function indexes(string $table): array
    {
        if (!self::isValidIdentifier($table)) {
            return [];
        }
        $out = [];
        if ($this->isSqlite()) {
            foreach ($this->queryAll("PRAGMA index_list(`{$table}`)") as $idx) {
                $name = (string) $idx['name'];
                $cols = array_map(
                    static fn (array $c): string => (string) $c['name'],
                    $this->queryAll('PRAGMA index_info(`' . str_replace('`', '', $name) . '`)')
                );
                $out[] = ['name' => $name, 'columns' => array_values($cols), 'unique' => (int) $idx['unique'] === 1];
            }

            return $out;
        }
        $stmt = $this->pdo->prepare(
            'SELECT INDEX_NAME AS name, COLUMN_NAME AS col, NON_UNIQUE AS non_unique FROM INFORMATION_SCHEMA.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $stmt->execute([':t' => $table]);
        $byName = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = (string) $r['name'];
            $byName[$name] ??= ['name' => $name, 'columns' => [], 'unique' => (int) $r['non_unique'] === 0];
            $byName[$name]['columns'][] = (string) $r['col'];
        }

        return array_values($byName);
    }

    public function columnExists(string $table, string $column): bool
    {
        if (!isset($this->columnsCache[$table])) {
            $this->columnsCache[$table] = [];
            foreach ($this->columns($table) as $c) {
                $this->columnsCache[$table][strtolower($c['name'])] = true;
            }
        }

        return isset($this->columnsCache[$table][strtolower($column)]);
    }

    public function forget(): void
    {
        $this->columnsCache = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryAll(string $sql): array
    {
        $stmt = $this->pdo->query($sql);

        return $stmt === false ? [] : array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function isSqlite(): bool
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }
}
