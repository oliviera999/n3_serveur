<?php

declare(strict_types=1);

namespace App\Notification;

use App\Config\Database;
use App\Service\LogService;
use PDO;

/**
 * Anti-spam transversal + historique des notifications.
 *
 * Généralise le mécanisme de cooldown de ErrorAlertService à TOUTES les alertes :
 * chaque envoi est enregistré dans la table `notification_log` (clé, sévérité,
 * catégorie, destinataire, sujet, horodatage). Avant un envoi, allow() vérifie
 * qu'aucune alerte de même clé n'est partie depuis moins de `cooldownSeconds`.
 *
 * Robustesse : en cas d'indisponibilité de la base, allow() « échoue ouvert »
 * (autorise l'envoi) — on préfère un éventuel doublon à une alerte critique
 * silencieusement perdue. La table sert aussi de journal d'audit unifié.
 */
class AlertThrottler
{
    private const TABLE = 'notification_log';

    /** Rétention de l'historique (jours) avant purge. */
    private const RETENTION_DAYS = 30;

    public function __construct(private LogService $logger)
    {
    }

    /**
     * Indique si une alerte de clé donnée peut être envoyée maintenant, c.-à-d.
     * qu'aucun envoi de même clé n'a eu lieu dans la fenêtre de cooldown.
     *
     * En cas d'erreur de persistance : autorise l'envoi (fail-open) et journalise.
     */
    public function allow(string $key, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return true;
        }

        try {
            $this->ensureTableExists();
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare(
                'SELECT MAX(sent_at) AS last_sent FROM `' . self::TABLE . '` WHERE alert_key = :key'
            );
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row) || $row['last_sent'] === null) {
                return true;
            }

            $lastTs = strtotime((string) $row['last_sent']);
            if ($lastTs === false) {
                return true;
            }

            return (time() - $lastTs) >= $cooldownSeconds;
        } catch (\Throwable $e) {
            $this->logger->warning('AlertThrottler indisponible, envoi autorisé par défaut', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Enregistre un envoi effectif (alimente le cooldown et l'historique).
     * Silencieux en cas d'échec de persistance.
     */
    public function record(
        string $key,
        Severity $severity,
        ?NotificationCategory $category,
        string $recipient,
        string $subject
    ): void {
        try {
            $this->ensureTableExists();
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare(
                'INSERT INTO `' . self::TABLE . '` (alert_key, severity, category, recipient, subject, sent_at)
                 VALUES (:key, :severity, :category, :recipient, :subject, NOW())'
            );
            $stmt->execute([
                ':key' => $key,
                ':severity' => $severity->value,
                ':category' => $category?->value,
                ':recipient' => $recipient,
                ':subject' => mb_substr($subject, 0, 255),
            ]);

            $this->cleanupOldEntries();
        } catch (\Throwable $e) {
            $this->logger->warning('AlertThrottler: enregistrement impossible', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Purge les entrées plus anciennes que la rétention (throttlé à 1×/h). */
    private function cleanupOldEntries(): void
    {
        static $lastCleanup = 0;
        $now = time();
        if ($now - $lastCleanup < 3600) {
            return;
        }
        $lastCleanup = $now;

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'DELETE FROM `' . self::TABLE . '` WHERE sent_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
        );
        $stmt->execute([':days' => self::RETENTION_DAYS]);
    }

    /** Crée la table d'historique si elle n'existe pas encore. */
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
                alert_key VARCHAR(191) NOT NULL,
                severity VARCHAR(16) NOT NULL,
                category VARCHAR(32) NULL,
                recipient VARCHAR(255) NULL,
                subject VARCHAR(255) NULL,
                sent_at DATETIME NOT NULL,
                INDEX idx_key_sent (alert_key, sent_at),
                INDEX idx_sent (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
