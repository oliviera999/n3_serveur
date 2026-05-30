-- Trigger OTA FFP3 (Docker local — aligne prod audit 2026-05)
-- Voir migrations/CREATE_FFP3_OTA_TRIGGER_TABLE.sql

CREATE TABLE IF NOT EXISTS `ffp3OtaTrigger` (
    `env` VARCHAR(32) NOT NULL,
    `pending` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`env`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
