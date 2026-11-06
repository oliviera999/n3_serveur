<?php

declare(strict_types=1);

namespace App\Mcp\Sql;

use App\Mcp\ToolException;

/**
 * Migrations du dépôt (`migrations/*.sql`, premier niveau) confrontées au schéma RÉEL de la base :
 * pour chaque instruction additive, appliquée / en attente / partielle / table absente.
 * Lecture seule ; l'application est faite par {@see \App\Mcp\Tools\MigrateTool}.
 */
final class MigrationCatalog
{
    public const MAX_FILE_BYTES = 262144;

    public const ST_APPLIED = 'applied';
    public const ST_PENDING = 'pending';
    public const ST_PARTIAL = 'partial';
    public const ST_TABLE_MISSING = 'table_missing';
    public const ST_UNVERIFIABLE = 'unverifiable';

    public function __construct(private string $directory, private SchemaReader $schema)
    {
    }

    /**
     * @return list<string> Noms de fichiers *.sql (tri alphabétique)
     */
    public function files(): array
    {
        $real = realpath($this->directory);
        if ($real === false || !is_dir($real)) {
            return [];
        }
        $out = [];
        foreach (scandir($real) ?: [] as $name) {
            if (self::isValidName($name) && is_file($real . '/' . $name)) {
                $out[] = $name;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,120}\.sql$/', $name) === 1 && !str_contains($name, '..');
    }

    /**
     * @return array{file: string, status: string, additive_only: bool, statements: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function inspect(string $file): array
    {
        $statements = MigrationFile::parse($this->read($file));
        $additive = MigrationFile::isAdditiveOnly($statements);
        $out = [];
        $counts = [];
        foreach ($statements as $s) {
            [$status, $missing] = $this->statementStatus($s);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $out[] = [
                'kind' => $s['kind'],
                'table' => $s['table'],
                'status' => $status,
                'missing' => $missing,
                'idempotent' => (bool) preg_match('/\bIF\s+NOT\s+EXISTS\b/i', $s['sql']),
                'sql' => mb_substr($s['sql'], 0, 600),
            ];
        }

        return [
            'file' => $file,
            'status' => self::overall($counts, $additive),
            'additive_only' => $additive,
            'statements' => $out,
            'counts' => $counts,
        ];
    }

    /**
     * Instructions brutes (non tronquées) d'un fichier, pour exécution.
     *
     * @return list<array{sql: string, kind: string, table: string|null, columns: list<string>, indexes: list<string>}>
     */
    public function statements(string $file): array
    {
        return MigrationFile::parse($this->read($file));
    }

    /**
     * @param array{sql: string, kind: string, table: string|null, columns: list<string>, indexes: list<string>} $s
     * @return array{0: string, 1: list<string>} statut + éléments manquants
     */
    public function statementStatus(array $s): array
    {
        if ($s['kind'] === MigrationFile::KIND_OTHER || $s['table'] === null) {
            return [self::ST_UNVERIFIABLE, []];
        }
        $table = $s['table'];
        $exists = $this->schema->tableExists($table);
        if ($s['kind'] === MigrationFile::KIND_CREATE_TABLE) {
            return $exists ? [self::ST_APPLIED, []] : [self::ST_PENDING, [$table]];
        }
        if (!$exists) {
            return [self::ST_TABLE_MISSING, [$table]];
        }
        $indexNames = array_map(static fn (array $i): string => strtolower($i['name']), $this->schema->indexes($table));
        $missing = [];
        $expected = 0;
        foreach ($s['columns'] as $c) {
            $expected++;
            if (!$this->schema->columnExists($table, $c)) {
                $missing[] = $c;
            }
        }
        foreach ($s['indexes'] as $i) {
            $expected++;
            if (!in_array(strtolower($i), $indexNames, true)) {
                $missing[] = 'index ' . $i;
            }
        }
        if ($missing === []) {
            return [self::ST_APPLIED, []];
        }

        return [count($missing) === $expected ? self::ST_PENDING : self::ST_PARTIAL, $missing];
    }

    /**
     * @param array<string, int> $counts
     */
    private static function overall(array $counts, bool $additive): string
    {
        if (!$additive) {
            return self::ST_UNVERIFIABLE;
        }
        $pending = ($counts[self::ST_PENDING] ?? 0) + ($counts[self::ST_PARTIAL] ?? 0);
        $applied = $counts[self::ST_APPLIED] ?? 0;
        if ($pending === 0) {
            return $applied > 0 ? self::ST_APPLIED : self::ST_TABLE_MISSING;
        }

        return $applied > 0 || ($counts[self::ST_PARTIAL] ?? 0) > 0 ? self::ST_PARTIAL : self::ST_PENDING;
    }

    private function read(string $file): string
    {
        if (!self::isValidName($file) || !in_array($file, $this->files(), true)) {
            throw new ToolException('Migration inconnue : ' . $file . ' (voir iot_db_schema, section migrations).', 'migration_unknown');
        }
        $path = rtrim((string) realpath($this->directory), '/') . '/' . $file;
        if (filesize($path) > self::MAX_FILE_BYTES) {
            throw new ToolException('Fichier de migration trop gros pour le connecteur.', 'migration_too_large');
        }

        return (string) file_get_contents($path);
    }
}
