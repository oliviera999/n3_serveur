INSERT INTO `Boards` (`board`, `last_request`) VALUES
('1', NOW()),
('2', NOW()),
('3', NOW()),
('4', NOW()),
('5', NOW()),
('6', NOW()),
('7', NOW())
ON DUPLICATE KEY UPDATE `last_request` = VALUES(`last_request`);

INSERT INTO `ffp3Outputs` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`) VALUES
('1', 2, 'Radiateur', '0', 'Actionneur chauffage', NOW(), 'web'),
('1', 15, 'Lumiere', '0', 'Actionneur lumiere', NOW(), 'web'),
('1', 16, 'Pompe aquarium', '0', 'Pompe circuit aquarium', NOW(), 'web'),
('1', 18, 'Pompe reserve', '0', 'Pompe circuit reserve', NOW(), 'web'),
('1', 100, 'mail', 'dev@local.test', 'Adresse e-mail notifications', NOW(), 'web'),
('1', 101, 'mailNotif', 'checked', 'Activation notifications e-mail', NOW(), 'web'),
('1', 102, 'aqThreshold', '18', 'Seuil pompe aquarium', NOW(), 'web'),
('1', 103, 'tankThreshold', '80', 'Seuil pompe reserve', NOW(), 'web'),
('1', 104, 'chauffageThreshold', '18', 'Seuil chauffage', NOW(), 'web'),
('1', 105, 'bouffeMatin', '8', 'Heure nourrissage matin', NOW(), 'web'),
('1', 106, 'bouffeMidi', '12', 'Heure nourrissage midi', NOW(), 'web'),
('1', 107, 'bouffeSoir', '19', 'Heure nourrissage soir', NOW(), 'web'),
('1', 108, 'bouffePetits', '0', 'Commande one-shot petits poissons', NOW(), 'web'),
('1', 109, 'bouffeGros', '0', 'Commande one-shot gros poissons', NOW(), 'web'),
('1', 110, 'resetMode', '0', 'Commande one-shot reset', NOW(), 'web'),
('1', 111, 'tempsGros', '2', 'Duree nourrissage gros poissons', NOW(), 'web'),
('1', 112, 'tempsPetits', '2', 'Duree nourrissage petits poissons', NOW(), 'web'),
('1', 113, 'tempsRemplissageSec', '5', 'Temps remplissage', NOW(), 'web'),
('1', 114, 'limFlood', '8', 'Limite anti-flood', NOW(), 'web'),
('1', 115, 'WakeUp', '0', 'Commande one-shot wake-up', NOW(), 'web'),
('1', 116, 'FreqWakeUp', '6', 'Frequence wake-up (h)', NOW(), 'web'),
('1', 117, 'ForcePompeAquarium', '0', 'Force la BDD pompe aquarium a 1 (ignore etat switch ESP32)', NOW(), 'web'),
('1', 118, 'angleReposGros', '88', 'Angle repos servo gros poissons (degres)', NOW(), 'web'),
('1', 119, 'angleDistribGros', '140', 'Angle distribution servo gros poissons (degres)', NOW(), 'web'),
('1', 120, 'angleInterGros', '45', 'Angle intermediaire servo gros poissons (degres)', NOW(), 'web'),
('1', 121, 'angleReposPetits', '88', 'Angle repos servo petits poissons (degres)', NOW(), 'web'),
('1', 122, 'angleDistribPetits', '140', 'Angle distribution servo petits poissons (degres)', NOW(), 'web'),
('1', 123, 'angleInterPetits', '45', 'Angle intermediaire servo petits poissons (degres)', NOW(), 'web')
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`description` = VALUES(`description`),
`requestTime` = VALUES(`requestTime`),
`lastModifiedBy` = VALUES(`lastModifiedBy`);

INSERT INTO `ffp3Outputs2` SELECT * FROM `ffp3Outputs` WHERE `board` = '1'
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`description` = VALUES(`description`),
`requestTime` = VALUES(`requestTime`),
`lastModifiedBy` = VALUES(`lastModifiedBy`);

INSERT INTO `ffp3Outputs3` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT '4', `gpio`, `name`, `state`, `description`, NOW(), `lastModifiedBy`
FROM `ffp3Outputs` WHERE `board` = '1'
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`description` = VALUES(`description`),
`requestTime` = VALUES(`requestTime`),
`lastModifiedBy` = VALUES(`lastModifiedBy`);

INSERT INTO `ffp3OutputsS3` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT '5', `gpio`, `name`, `state`, `description`, NOW(), `lastModifiedBy`
FROM `ffp3Outputs` WHERE `board` = '1'
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`description` = VALUES(`description`),
`requestTime` = VALUES(`requestTime`),
`lastModifiedBy` = VALUES(`lastModifiedBy`);

INSERT INTO `ffp3OutputsS3Test` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT '6', `gpio`, `name`, `state`, `description`, NOW(), `lastModifiedBy`
FROM `ffp3Outputs` WHERE `board` = '1'
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`description` = VALUES(`description`),
`requestTime` = VALUES(`requestTime`),
`lastModifiedBy` = VALUES(`lastModifiedBy`);

INSERT INTO `msp1Outputs` (`board`, `gpio`, `name`, `state`, `requestTime`) VALUES
('2', 2, 'Pompe arrosage', '0', NOW()),
('2', 100, 'mail', 'dev@local.test', NOW()),
('2', 101, 'mailNotif', 'checked', NOW()),
('2', 102, 'SeuilSec', '35', NOW()),
('2', 103, 'SeuilPontDiv', '2400', NOW()),
('2', 104, 'ServoHB', '90', NOW()),
('2', 105, 'ServoGD', '90', NOW()),
('2', 106, 'WakeUp', '0', NOW()),
('2', 107, 'FreqWakeUp', '10', NOW()),
('2', 110, 'resetMode', '0', NOW()),
('2', 111, 'ServoModeAuto', '1', NOW())
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`requestTime` = VALUES(`requestTime`);

INSERT INTO `msp1OutputsTest` SELECT * FROM `msp1Outputs` WHERE `board` = '2'
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`requestTime` = VALUES(`requestTime`);

INSERT INTO `n3ppOutputs` (`board`, `gpio`, `name`, `state`, `requestTime`) VALUES
('3', 2, 'Pompe irrigation', '0', NOW()),
('3', 15, 'Lampe', '0', NOW()),
('3', 16, 'Ventilation', '0', NOW()),
('3', 100, 'mail', 'dev@local.test', NOW()),
('3', 101, 'mailNotif', 'checked', NOW()),
('3', 102, 'SeuilSec', '40', NOW()),
('3', 103, 'SeuilPontDiv', '2300', NOW()),
('3', 104, 'HeureArrosage', '8', NOW()),
('3', 105, 'tempsArrosage', '10', NOW()),
('3', 106, 'WakeUp', '0', NOW()),
('3', 107, 'FreqWakeUp', '8', NOW()),
('3', 110, 'resetMode', '0', NOW())
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`requestTime` = VALUES(`requestTime`);

INSERT INTO `n3ppOutputsTest` SELECT * FROM `n3ppOutputs` WHERE `board` = '3'
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`requestTime` = VALUES(`requestTime`);

INSERT INTO `UploadPhoto1Outputs` (`board`, `gpio`, `name`, `state`, `requestTime`) VALUES
('5', 100, 'firmwareVersion', 'local-dev', NOW()),
('5', 102, 'mail', 'dev@local.test', NOW()),
('5', 103, 'mailNotif', 'checked', NOW()),
('5', 104, 'forceWakeUp', '0', NOW()),
('5', 105, 'sleepTime', '600', NOW()),
('5', 106, 'resetMode', '0', NOW())
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`requestTime` = VALUES(`requestTime`);

INSERT INTO `UploadPhoto2Outputs` (`board`, `gpio`, `name`, `state`, `requestTime`) VALUES
('6', 100, 'firmwareVersion', 'local-dev', NOW()),
('6', 102, 'mail', 'dev@local.test', NOW()),
('6', 103, 'mailNotif', 'checked', NOW()),
('6', 104, 'forceWakeUp', '0', NOW()),
('6', 105, 'sleepTime', '600', NOW()),
('6', 106, 'resetMode', '0', NOW())
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`requestTime` = VALUES(`requestTime`);

INSERT INTO `UploadPhoto3Outputs` (`board`, `gpio`, `name`, `state`, `requestTime`) VALUES
('7', 100, 'firmwareVersion', 'local-dev', NOW()),
('7', 102, 'mail', 'dev@local.test', NOW()),
('7', 103, 'mailNotif', 'checked', NOW()),
('7', 104, 'forceWakeUp', '0', NOW()),
('7', 105, 'sleepTime', '600', NOW()),
('7', 106, 'resetMode', '0', NOW())
ON DUPLICATE KEY UPDATE
`name` = VALUES(`name`),
`state` = VALUES(`state`),
`requestTime` = VALUES(`requestTime`);

INSERT INTO `ffp3Data` (`sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`, `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`, `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`, `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, `post_id`, `reading_time`)
VALUES
('esp32-local', 'local-dev', 24.8, 60.0, 23.5, 48.0, 55.0, 42.0, 0.1, 450.0, 0, 0, 0, 0, 8, 12, 0, 0, 18, 80, 18.0, 'dev@local.test', 'checked', 0, 19, 'seed-ffp3-1', NOW())
ON DUPLICATE KEY UPDATE `reading_time` = VALUES(`reading_time`);

