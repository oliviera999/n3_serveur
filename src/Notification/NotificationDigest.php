<?php

declare(strict_types=1);

namespace App\Notification;

use App\Config\Database;
use App\Service\LogService;
use PDO;

/**
 * File d'attente de synthèse (digest) pour les notifications de faible sévérité (P3/P4).
 *
 * Plutôt qu'un e-mail par alerte mineure, celles-ci sont accumulées dans la table
 * `notification_digest`, puis regroupées en UN SEUL e-mail lors d'un flush (tick CRON
 * ou fin de run). Les alertes critiques (P1/P2) restent envoyées immédiatement par
 * NotificationService et ne passent jamais par cette file.
 *
 * Robustesse : comme AlertThrottler, la persistance « échoue silencieusement » en cas
 * d'indisponibilité de la base — une synthèse manquée est préférable à un plantage du
 * CRON. Les lignes flushées sont supprimées dans la même transaction logique.
 */
final class NotificationDigest implements DigestQueue
{
    private const TABLE = 'notification_digest';

    /** Nombre maximal d'entrées agrégées dans un même e-mail de synthèse. */
    private const MAX_ITEMS = 100;

    public function __construct(private readonly LogService $logger)
    {
    }

    /**
     * Empile une notification de faible sévérité en attente de synthèse.
     *
     * @return bool Vrai si la mise en file a réussi ; faux si la persistance est indisponible
     */
    public function queue(
        Severity $severity,
        ?NotificationCategory $category,
        ?string $family,
        string $subject,
        string $message
    ): bool {
        try {
            $this->ensureTableExists();
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare(
                'INSERT INTO `' . self::TABLE . '` (severity, category, family, subject, message, queued_at)
                 VALUES (:severity, :category, :family, :subject, :message, NOW())'
            );
            $stmt->execute([
                ':severity' => $severity->value,
                ':category' => $category?->value,
                ':family' => $family !== null && $family !== '' ? strtoupper($family) : null,
                ':subject' => mb_substr($subject, 0, 255),
                ':message' => $message,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('NotificationDigest: mise en file impossible', [
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Indique combien d'entrées sont en attente de synthèse (0 si base indisponible).
     */
    public function pendingCount(): int
    {
        try {
            $this->ensureTableExists();
            $pdo = Database::getConnection();
            $stmt = $pdo->query('SELECT COUNT(*) FROM `' . self::TABLE . '`');

            return $stmt === false ? 0 : (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Récupère et CONSOMME les entrées en attente, regroupées par (sévérité, famille, sujet).
     *
     * Chaque groupe porte un compteur d'occurrences, le dernier message et la dernière
     * date. Les lignes lues sont supprimées : un flush ne renvoie jamais deux fois la
     * même alerte. Retourne un tableau vide si rien n'est en attente ou si la base est
     * indisponible.
     *
     * @return list<array{severity_code: string, severity_label: string, severity_emoji: string, family: ?string, subject: string, message: string, count: int, last_seen: string}>
     */
    public function flush(): array
    {
        try {
            $this->ensureTableExists();
            $pdo = Database::getConnection();

            $rows = $pdo->query(
                'SELECT id, severity, category, family, subject, message, queued_at
                 FROM `' . self::TABLE . '`
                 ORDER BY queued_at ASC
                 LIMIT ' . self::MAX_ITEMS
            );
            $rows = $rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                return [];
            }

            /** @var array<string, array{severity_code: string, severity_label: string, severity_emoji: string, family: ?string, subject: string, message: string, count: int, last_seen: string}> $grouped */
            $grouped = [];
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int) $row['id'];
                $severity = Severity::tryFrom((string) $row['severity']) ?? Severity::Info;
                $family = isset($row['family']) && is_string($row['family']) && $row['family'] !== ''
                    ? $row['family'] : null;
                $subject = (string) ($row['subject'] ?? '');
                $key = $severity->value . '|' . ($family ?? '') . '|' . $subject;

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'severity_code' => $severity->code(),
                        'severity_label' => $severity->label(),
                        'severity_emoji' => $severity->emoji(),
                        'family' => $family,
                        'subject' => $subject,
                        'message' => (string) ($row['message'] ?? ''),
                        'count' => 0,
                        'last_seen' => (string) ($row['queued_at'] ?? ''),
                    ];
                }
                $grouped[$key]['count']++;
                // Conserve le message et l'horodatage les plus récents (ordre ASC).
                $grouped[$key]['message'] = (string) ($row['message'] ?? '');
                $grouped[$key]['last_seen'] = (string) ($row['queued_at'] ?? '');
            }

            $this->deleteByIds($pdo, $ids);

            return array_values($grouped);
        } catch (\Throwable $e) {
            $this->logger->warning('NotificationDigest: flush impossible', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param int[] $ids
     */
    private function deleteByIds(PDO $pdo, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare('DELETE FROM `' . self::TABLE . '` WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_values($ids));
    }

    /** Crée la table de file d'attente si elle n'existe pas encore. */
    private function ensureTableExists(): void
    {
        $pdo = Database::getConnection();

        $check = $pdo->prepare('SHOW TABLES LIKE :table');
        $check->execute([':table' => self::TABLE]);
        if ($check->fetch() !== false) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                severity VARCHAR(16) NOT NULL,
                category VARCHAR(32) NULL,
                family VARCHAR(16) NULL,
                subject VARCHAR(255) NOT NULL,
                message TEXT NULL,
                queued_at DATETIME NOT NULL,
                INDEX idx_queued (queued_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
