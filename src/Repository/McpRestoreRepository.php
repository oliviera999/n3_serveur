<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Rétablissements automatiques planifiés (pompe aquarium après arrêt MCP borné).
 */
class McpRestoreRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function schedule(
        string $family,
        string $env,
        string $targetKey,
        int $gpio,
        string $restoreValue,
        int $restoreAtTimestamp,
        int $createdBy,
        ?string $reason,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mcp_scheduled_restores (family, env, target_key, gpio, restore_value, restore_at,'
            . ' created_by, reason, status, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?)'
        );
        $stmt->execute([
            $family,
            $env,
            $targetKey,
            $gpio,
            $restoreValue,
            date('Y-m-d H:i:s', $restoreAtTimestamp),
            $createdBy,
            $reason,
            self::now(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function duePending(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mcp_scheduled_restores WHERE status = \'pending\' AND restore_at <= ? ORDER BY restore_at ASC'
        );
        $stmt->execute([self::now()]);

        return $stmt->fetchAll() ?: [];
    }

    public function markDone(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE mcp_scheduled_restores SET status = \'done\', done_at = ? WHERE id = ?'
        )->execute([self::now(), $id]);
    }

    public function markFailed(int $id, string $message): void
    {
        $this->pdo->prepare(
            'UPDATE mcp_scheduled_restores SET status = \'failed\', done_at = ?, error_message = ? WHERE id = ?'
        )->execute([self::now(), mb_substr($message, 0, 500), $id]);
    }

    public function cancelPendingForTarget(string $targetKey, ?int $exceptId = null): int
    {
        $sql = 'UPDATE mcp_scheduled_restores SET status = \'cancelled\', done_at = ?'
            . ' WHERE target_key = ? AND status = \'pending\'';
        $params = [self::now(), $targetKey];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPendingForTarget(string $targetKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mcp_scheduled_restores WHERE target_key = ? AND status = \'pending\' ORDER BY restore_at ASC LIMIT 1'
        );
        $stmt->execute([$targetKey]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function purgeOld(int $retentionDays = 30): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM mcp_scheduled_restores WHERE status <> \'pending\' AND created_at < ?'
        );
        $stmt->execute([date('Y-m-d H:i:s', time() - ($retentionDays * 86400))]);

        return $stmt->rowCount();
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
