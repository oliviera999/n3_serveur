<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Stockage des confirmations par code et des fenêtres de déverrouillage MCP.
 */
class McpConfirmRepository
{
    public const CODE_TTL_SECONDS = 600;
    public const MAX_ATTEMPTS = 3;
    public const MAX_ISSUES_PER_HOUR = 10;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<string>             $categories
     * @param array<string, mixed>     $summary
     */
    public function createConfirmation(
        string $confirmationId,
        int $userId,
        string $clientId,
        string $tool,
        string $argsFingerprint,
        array $categories,
        array $summary,
        string $codeHmac,
        int $ttlSeconds = self::CODE_TTL_SECONDS,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mcp_confirmations (confirmation_id, user_id, client_id, tool, args_fingerprint, categories,'
            . ' summary_json, code_hmac, attempts, expires_at, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
        );
        $now = self::now();
        $stmt->execute([
            $confirmationId,
            $userId,
            $clientId,
            $tool,
            $argsFingerprint,
            implode(',', $categories),
            (string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $codeHmac,
            date('Y-m-d H:i:s', time() + $ttlSeconds),
            $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findConfirmation(string $confirmationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mcp_confirmations WHERE confirmation_id = ?');
        $stmt->execute([$confirmationId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function countRecentIssues(int $userId, int $sinceTimestamp): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM mcp_confirmations WHERE user_id = ? AND created_at >= ?'
        );
        $stmt->execute([$userId, date('Y-m-d H:i:s', $sinceTimestamp)]);

        return (int) $stmt->fetchColumn();
    }

    public function incrementAttempts(string $confirmationId): int
    {
        $this->pdo->prepare('UPDATE mcp_confirmations SET attempts = attempts + 1 WHERE confirmation_id = ?')
            ->execute([$confirmationId]);
        $stmt = $this->pdo->prepare('SELECT attempts FROM mcp_confirmations WHERE confirmation_id = ?');
        $stmt->execute([$confirmationId]);

        return (int) $stmt->fetchColumn();
    }

    public function consumeConfirmation(string $confirmationId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mcp_confirmations SET consumed_at = ? WHERE confirmation_id = ? AND consumed_at IS NULL'
        );
        $stmt->execute([self::now(), $confirmationId]);

        return $stmt->rowCount() > 0;
    }

    public function invalidateConfirmation(string $confirmationId): void
    {
        $this->pdo->prepare(
            'UPDATE mcp_confirmations SET consumed_at = COALESCE(consumed_at, ?), attempts = ? WHERE confirmation_id = ?'
        )->execute([self::now(), self::MAX_ATTEMPTS, $confirmationId]);
    }

    public function createUnlock(int $userId, string $clientId, int $minutes): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mcp_confirm_unlocks (user_id, client_id, expires_at, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $clientId,
            date('Y-m-d H:i:s', time() + ($minutes * 60)),
            self::now(),
        ]);
    }

    public function hasActiveUnlock(int $userId, string $clientId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM mcp_confirm_unlocks WHERE user_id = ? AND client_id = ? AND expires_at > ? LIMIT 1'
        );
        $stmt->execute([$userId, $clientId, self::now()]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array{confirmations: int, unlocks: int}
     */
    public function purgeExpired(): array
    {
        $now = self::now();
        $c = $this->pdo->prepare(
            'DELETE FROM mcp_confirmations WHERE (expires_at < ? AND consumed_at IS NOT NULL)'
            . ' OR (expires_at < ? AND created_at < ?)'
        );
        $c->execute([$now, $now, date('Y-m-d H:i:s', time() - 86400)]);
        $u = $this->pdo->prepare('DELETE FROM mcp_confirm_unlocks WHERE expires_at < ?');
        $u->execute([$now]);

        return ['confirmations' => $c->rowCount(), 'unlocks' => $u->rowCount()];
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
