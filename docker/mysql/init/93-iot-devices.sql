-- Catalogue d'appareils IoT (instances) — aligné migrations/2026_10_iot_devices.sql
CREATE TABLE IF NOT EXISTS `iotDevices` (
    `device_id` VARCHAR(64) NOT NULL,
    `family` VARCHAR(32) NOT NULL,
    `board` VARCHAR(64) DEFAULT NULL,
    `label` VARCHAR(128) NOT NULL,
    `env` VARCHAR(32) NOT NULL DEFAULT 'prod',
    `page_key` VARCHAR(64) DEFAULT NULL,
    `page_url` VARCHAR(255) DEFAULT NULL,
    `live_module_id` VARCHAR(64) DEFAULT NULL,
    `live_health_url` VARCHAR(255) DEFAULT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 1000,
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` VARCHAR(64) DEFAULT NULL,
    PRIMARY KEY (`device_id`),
    KEY `idx_family` (`family`),
    KEY `idx_active_sort` (`active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
