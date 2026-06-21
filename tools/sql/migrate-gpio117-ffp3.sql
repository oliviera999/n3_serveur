-- Migration : GPIO 117 ForcePompeAquarium (correctif switch forçage pompe — v5.2.9)
-- Idempotent : ON DUPLICATE KEY UPDATE sur (board, gpio)

INSERT INTO `ffp3Outputs` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
VALUES ('1', 117, 'ForcePompeAquarium', '0', 'Force la BDD pompe aquarium a 1 (ignore etat switch ESP32)', NOW(), 'migration')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

INSERT INTO `ffp3Outputs2` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
VALUES ('1', 117, 'ForcePompeAquarium', '0', 'Force la BDD pompe aquarium a 1 (ignore etat switch ESP32)', NOW(), 'migration')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);
