-- Migration : GPIO 118-123 angles servo nourrissage FFP3
-- Idempotent : ON DUPLICATE KEY UPDATE sur (board, gpio)

INSERT INTO `ffp3Outputs` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`) VALUES
('1', 118, 'angleReposGros', '88', 'Angle repos servo gros poissons (degres)', NOW(), 'migration'),
('1', 119, 'angleDistribGros', '140', 'Angle distribution servo gros poissons (degres)', NOW(), 'migration'),
('1', 120, 'angleInterGros', '45', 'Angle intermediaire servo gros poissons (degres)', NOW(), 'migration'),
('1', 121, 'angleReposPetits', '88', 'Angle repos servo petits poissons (degres)', NOW(), 'migration'),
('1', 122, 'angleDistribPetits', '140', 'Angle distribution servo petits poissons (degres)', NOW(), 'migration'),
('1', 123, 'angleInterPetits', '45', 'Angle intermediaire servo petits poissons (degres)', NOW(), 'migration')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

INSERT INTO `ffp3Outputs2` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`) VALUES
('1', 118, 'angleReposGros', '88', 'Angle repos servo gros poissons (degres)', NOW(), 'migration'),
('1', 119, 'angleDistribGros', '140', 'Angle distribution servo gros poissons (degres)', NOW(), 'migration'),
('1', 120, 'angleInterGros', '45', 'Angle intermediaire servo gros poissons (degres)', NOW(), 'migration'),
('1', 121, 'angleReposPetits', '88', 'Angle repos servo petits poissons (degres)', NOW(), 'migration'),
('1', 122, 'angleDistribPetits', '140', 'Angle distribution servo petits poissons (degres)', NOW(), 'migration'),
('1', 123, 'angleInterPetits', '45', 'Angle intermediaire servo petits poissons (degres)', NOW(), 'migration')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

INSERT INTO `ffp3Outputs3` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT '4', `gpio`, `name`, `state`, `description`, NOW(), 'migration'
FROM `ffp3Outputs` WHERE `board` = '1' AND `gpio` IN (118, 119, 120, 121, 122, 123)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

INSERT INTO `ffp3OutputsS3` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT '5', `gpio`, `name`, `state`, `description`, NOW(), 'migration'
FROM `ffp3Outputs` WHERE `board` = '1' AND `gpio` IN (118, 119, 120, 121, 122, 123)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

INSERT INTO `ffp3OutputsS3Test` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT '6', `gpio`, `name`, `state`, `description`, NOW(), 'migration'
FROM `ffp3Outputs` WHERE `board` = '1' AND `gpio` IN (118, 119, 120, 121, 122, 123)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);
