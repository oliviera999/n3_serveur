<?php

declare(strict_types=1);

namespace App\Mcp\Sql;

/**
 * Analyse PURE d'un fichier de migration SQL du dépôt (migrations/*.sql) pour iot_db_schema /
 * iot_migrate : découpe en instructions (commentaires retirés, « ; » hors chaînes) et reconnaît les
 * instructions ADDITIVES, seules applicables par le connecteur :
 *  - ALTER TABLE t ADD [COLUMN] [IF NOT EXISTS] c …[, ADD …] (colonnes et index uniquement) ;
 *  - CREATE TABLE [IF NOT EXISTS] t (…) ;
 *  - CREATE [UNIQUE] INDEX [IF NOT EXISTS] i ON t (…).
 * Tout le reste (DROP, MODIFY, CHANGE, RENAME, UPDATE, DELETE, INSERT, GRANT…) est « other » : la
 * migration est alors listée mais jamais appliquée via MCP (phpMyAdmin reste la voie).
 */
final class MigrationFile
{
    public const KIND_ADD = 'alter_add';
    public const KIND_CREATE_TABLE = 'create_table';
    public const KIND_CREATE_INDEX = 'create_index';
    public const KIND_OTHER = 'other';

    private const IDENT = '`?([A-Za-z0-9_]{1,64})`?';

    /**
     * @return list<array{sql: string, kind: string, table: string|null, columns: list<string>, indexes: list<string>}>
     */
    public static function parse(string $content): array
    {
        $out = [];
        foreach (self::split(self::stripComments($content)) as $sql) {
            $out[] = self::analyse($sql);
        }

        return $out;
    }

    /**
     * @param list<array{kind: string}> $statements
     */
    public static function isAdditiveOnly(array $statements): bool
    {
        if ($statements === []) {
            return false;
        }
        foreach ($statements as $s) {
            if ($s['kind'] === self::KIND_OTHER) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{sql: string, kind: string, table: string|null, columns: list<string>, indexes: list<string>}
     */
    private static function analyse(string $sql): array
    {
        $other = ['sql' => $sql, 'kind' => self::KIND_OTHER, 'table' => null, 'columns' => [], 'indexes' => []];
        $i = self::IDENT;

        if (preg_match('/^ALTER\s+TABLE\s+' . $i . '\s+(.+)$/is', $sql, $m) === 1) {
            $columns = [];
            $indexes = [];
            foreach (self::splitTopLevel($m[2]) as $clause) {
                if (preg_match('/^ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $i . '\s*\(/is', $clause, $c) === 1) {
                    $indexes[] = $c[1];
                } elseif (preg_match('/^ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?' . $i . '\s+\S/is', $clause, $c) === 1
                    && !in_array(strtoupper($c[1]), ['CONSTRAINT', 'PRIMARY', 'FOREIGN', 'FULLTEXT', 'SPATIAL', 'CHECK', 'PARTITION'], true)) {
                    $columns[] = $c[1];
                } else {
                    return $other;
                }
            }

            return ['sql' => $sql, 'kind' => self::KIND_ADD, 'table' => $m[1], 'columns' => $columns, 'indexes' => $indexes];
        }
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $i . '\s*(?:\(|LIKE\b)/is', $sql, $m) === 1) {
            return ['sql' => $sql, 'kind' => self::KIND_CREATE_TABLE, 'table' => $m[1], 'columns' => [], 'indexes' => []];
        }
        if (preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $i . '\s+ON\s+' . $i . '\s*\(/is', $sql, $m) === 1) {
            return ['sql' => $sql, 'kind' => self::KIND_CREATE_INDEX, 'table' => $m[2], 'columns' => [], 'indexes' => [$m[1]]];
        }

        return $other;
    }

    /**
     * Retire les commentaires `-- …`, `# …` et bloc, en respectant les chaînes et identifiants quotés.
     */
    private static function stripComments(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $quote = null;
        for ($p = 0; $p < $len; $p++) {
            $ch = $sql[$p];
            if ($quote !== null) {
                $out .= $ch;
                if ($ch === '\\' && $quote !== '`' && $p + 1 < $len) {
                    $out .= $sql[++$p];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $out .= $ch;
                continue;
            }
            $next = $p + 1 < $len ? $sql[$p + 1] : '';
            if (($ch === '-' && $next === '-') || $ch === '#') {
                while ($p < $len && $sql[$p] !== "\n") {
                    $p++;
                }
                $out .= "\n";
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $p + 2);
                $p = $end === false ? $len : $end + 1;
                $out .= ' ';
                continue;
            }
            $out .= $ch;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function split(string $sql): array
    {
        $parts = [];
        $buf = '';
        $quote = null;
        $len = strlen($sql);
        for ($p = 0; $p < $len; $p++) {
            $ch = $sql[$p];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === '\\' && $quote !== '`' && $p + 1 < $len) {
                    $buf .= $sql[++$p];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === ';') {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        $parts[] = $buf;

        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) preg_replace('/\s+/', ' ', $part));
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * Découpe sur les virgules de premier niveau (hors parenthèses et chaînes).
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $s): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($s);
        for ($p = 0; $p < $len; $p++) {
            $ch = $s[$p];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $parts[] = trim($buf);
        }

        return $parts;
    }
}
