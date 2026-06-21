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

-- Compte admin local (mot de passe : localadmin — aligné .env.docker.example)
INSERT INTO `n3_users` (`username`, `email`, `display_name`, `password_hash`, `role`, `is_active`)
VALUES (
  'admin',
  'admin@local.test',
  'Administrateur local',
  '$2y$10$CiGnIyELv40ProOPLm5.OuzpMXPLX.mHEEj86PfwtBO0SsRLCtw2y',
  'admin',
  1
) ON DUPLICATE KEY UPDATE `username` = `username`;