INSERT INTO `ffp3Data2` SELECT * FROM `ffp3Data` WHERE `post_id` = 'seed-ffp3-1'
ON DUPLICATE KEY UPDATE `reading_time` = VALUES(`reading_time`);

INSERT INTO `ffp3Data3` SELECT * FROM `ffp3Data` WHERE `post_id` = 'seed-ffp3-1'
ON DUPLICATE KEY UPDATE `reading_time` = VALUES(`reading_time`);

INSERT INTO `ffp3DataS3` SELECT * FROM `ffp3Data` WHERE `post_id` = 'seed-ffp3-1'
ON DUPLICATE KEY UPDATE `reading_time` = VALUES(`reading_time`);

INSERT INTO `ffp3DataS3Test` SELECT * FROM `ffp3Data` WHERE `post_id` = 'seed-ffp3-1'
ON DUPLICATE KEY UPDATE `reading_time` = VALUES(`reading_time`);

INSERT INTO `msp1Data` (`sensor`, `version`, `TempAirInt`, `TempAirExt`, `HumidAirInt`, `HumidAirExt`, `LuminositeA`, `LuminositeB`, `LuminositeC`, `LuminositeD`, `LuminositeMoy`, `ServoHB`, `ServoGD`, `HumidSol`, `Pluie`, `TempEau`, `PontDiv`, `WakeUp`, `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`, `resetMode`, `bootCount`, `reading_time`)
VALUES
('msp-local', 'local-dev', 25.0, 23.8, 58.0, 61.0, 300.0, 320.0, 310.0, 305.0, 308.0, 90, 90, 44.0, 0.0, 22.0, 2300, 0, 35, 10, 2400, 'dev@local.test', 'checked', 0, 1, NOW());

