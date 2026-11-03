-- =====================================================================
-- Connecteur MCP + OAuth (Docker local) — aligné migrations/2026_11_mcp_oauth.sql (source de vérité)
-- =====================================================================
-- Idempotent : CREATE TABLE IF NOT EXISTS uniquement (aucune donnée existante touchée).
-- À jouer UNE fois en production (phpMyAdmin o2switch ou `mysql … < fichier`), avant
-- d'activer MCP_ENABLED=true. Voir docs/MCP.md § Déploiement.
--
-- Tables :
--   mcp_oauth_clients  clients OAuth enregistrés par DCR (RFC 7591) — publics, sans secret.
--                      Les clients CIMD (client_id = URL) ne sont PAS stockés ici :
--                      leur document est mis en cache dans mcp_oauth_cimd_cache.
--   mcp_oauth_cimd_cache  cache des Client ID Metadata Documents (TTL court).
--   mcp_oauth_codes    codes d'autorisation (usage unique, ~60 s, PKCE S256).
--   mcp_oauth_tokens   jetons d'accès / de rafraîchissement, HACHÉS (sha256), par famille
--                      de rotation (réutilisation d'un refresh révoqué → famille révoquée).
--   mcp_control_plans  plans de pilotage (iot_plan_output_change → iot_apply_plan), ~10 min.
--
-- Aucun jeton, code ou secret n'est stocké en clair : seuls leurs condensats sha256.
-- La purge des lignes expirées est faite par le CRON (CronOrchestrator → McpOAuthPurgeCommand).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `mcp_oauth_clients` (
  `client_id` VARCHAR(64) NOT NULL,
  `client_name` VARCHAR(191) DEFAULT NULL,
  `redirect_uris` TEXT NOT NULL COMMENT 'JSON (liste)',
  `grant_types` VARCHAR(191) NOT NULL DEFAULT 'authorization_code,refresh_token',
  `token_endpoint_auth_method` VARCHAR(32) NOT NULL DEFAULT 'none',
  `software_id` VARCHAR(191) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`client_id`),
  KEY `idx_mcp_clients_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_oauth_cimd_cache` (
  `url_hash` CHAR(64) NOT NULL,
  `client_id` VARCHAR(512) NOT NULL,
  `document` TEXT NOT NULL COMMENT 'JSON validé',
  `fetched_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`url_hash`),
  KEY `idx_mcp_cimd_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_oauth_codes` (
  `code_hash` CHAR(64) NOT NULL,
  `client_id` VARCHAR(512) NOT NULL,
  `user_id` INT NOT NULL,
  `redirect_uri` VARCHAR(512) NOT NULL,
  `scope` VARCHAR(255) NOT NULL,
  `resource` VARCHAR(512) DEFAULT NULL,
  `code_challenge` VARCHAR(128) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code_hash`),
  KEY `idx_mcp_codes_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_oauth_tokens` (
  `token_hash` CHAR(64) NOT NULL,
  `token_type` ENUM('access', 'refresh') NOT NULL,
  `family_id` CHAR(32) NOT NULL COMMENT 'Famille de rotation (un consentement)',
  `client_id` VARCHAR(512) NOT NULL,
  `user_id` INT NOT NULL,
  `scope` VARCHAR(255) NOT NULL,
  `resource` VARCHAR(512) DEFAULT NULL,
  `credential_epoch` CHAR(16) DEFAULT NULL COMMENT 'Empreinte du mot de passe au consentement (jeton invalide si le mot de passe change)',
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `idx_mcp_tokens_family` (`family_id`),
  KEY `idx_mcp_tokens_user` (`user_id`),
  KEY `idx_mcp_tokens_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_control_plans` (
  `plan_id` CHAR(32) NOT NULL,
  `user_id` INT NOT NULL,
  `client_id` VARCHAR(512) NOT NULL,
  `env` VARCHAR(32) NOT NULL,
  `family` VARCHAR(16) NOT NULL,
  `gpio` INT NOT NULL,
  `target_key` VARCHAR(96) NOT NULL COMMENT 'Table outputs réellement ciblée + board (plafond nourrissage)',
  `action` VARCHAR(16) NOT NULL COMMENT 'set | feed',
  `before_state` VARCHAR(255) DEFAULT NULL,
  `target_state` VARCHAR(255) DEFAULT NULL,
  `payload` TEXT NOT NULL COMMENT 'JSON : plan présenté (avant, après, risques)',
  `expires_at` DATETIME NOT NULL,
  `applied_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`plan_id`),
  KEY `idx_mcp_plans_expires` (`expires_at`),
  KEY `idx_mcp_plans_applied` (`target_key`, `action`, `applied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
