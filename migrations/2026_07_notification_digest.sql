-- File d'attente du digest de notifications : les alertes de faible priorité (P3/P4)
-- y sont accumulées puis regroupées en UN SEUL e-mail lors d'un flush (tick CRON).
-- Utilisée par App\Notification\NotificationDigest.
--
-- NOTE : jusqu'ici cette table était créée à la volée par NotificationDigest::ensureTableExists().
-- Cette migration la versionne explicitement ; le schéma est identique (idempotent).

CREATE TABLE IF NOT EXISTS notification_digest (
    id INT AUTO_INCREMENT PRIMARY KEY,
    severity VARCHAR(16) NOT NULL,
    category VARCHAR(32) NULL,
    family VARCHAR(16) NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NULL,
    queued_at DATETIME NOT NULL,
    INDEX idx_queued (queued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
