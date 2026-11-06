<?php

declare(strict_types=1);

namespace App\Mcp\Sql;

use App\Mcp\Support\SecretScanner;
use App\Mcp\ToolException;
use PDO;

/**
 * Exécution d'une LECTURE SQL pour le connecteur (iot_sql, iot_sql_select) : délai d'instruction
 * borné, lignes plafonnées, chaînes tronquées, secrets reconnaissables masqués (SecretScanner) et
 * colonnes au nom sensible (password, secret, token, hash, hmac, credential) remplacées.
 *
 * Extrait de SqlTool (6.58.0) pour être partagé sans dupliquer les garde-fous.
 */
final class SqlReader
{
    public const MAX_CELL_LENGTH = 500;

    public function __construct(private PDO $readPdo, private int $maxStatementSeconds)
    {
    }

    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool}
     */
    public function select(string $sql, int $maxRows, bool $keepEmails): array
    {
        $stmt = $this->readPdo->query(self::withTimeout($this->readPdo, $sql, true, $this->maxStatementSeconds));
        if ($stmt === false) {
            throw new ToolException('Échec de la lecture SQL.', 'sql_failed');
        }
        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); ++$i) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = is_array($meta) ? (string) ($meta['name'] ?? 'col' . $i) : 'col' . $i;
        }
        $rows = [];
        $truncated = false;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (count($rows) >= $maxRows) {
                $truncated = true;
                break;
            }
            $clean = [];
            foreach ($row as $k => $v) {
                if (is_string($v) && strlen($v) > self::MAX_CELL_LENGTH) {
                    $v = substr($v, 0, self::MAX_CELL_LENGTH) . '…';
                }
                if (is_string($v)) {
                    $v = SecretScanner::mask($v, $keepEmails);
                }
                if (preg_match('/(password|secret|token|hash|hmac|credential)/i', (string) $k) === 1) {
                    $v = '[masqué]';
                }
                $clean[(string) $k] = $v;
            }
            $rows[] = $clean;
        }
        $stmt->closeCursor();

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Préfixe de délai maximal (MariaDB `SET STATEMENT max_statement_time`, MySQL hint sur SELECT).
     */
    public static function withTimeout(PDO $pdo, string $sql, bool $isSelect, int $seconds): string
    {
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver !== 'mysql') {
                return $sql;
            }
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (\Throwable) {
            return $sql;
        }
        if (stripos($version, 'MariaDB') !== false) {
            return 'SET STATEMENT max_statement_time=' . $seconds . ' FOR ' . $sql;
        }
        if ($isSelect && preg_match('/^\s*SELECT\b/i', $sql) === 1) {
            return preg_replace('/^\s*SELECT\b/i', 'SELECT /*+ MAX_EXECUTION_TIME(' . ($seconds * 1000) . ') */', $sql, 1) ?? $sql;
        }

        return $sql;
    }
}
