-- =====================================================================
-- Connecteur MCP — mode productivité (v6.53.0)
-- =====================================================================
-- Idempotent : CREATE TABLE IF NOT EXISTS uniquement.
-- À jouer UNE fois en production APRÈS migrations/2026_11_mcp_oauth.sql
-- et migrations/2026_11_mcp_exploitation.sql. Voir docs/MCP.md.
--
-- Tables :
--   mcp_confirmations      codes à 6 chiffres (HMAC) pour opérations sensibles
--   mcp_confirm_unlocks    fenêtres de déverrouillage après iot_confirm_unlock
--   mcp_scheduled_restores rétablissement automatique pompe aquarium après arrêt MCP
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_confirmations` (
  `confirmation_id` CHAR(32) NOT NULL,
  `user_id` INT NOT NULL,
  `client_id` VARCHAR(512) NOT NULL,
  `tool` VARCHAR(64) NOT NULL,
  `args_fingerprint` CHAR(64) NOT NULL COMMENT 'sha256 JSON canonique (sans champs confirmation_*)',
  `categories` VARCHAR(255) NOT NULL COMMENT 'CSV des catégories exigeant le code',
  `summary_json` TEXT NOT NULL COMMENT 'Résumé présenté (cible, effet, risques)',
  `code_hmac` CHAR(64) NOT NULL COMMENT 'HMAC-SHA256 du code à 6 chiffres',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`confirmation_id`),
  KEY `idx_mcp_confirm_user_created` (`user_id`, `created_at`),
  KEY `idx_mcp_confirm_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_confirm_unlocks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `client_id` VARCHAR(512) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mcp_unlock_lookup` (`user_id`, `client_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_scheduled_restores` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `family` VARCHAR(16) NOT NULL,
  `env` VARCHAR(32) NOT NULL,
  `target_key` VARCHAR(96) NOT NULL COMMENT 'Table outputs + board (annulation)',
  `gpio` INT NOT NULL COMMENT 'GPIO à rétablir (typ. 117 puis 16)',
  `restore_value` VARCHAR(32) NOT NULL COMMENT 'Valeur cible au rétablissement',
  `restore_at` DATETIME NOT NULL,
  `created_by` INT NOT NULL COMMENT 'n3_users.id',
  `reason` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending', 'done', 'cancelled', 'failed') NOT NULL DEFAULT 'pending',
  `done_at` DATETIME DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mcp_restore_due` (`status`, `restore_at`),
  KEY `idx_mcp_restore_target` (`target_key`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
