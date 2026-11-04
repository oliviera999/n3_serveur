<?php

declare(strict_types=1);

namespace App\Mcp\Sql;

use App\Mcp\ToolException;

/**
 * Classe une instruction SQL unique pour iot_sql (lexer maison, pas de dépendance).
 */
final class SqlStatementClassifier
{
    public const CLASS_READ = 'read';
    public const CLASS_DML = 'dml';
    public const CLASS_DDL = 'ddl';
    public const CLASS_ACCOUNT = 'account_admin';
    public const CLASS_FORBIDDEN = 'forbidden';

    /** @var list<string> */
    private const SECRET_COLUMNS = [
        'password_hash', 'token_hash', 'code_hash', 'credential_epoch', 'code_hmac',
        'api_key', 'api_sig_secret', 'admin_token',
    ];

    /** @var list<string> */
    private const PROTECTED_WRITE_TABLES = [
        'mcp_confirmations', 'mcp_confirm_unlocks', 'mcp_control_plans', 'mcp_scheduled_restores',
        'mcp_oauth_tokens', 'mcp_oauth_codes',
    ];

    /**
     * @param list<string> $productionOutputTables tables outputs de production (résolues via TableConfig)
     * @return array{
     *   class: string,
     *   keywords: list<string>,
     *   tables: list<string>,
     *   has_where: bool,
     *   touches_prod_outputs: bool,
     *   unbounded_write: bool,
     *   needs_confirm: list<string>,
     *   sql: string
     * }
     */
    public static function classify(string $sql, array $productionOutputTables = []): array
    {
        $stripped = self::stripCommentsAndNormalize($sql);
        if ($stripped === '') {
            throw new ToolException('Instruction SQL vide.', 'sql_empty');
        }
        if (str_contains($stripped, ';')) {
            throw new ToolException('Une seule instruction autorisée (pas de « ; » suivi d\'autre chose).', 'sql_multi');
        }

        $upper = strtoupper($stripped);
        self::assertNotForbidden($upper, $stripped);

        $tokens = self::tokenize($stripped);
        if ($tokens === []) {
            throw new ToolException('Instruction SQL vide.', 'sql_empty');
        }

        $first = strtoupper($tokens[0]);
        $second = isset($tokens[1]) ? strtoupper($tokens[1]) : '';

        $class = self::CLASS_FORBIDDEN;
        if (in_array($first, ['SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
            $class = self::CLASS_READ;
        } elseif (in_array($first, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)) {
            $class = self::CLASS_DML;
        } elseif (in_array($first, ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'], true)) {
            if (in_array($second, ['USER', 'ROLE'], true)
                || ($first === 'CREATE' && $second === 'USER')
                || ($first === 'DROP' && $second === 'USER')
                || ($first === 'ALTER' && $second === 'USER')
                || ($first === 'RENAME' && $second === 'USER')) {
                $class = self::CLASS_ACCOUNT;
            } else {
                $class = self::CLASS_DDL;
            }
        } elseif (in_array($first, ['GRANT', 'REVOKE'], true)) {
            $class = self::CLASS_ACCOUNT;
        } elseif ($first === 'SET' && $second === 'PASSWORD') {
            $class = self::CLASS_ACCOUNT;
        } else {
            throw new ToolException('Instruction non autorisée : ' . $first, 'sql_forbidden');
        }

        foreach (self::SECRET_COLUMNS as $col) {
            if (preg_match('/\b' . preg_quote($col, '/') . '\b/i', $stripped) === 1) {
                throw new ToolException('Colonne secrète interdite dans iot_sql : ' . $col, 'sql_secret_column');
            }
        }

        $tables = self::extractTables($stripped);
        foreach ($tables as $table) {
            if (in_array(strtolower($table), self::PROTECTED_WRITE_TABLES, true) && $class !== self::CLASS_READ) {
                throw new ToolException(
                    'Écriture interdite dans la table protégée ' . $table . '.',
                    'sql_protected_table'
                );
            }
        }

        // WHERE dans une chaîne ('…WHERE…') ne filtre pas : sinon un UPDATE sans clause
        // échappe à la confirmation sql_unbounded_write.
        $hasWhere = (bool) preg_match('/\bWHERE\b/i', self::maskLiterals($stripped));
        $unbounded = $class === self::CLASS_DML
            && in_array($first, ['UPDATE', 'DELETE'], true)
            && !$hasWhere;

        $prodLower = array_map('strtolower', $productionOutputTables);
        $touchesProd = false;
        if ($class !== self::CLASS_READ) {
            foreach ($tables as $table) {
                if (in_array(strtolower($table), $prodLower, true)) {
                    $touchesProd = true;
                    break;
                }
            }
        }

        $needs = [];
        if ($class === self::CLASS_DDL) {
            $needs[] = 'sql_ddl';
        }
        if ($class === self::CLASS_ACCOUNT) {
            $needs[] = 'sql_account_admin';
        }
        if ($unbounded) {
            $needs[] = 'sql_unbounded_write';
        }
        if ($touchesProd) {
            $needs[] = 'sql_prod_outputs';
        }

        return [
            'class' => $class,
            'keywords' => [$first, $second],
            'tables' => $tables,
            'has_where' => $hasWhere,
            'touches_prod_outputs' => $touchesProd,
            'unbounded_write' => $unbounded,
            'needs_confirm' => $needs,
            'sql' => $stripped,
        ];
    }

    private static function assertNotForbidden(string $upper, string $original): void
    {
        $patterns = [
            '/\bLOAD\s+DATA\b/' => 'LOAD DATA',
            '/\bLOAD\s+XML\b/' => 'LOAD XML',
            '/\bINTO\s+(OUT|DUMP)FILE\b/' => 'INTO OUTFILE/DUMPFILE',
            '/\bSHUTDOWN\b/' => 'SHUTDOWN',
            '/\bLOAD_FILE\s*\(/' => 'LOAD_FILE()',
            '/\bPREPARE\b/' => 'PREPARE',
            '/\bEXECUTE\b/' => 'EXECUTE',
            '/\bDEALLOCATE\b/' => 'DEALLOCATE',
            '/\bCALL\b/' => 'CALL',
            '/\bHANDLER\b/' => 'HANDLER',
            '/\b(START|BEGIN|COMMIT|ROLLBACK)\b/' => 'contrôle de transaction',
            '/\b(LOCK|UNLOCK)\s+TABLES\b/' => 'LOCK/UNLOCK TABLES',
            '/\bKILL\b/' => 'KILL',
            '/\bFLUSH\b/' => 'FLUSH',
            '/\bRESET\b/' => 'RESET',
            '/\bINSTALL\b/' => 'INSTALL',
            '/\bUNINSTALL\b/' => 'UNINSTALL',
            '/\bSONAME\b/' => 'SONAME',
            '/\/\*\!/' => 'commentaire exécutable',
        ];
        // SET hors SET PASSWORD
        if (preg_match('/^\s*SET\b/i', $original) === 1 && preg_match('/^\s*SET\s+PASSWORD\b/i', $original) !== 1) {
            throw new ToolException('SET est interdit (sauf SET PASSWORD).', 'sql_forbidden');
        }
        foreach ($patterns as $pattern => $label) {
            if (preg_match($pattern, $upper) === 1) {
                throw new ToolException('Instruction interdite : ' . $label . '.', 'sql_forbidden');
            }
        }
    }

    private static function stripCommentsAndNormalize(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $n = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($c === '-' && $n === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    ++$i;
                }
                continue;
            }
            if ($c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    ++$i;
                }
                continue;
            }
            if ($c === '/' && $n === '*') {
                if ($i + 2 < $len && $sql[$i + 2] === '!') {
                    throw new ToolException('Commentaire exécutable interdit.', 'sql_forbidden');
                }
                $i += 2;
                while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    ++$i;
                }
                $i += 2;
                $out .= ' ';
                continue;
            }
            if ($c === '\'' || $c === '"' || $c === '`') {
                $quote = $c;
                $out .= $c;
                ++$i;
                while ($i < $len) {
                    $out .= $sql[$i];
                    if ($sql[$i] === '\\' && $i + 1 < $len) {
                        $out .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === $quote) {
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $out .= $sql[$i + 1];
                            $i += 2;
                            continue;
                        }
                        ++$i;
                        break;
                    }
                    ++$i;
                }
                continue;
            }
            $out .= $c;
            ++$i;
        }
        $out = trim($out);
        if (str_ends_with($out, ';')) {
            $out = rtrim(substr($out, 0, -1));
        }

        return trim(preg_replace('/\s+/', ' ', $out) ?? $out);
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $sql): array
    {
        preg_match_all('/`[^`]+`|[A-Za-z_][A-Za-z0-9_]*|\S/', $sql, $m);

        /** @var list<string> $tokens */
        $tokens = $m[0];

        return $tokens;
    }

    /** Mots qui introduisent une référence de table. */
    private const TABLE_INTRODUCERS = ['FROM', 'INTO', 'JOIN', 'STRAIGHT_JOIN', 'UPDATE', 'TABLE', 'USING'];

    /** Modificateurs entre le verbe et le nom (UPDATE LOW_PRIORITY IGNORE …). */
    private const TABLE_MODIFIERS = ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'QUICK', 'IGNORE', 'IF', 'NOT', 'EXISTS'];

    /** Fin de liste de tables (ne pas les prendre pour un alias). */
    private const TABLE_STOP = [
        'SET', 'WHERE', 'ON', 'USING', 'VALUES', 'SELECT', 'PARTITION', 'LIMIT', 'ORDER', 'GROUP',
        'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'STRAIGHT_JOIN', 'NATURAL', 'JOIN', 'FROM',
        'INTO', 'UPDATE', 'TABLE', 'HAVING', 'WINDOW', 'UNION',
    ];

    /**
     * Tables réellement visées, y compris `schema.table`, `LOW_PRIORITY` / `IGNORE`
     * et les listes `t1, t2`. Le premier identifiant après UPDATE n'est pas toujours la table.
     *
     * @return list<string>
     */
    private static function extractTables(string $sql): array
    {
        $tokens = self::tokenize($sql);
        $tables = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; ++$i) {
            $word = self::upperBare($tokens[$i]);
            if ($word === null || !in_array($word, self::TABLE_INTRODUCERS, true)) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && in_array(self::upperBare($tokens[$j]), self::TABLE_MODIFIERS, true)) {
                ++$j;
            }
            while ($j < $n) {
                $name = self::bare($tokens[$j]);
                $upper = $name === null ? null : strtoupper($name);
                if ($name === null || in_array($upper, self::TABLE_STOP, true) || in_array($upper, self::TABLE_MODIFIERS, true)) {
                    break;
                }
                ++$j;
                if ($j + 1 < $n && $tokens[$j] === '.') {
                    $qualified = self::bare($tokens[$j + 1]);
                    if ($qualified !== null) {
                        $name = $qualified;
                        $j += 2;
                    }
                }
                $tables[] = $name;
                $next = $j < $n ? self::upperBare($tokens[$j]) : null;
                if ($next === 'AS') {
                    $j += 2;
                } elseif ($next !== null
                    && !in_array($next, self::TABLE_STOP, true)
                    && !in_array($next, self::TABLE_MODIFIERS, true)
                    && !in_array($next, self::TABLE_INTRODUCERS, true)
                    && ($j + 1 >= $n || $tokens[$j + 1] !== '.')
                ) {
                    ++$j;
                }
                if ($j < $n && $tokens[$j] === ',') {
                    ++$j;
                    continue;
                }
                break;
            }
        }

        return array_values(array_unique($tables));
    }

    private static function bare(string $token): ?string
    {
        if (preg_match('/^`([A-Za-z_][A-Za-z0-9_]*)`$/', $token, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $token) === 1) {
            return $token;
        }

        return null;
    }

    private static function upperBare(string $token): ?string
    {
        $bare = self::bare($token);

        return $bare === null ? null : strtoupper($bare);
    }

    /**
     * Retire le contenu des chaînes et identifiants quotés pour les tests de mots-clés.
     */
    private static function maskLiterals(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === '\'' || $c === '"' || $c === '`') {
                $quote = $c;
                $out .= ' ';
                ++$i;
                while ($i < $len) {
                    if ($sql[$i] === '\\' && $i + 1 < $len) {
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === $quote) {
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $i += 2;
                            continue;
                        }
                        ++$i;
                        break;
                    }
                    ++$i;
                }
                $out .= ' ';
                continue;
            }
            $out .= $c;
            ++$i;
        }

        return $out;
    }
}
