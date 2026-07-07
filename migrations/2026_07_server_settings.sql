-- Réglages serveur globaux (supervision) — journal audit HMAC, etc.
CREATE TABLE IF NOT EXISTS `serverSettings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` VARCHAR(64) DEFAULT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
