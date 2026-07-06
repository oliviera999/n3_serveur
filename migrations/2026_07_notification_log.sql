-- Table anti-spam des notifications : historique des e-mails envoyés (clé, sévérité,
-- catégorie, destinataire, sujet, horodatage) utilisée par App\Notification\AlertThrottler
-- pour appliquer les cooldowns par clé d'alerte et purger l'historique (> 30 j).
--
-- NOTE : jusqu'ici cette table était créée à la volée par AlertThrottler::ensureTableExists().
-- Cette migration la versionne explicitement ; le schéma est identique (idempotent).

CREATE TABLE IF NOT EXISTS notification_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_key VARCHAR(191) NOT NULL,
    severity VARCHAR(16) NOT NULL,
    category VARCHAR(32) NULL,
    recipient VARCHAR(255) NULL,
    subject VARCHAR(255) NULL,
    sent_at DATETIME NOT NULL,
    INDEX idx_key_sent (alert_key, sent_at),
    INDEX idx_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