INSERT INTO `msp1DataTest` SELECT * FROM `msp1Data` ORDER BY `id` DESC LIMIT 1;

INSERT INTO `n3ppData` (`sensor`, `version`, `TempAir`, `Humidite`, `Luminosite`, `Humid1`, `Humid2`, `Humid3`, `Humid4`, `HumidMoy`, `PontDiv`, `WakeUp`, `ArrosageManu`, `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`, `HeureArrosage`, `resetMode`, `etatPompe`, `tempsArrosage`, `bootCount`, `reading_time`)
VALUES
('n3pp-local', 'local-dev', 26.3, 57.0, 380.0, 41.0, 43.0, 42.0, 44.0, 42.5, 2200, 0, 0, 40, 8, 2300, 'dev@local.test', 'checked', 8, 0, 0, 10, 1, NOW());

INSERT INTO `n3ppDataTest` SELECT * FROM `n3ppData` ORDER BY `id` DESC LIMIT 1;

INSERT INTO `ffp3Heartbeat` (`uptime`, `freeHeap`, `minHeap`, `reboots`, `reading_time`) VALUES (12345, 100000, 90000, 1, NOW());
INSERT INTO `ffp3Heartbeat2` (`uptime`, `freeHeap`, `minHeap`, `reboots`, `reading_time`) VALUES (12345, 100000, 90000, 1, NOW());
INSERT INTO `ffp3Heartbeat3` (`uptime`, `freeHeap`, `minHeap`, `reboots`, `reading_time`) VALUES (12345, 100000, 90000, 1, NOW());
INSERT INTO `ffp3HeartbeatS3` (`uptime`, `freeHeap`, `minHeap`, `reboots`, `reading_time`) VALUES (12345, 100000, 90000, 1, NOW());
INSERT INTO `ffp3HeartbeatS3Test` (`uptime`, `freeHeap`, `minHeap`, `reboots`, `reading_time`) VALUES (12345, 100000, 90000, 1, NOW());
