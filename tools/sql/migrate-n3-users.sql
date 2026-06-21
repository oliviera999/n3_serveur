-- Migration : table n3_users (gestionnaire d'utilisateurs)
-- À exécuter une fois en production avant tools/bootstrap-admin-user.php

CREATE TABLE IF NOT EXISTS `n3_users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `email` VARCHAR(191) DEFAULT NULL,
  `display_name` VARCHAR(128) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'operator', 'reader') NOT NULL DEFAULT 'reader',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_n3_users_username` (`username`),
  KEY `idx_n3_users_active` (`is_active`),
  KEY `idx_n3_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
