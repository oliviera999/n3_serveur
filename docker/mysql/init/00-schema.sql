CREATE TABLE IF NOT EXISTS `Boards` (
  `board` VARCHAR(32) NOT NULL,
  `last_request` DATETIME NULL,
  PRIMARY KEY (`board`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ffp3Outputs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `board` VARCHAR(32) NOT NULL,
  `gpio` INT NOT NULL,
  `name` VARCHAR(191) DEFAULT NULL,
  `state` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `requestTime` DATETIME DEFAULT NULL,
  `lastModifiedBy` VARCHAR(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ffp3Outputs_board_gpio` (`board`, `gpio`),
  KEY `idx_ffp3Outputs_board` (`board`),
  KEY `idx_ffp3Outputs_gpio` (`gpio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ffp3Outputs2` LIKE `ffp3Outputs`;
CREATE TABLE IF NOT EXISTS `ffp3Outputs3` LIKE `ffp3Outputs`;
CREATE TABLE IF NOT EXISTS `ffp3OutputsS3` LIKE `ffp3Outputs`;
CREATE TABLE IF NOT EXISTS `ffp3OutputsS3Test` LIKE `ffp3Outputs`;

CREATE TABLE IF NOT EXISTS `ffp3Data` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `sensor` VARCHAR(30) DEFAULT NULL,
  `version` VARCHAR(30) DEFAULT NULL,
  `TempAir` DOUBLE DEFAULT NULL,
  `Humidite` DOUBLE DEFAULT NULL,
  `TempEau` DOUBLE DEFAULT NULL,
  `EauPotager` DOUBLE DEFAULT NULL,
  `EauAquarium` DOUBLE DEFAULT NULL,
  `EauReserve` DOUBLE DEFAULT NULL,
  `diffMaree` DOUBLE DEFAULT NULL,
  `Luminosite` DOUBLE DEFAULT NULL,
  `etatPompeAqua` INT DEFAULT NULL,
  `etatPompeTank` INT DEFAULT NULL,
  `etatHeat` INT DEFAULT NULL,
  `etatUV` INT DEFAULT NULL,
  `bouffeMatin` INT DEFAULT NULL,
  `bouffeMidi` INT DEFAULT NULL,
  `bouffePetits` INT DEFAULT NULL,
  `bouffeGros` INT DEFAULT NULL,
  `aqThreshold` INT DEFAULT NULL,
  `tankThreshold` INT DEFAULT NULL,
  `chauffageThreshold` DOUBLE DEFAULT NULL,
  `mail` VARCHAR(255) DEFAULT NULL,
  `mailNotif` VARCHAR(255) DEFAULT NULL,
  `resetMode` INT DEFAULT NULL,
  `bouffeSoir` INT DEFAULT NULL,
  `tempsGros` INT DEFAULT NULL,
  `tempsPetits` INT DEFAULT NULL,
  `tempsRemplissageSec` INT DEFAULT NULL,
  `limFlood` INT DEFAULT NULL,
  `WakeUp` INT DEFAULT NULL,
  `FreqWakeUp` INT DEFAULT NULL,
  `configSynced` INT DEFAULT NULL,
  `Pression` DOUBLE DEFAULT NULL,
  `post_id` VARCHAR(64) DEFAULT NULL,
  `reading_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ffp3Data_post_id` (`post_id`),
  KEY `idx_ffp3Data_reading_time` (`reading_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ffp3Data2` LIKE `ffp3Data`;
CREATE TABLE IF NOT EXISTS `ffp3Data3` LIKE `ffp3Data`;
CREATE TABLE IF NOT EXISTS `ffp3DataS3` LIKE `ffp3Data`;
CREATE TABLE IF NOT EXISTS `ffp3DataS3Test` LIKE `ffp3Data`;

CREATE TABLE IF NOT EXISTS `ffp3Heartbeat` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `uptime` BIGINT NOT NULL,
  `freeHeap` BIGINT NOT NULL,
  `minHeap` BIGINT NOT NULL,
  `reboots` INT NOT NULL,
  `reading_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ffp3Heartbeat_reading_time` (`reading_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ffp3Heartbeat2` LIKE `ffp3Heartbeat`;
CREATE TABLE IF NOT EXISTS `ffp3Heartbeat3` LIKE `ffp3Heartbeat`;
CREATE TABLE IF NOT EXISTS `ffp3HeartbeatS3` LIKE `ffp3Heartbeat`;
CREATE TABLE IF NOT EXISTS `ffp3HeartbeatS3Test` LIKE `ffp3Heartbeat`;

CREATE TABLE IF NOT EXISTS `ffp3OtaTrigger` (
  `env` VARCHAR(32) NOT NULL,
  `pending` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`env`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `msp1Data` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `sensor` VARCHAR(30) DEFAULT NULL,
  `version` VARCHAR(30) DEFAULT NULL,
  `TempAirInt` DOUBLE DEFAULT NULL,
  `TempAirExt` DOUBLE DEFAULT NULL,
  `HumidAirInt` DOUBLE DEFAULT NULL,
  `HumidAirExt` DOUBLE DEFAULT NULL,
  `LuminositeA` DOUBLE DEFAULT NULL,
  `LuminositeB` DOUBLE DEFAULT NULL,
  `LuminositeC` DOUBLE DEFAULT NULL,
  `LuminositeD` DOUBLE DEFAULT NULL,
  `LuminositeMoy` DOUBLE DEFAULT NULL,
  `ServoHB` INT DEFAULT NULL,
  `ServoGD` INT DEFAULT NULL,
  `HumidSol` DOUBLE DEFAULT NULL,
  `Pluie` DOUBLE DEFAULT NULL,
  `TempEau` DOUBLE DEFAULT NULL,
  `PontDiv` INT DEFAULT NULL,
  `WakeUp` INT DEFAULT NULL,
  `SeuilSec` INT DEFAULT NULL,
  `FreqWakeUp` INT DEFAULT NULL,
  `SeuilPontDiv` INT DEFAULT NULL,
  `mail` VARCHAR(255) DEFAULT NULL,
  `mailNotif` VARCHAR(255) DEFAULT NULL,
  `resetMode` INT DEFAULT NULL,
  `bootCount` INT DEFAULT NULL,
  `reading_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msp1Data_reading_time` (`reading_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `msp1DataTest` LIKE `msp1Data`;

CREATE TABLE IF NOT EXISTS `msp1Outputs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) DEFAULT NULL,
  `board` VARCHAR(32) NOT NULL,
  `gpio` INT NOT NULL,
  `state` VARCHAR(255) DEFAULT NULL,
  `requestTime` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_msp1Outputs_board_gpio` (`board`, `gpio`),
  KEY `idx_msp1Outputs_board` (`board`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `msp1OutputsTest` LIKE `msp1Outputs`;

CREATE TABLE IF NOT EXISTS `n3ppData` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `sensor` VARCHAR(30) DEFAULT NULL,
  `version` VARCHAR(30) DEFAULT NULL,
  `TempAir` DOUBLE DEFAULT NULL,
  `Humidite` DOUBLE DEFAULT NULL,
  `Luminosite` DOUBLE DEFAULT NULL,
  `Humid1` DOUBLE DEFAULT NULL,
  `Humid2` DOUBLE DEFAULT NULL,
  `Humid3` DOUBLE DEFAULT NULL,
  `Humid4` DOUBLE DEFAULT NULL,
  `HumidMoy` DOUBLE DEFAULT NULL,
  `PontDiv` INT DEFAULT NULL,
  `WakeUp` INT DEFAULT NULL,
  `ArrosageManu` INT DEFAULT NULL,
  `SeuilSec` INT DEFAULT NULL,
  `FreqWakeUp` INT DEFAULT NULL,
  `SeuilPontDiv` INT DEFAULT NULL,
  `mail` VARCHAR(255) DEFAULT NULL,
  `mailNotif` VARCHAR(255) DEFAULT NULL,
  `HeureArrosage` INT DEFAULT NULL,
  `resetMode` INT DEFAULT NULL,
  `etatPompe` INT DEFAULT NULL,
  `tempsArrosage` INT DEFAULT NULL,
  `bootCount` INT DEFAULT NULL,
  `reading_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_n3ppData_reading_time` (`reading_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `n3ppDataTest` LIKE `n3ppData`;

CREATE TABLE IF NOT EXISTS `n3ppOutputs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) DEFAULT NULL,
  `board` VARCHAR(32) NOT NULL,
  `gpio` INT NOT NULL,
  `state` VARCHAR(255) DEFAULT NULL,
  `requestTime` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_n3ppOutputs_board_gpio` (`board`, `gpio`),
  KEY `idx_n3ppOutputs_board` (`board`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `n3ppOutputsTest` LIKE `n3ppOutputs`;

CREATE TABLE IF NOT EXISTS `UploadPhoto1Outputs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) DEFAULT NULL,
  `board` VARCHAR(32) NOT NULL,
  `gpio` INT NOT NULL,
  `state` VARCHAR(255) DEFAULT NULL,
  `requestTime` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_upload1_board_gpio` (`board`, `gpio`),
  KEY `idx_upload1_board` (`board`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `UploadPhoto2Outputs` LIKE `UploadPhoto1Outputs`;
CREATE TABLE IF NOT EXISTS `UploadPhoto3Outputs` LIKE `UploadPhoto1Outputs`;

CREATE TABLE IF NOT EXISTS `error_alerts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `message` TEXT NOT NULL,
  `normalized_message` VARCHAR(255) NOT NULL,
  `context` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `alerted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_error_alerts_normalized` (`normalized_message`),
  KEY `idx_error_alerts_created` (`created_at`),
  KEY `idx_error_alerts_alerted` (`alerted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
