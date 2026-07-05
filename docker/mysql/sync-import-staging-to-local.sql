-- Synchronise iot_n3_local depuis iot_n3_import_staging (dump phpMyAdmin production).
-- Les schémas divergent : colonnes renommées, Boards refactorisé, ffp3Heartbeat (timestamp -> reading_time),
-- ffp3Data sans bootCount/mailSent côté local, post_id ajouté en NULL si absent en prod.
-- Ne crée aucune table : tronque puis recopie vers les tables existantes de iot_n3_local.
-- Prérequis : CREATE DATABASE iot_n3_import_staging; + import du fichier .sql dans cette base.
-- Post-migration S3 (Option A) : ne pas importer ffp3Data4 — données migrées vers ffp3DataS3 en prod avant import.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;
SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION';

-- Vidage (pas de FK déclarées, ordre quelconque)
TRUNCATE TABLE `iot_n3_local`.`UploadPhoto1Outputs`;
TRUNCATE TABLE `iot_n3_local`.`UploadPhoto2Outputs`;
TRUNCATE TABLE `iot_n3_local`.`UploadPhoto3Outputs`;
TRUNCATE TABLE `iot_n3_local`.`msp1Outputs`;
TRUNCATE TABLE `iot_n3_local`.`msp1OutputsTest`;
TRUNCATE TABLE `iot_n3_local`.`n3ppOutputs`;
TRUNCATE TABLE `iot_n3_local`.`n3ppOutputsTest`;
TRUNCATE TABLE `iot_n3_local`.`ffp3Outputs`;
TRUNCATE TABLE `iot_n3_local`.`ffp3Outputs2`;
TRUNCATE TABLE `iot_n3_local`.`ffp3Outputs3`;
TRUNCATE TABLE `iot_n3_local`.`ffp3OutputsS3`;
TRUNCATE TABLE `iot_n3_local`.`ffp3OutputsS3Test`;
TRUNCATE TABLE `iot_n3_local`.`ffp3Heartbeat`;
TRUNCATE TABLE `iot_n3_local`.`ffp3Heartbeat2`;
TRUNCATE TABLE `iot_n3_local`.`ffp3Heartbeat3`;
TRUNCATE TABLE `iot_n3_local`.`ffp3HeartbeatS3`;
TRUNCATE TABLE `iot_n3_local`.`ffp3HeartbeatS3Test`;
TRUNCATE TABLE `iot_n3_local`.`ffp3Data`;
TRUNCATE TABLE `iot_n3_local`.`ffp3Data2`;
TRUNCATE TABLE `iot_n3_local`.`ffp3Data3`;
TRUNCATE TABLE `iot_n3_local`.`ffp3DataS3`;
TRUNCATE TABLE `iot_n3_local`.`ffp3DataS3Test`;
TRUNCATE TABLE `iot_n3_local`.`msp1Data`;
TRUNCATE TABLE `iot_n3_local`.`msp1DataTest`;
TRUNCATE TABLE `iot_n3_local`.`n3ppData`;
TRUNCATE TABLE `iot_n3_local`.`n3ppDataTest`;
TRUNCATE TABLE `iot_n3_local`.`error_alerts`;
TRUNCATE TABLE `iot_n3_local`.`Boards`;

-- Boards : production id+board+nom -> local cle board (varchar)
INSERT INTO `iot_n3_local`.`Boards` (`board`, `last_request`)
SELECT CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `last_request`
FROM `iot_n3_import_staging`.`Boards`;

-- ffp3Data* : ignorer bootCount, mailSent ; post_id = NULL si absent en prod
INSERT INTO `iot_n3_local`.`ffp3Data` (
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, `post_id`, `reading_time`
)
SELECT
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, NULL, `reading_time`
FROM `iot_n3_import_staging`.`ffp3Data`;

INSERT INTO `iot_n3_local`.`ffp3Data2` (
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, `post_id`, `reading_time`
)
SELECT
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, NULL, `reading_time`
FROM `iot_n3_import_staging`.`ffp3Data2`;

INSERT INTO `iot_n3_local`.`ffp3Data3` (
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, `post_id`, `reading_time`
)
SELECT
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, NULL, `reading_time`
FROM `iot_n3_import_staging`.`ffp3Data3`;

INSERT INTO `iot_n3_local`.`ffp3DataS3` (
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, `post_id`, `reading_time`
)
SELECT
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, NULL, `reading_time`
FROM `iot_n3_import_staging`.`ffp3DataS3`;

INSERT INTO `iot_n3_local`.`ffp3DataS3Test` (
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, `post_id`, `reading_time`
)
SELECT
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, NULL, `reading_time`
FROM `iot_n3_import_staging`.`ffp3DataS3Test`;

-- Heartbeat : timestamp (prod) -> reading_time (local)
INSERT INTO `iot_n3_local`.`ffp3Heartbeat` (`id`, `uptime`, `freeHeap`, `minHeap`, `reboots`, `reading_time`)
SELECT `id`, `uptime`, `freeHeap`, `minHeap`, `reboots`, `timestamp`
FROM `iot_n3_import_staging`.`ffp3Heartbeat`;

INSERT INTO `iot_n3_local`.`ffp3Heartbeat2` (`id`, `uptime`, `freeHeap`, `minHeap`, `reboots`, `reading_time`)
SELECT `id`, `uptime`, `freeHeap`, `minHeap`, `reboots`, `timestamp`
FROM `iot_n3_import_staging`.`ffp3Heartbeat2`;

INSERT INTO `iot_n3_local`.`ffp3Heartbeat3` (`id`, `uptime`, `freeHeap`, `minHeap`, `reboots`, `reading_time`)
SELECT `id`, `uptime`, `freeHeap`, `minHeap`, `reboots`, `timestamp`
FROM `iot_n3_import_staging`.`ffp3Heartbeat3`;

INSERT INTO `iot_n3_local`.`ffp3HeartbeatS3` (`id`, `uptime`, `freeHeap`, `minHeap`, `reboots`, `reading_time`)
SELECT `id`, `uptime`, `freeHeap`, `minHeap`, `reboots`, `timestamp`
FROM `iot_n3_import_staging`.`ffp3HeartbeatS3`;

INSERT INTO `iot_n3_local`.`ffp3HeartbeatS3Test` (`id`, `uptime`, `freeHeap`, `minHeap`, `reboots`, `reading_time`)
SELECT `id`, `uptime`, `freeHeap`, `minHeap`, `reboots`, `timestamp`
FROM `iot_n3_import_staging`.`ffp3HeartbeatS3Test`;

-- msp1Data : exclure colonnes absentes du schéma local (ArrosageManu, HeureArrosage, etatPompe, tempsArrosage)
INSERT INTO `iot_n3_local`.`msp1Data` (
  `id`, `sensor`, `version`, `TempAirInt`, `TempAirExt`, `HumidAirInt`, `HumidAirExt`,
  `LuminositeA`, `LuminositeB`, `LuminositeC`, `LuminositeD`, `LuminositeMoy`,
  `ServoHB`, `ServoGD`, `HumidSol`, `Pluie`, `TempEau`, `PontDiv`, `WakeUp`,
  `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`, `resetMode`, `bootCount`, `reading_time`
)
SELECT
  `id`, `sensor`, `version`, `TempAirInt`, `TempAirExt`, `HumidAirInt`, `HumidAirExt`,
  `LuminositeA`, `LuminositeB`, `LuminositeC`, `LuminositeD`, `LuminositeMoy`,
  `ServoHB`, `ServoGD`, `HumidSol`, `Pluie`, `TempEau`, `PontDiv`, `WakeUp`,
  `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`, `resetMode`, `bootCount`, `reading_time`
FROM `iot_n3_import_staging`.`msp1Data`;

INSERT INTO `iot_n3_local`.`msp1DataTest` (
  `id`, `sensor`, `version`, `TempAirInt`, `TempAirExt`, `HumidAirInt`, `HumidAirExt`,
  `LuminositeA`, `LuminositeB`, `LuminositeC`, `LuminositeD`, `LuminositeMoy`,
  `ServoHB`, `ServoGD`, `HumidSol`, `Pluie`, `TempEau`, `PontDiv`, `WakeUp`,
  `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`, `resetMode`, `bootCount`, `reading_time`
)
SELECT
  `id`, `sensor`, `version`, `TempAirInt`, `TempAirExt`, `HumidAirInt`, `HumidAirExt`,
  `LuminositeA`, `LuminositeB`, `LuminositeC`, `LuminositeD`, `LuminositeMoy`,
  `ServoHB`, `ServoGD`, `HumidSol`, `Pluie`, `TempEau`, `PontDiv`, `WakeUp`,
  `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`, `resetMode`, `bootCount`, `reading_time`
FROM `iot_n3_import_staging`.`msp1DataTest`;

-- n3ppData : réordonner WakeUp (fin en prod -> position locale après PontDiv)
INSERT INTO `iot_n3_local`.`n3ppData` (
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `Luminosite`,
  `Humid1`, `Humid2`, `Humid3`, `Humid4`, `HumidMoy`, `PontDiv`, `WakeUp`,
  `ArrosageManu`, `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`,
  `HeureArrosage`, `resetMode`, `etatPompe`, `tempsArrosage`, `bootCount`, `reading_time`
)
SELECT
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `Luminosite`,
  `Humid1`, `Humid2`, `Humid3`, `Humid4`, `HumidMoy`, `PontDiv`, `WakeUp`,
  `ArrosageManu`, `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`,
  `HeureArrosage`, `resetMode`, `etatPompe`, `tempsArrosage`, `bootCount`, `reading_time`
FROM `iot_n3_import_staging`.`n3ppData`;

INSERT INTO `iot_n3_local`.`n3ppDataTest` (
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `Luminosite`,
  `Humid1`, `Humid2`, `Humid3`, `Humid4`, `HumidMoy`, `PontDiv`, `WakeUp`,
  `ArrosageManu`, `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`,
  `HeureArrosage`, `resetMode`, `etatPompe`, `tempsArrosage`, `bootCount`, `reading_time`
)
SELECT
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `Luminosite`,
  `Humid1`, `Humid2`, `Humid3`, `Humid4`, `HumidMoy`, `PontDiv`, `WakeUp`,
  `ArrosageManu`, `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`,
  `HeureArrosage`, `resetMode`, `etatPompe`, `tempsArrosage`, `bootCount`, `reading_time`
FROM `iot_n3_import_staging`.`n3ppDataTest`;

INSERT INTO `iot_n3_local`.`error_alerts`
SELECT * FROM `iot_n3_import_staging`.`error_alerts`;

-- Sorties : board numérique -> VARCHAR ; ignorer lignes board NULL (données corrompues) ; description locale = NULL
INSERT IGNORE INTO `iot_n3_local`.`ffp3Outputs` (`id`, `board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT `id`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `name`, `state`, NULL, `requestTime`, CONVERT(CAST(`lastModifiedBy` AS CHAR) USING utf8mb4)
FROM `iot_n3_import_staging`.`ffp3Outputs`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`ffp3Outputs2` (`id`, `board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT `id`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `name`, `state`, NULL, `requestTime`, CONVERT(CAST(`lastModifiedBy` AS CHAR) USING utf8mb4)
FROM `iot_n3_import_staging`.`ffp3Outputs2`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`ffp3Outputs3` (`id`, `board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT `id`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `name`, `state`, NULL, `requestTime`, CONVERT(CAST(`lastModifiedBy` AS CHAR) USING utf8mb4)
FROM `iot_n3_import_staging`.`ffp3Outputs3`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`ffp3OutputsS3` (`id`, `board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT `id`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `name`, `state`, NULL, `requestTime`, CONVERT(CAST(`lastModifiedBy` AS CHAR) USING utf8mb4)
FROM `iot_n3_import_staging`.`ffp3OutputsS3`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`ffp3OutputsS3Test` (`id`, `board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT `id`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `name`, `state`, NULL, `requestTime`, CONVERT(CAST(`lastModifiedBy` AS CHAR) USING utf8mb4)
FROM `iot_n3_import_staging`.`ffp3OutputsS3Test`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`msp1Outputs` (`id`, `name`, `board`, `gpio`, `state`, `requestTime`)
SELECT `id`, `name`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `state`, `requestTime`
FROM `iot_n3_import_staging`.`msp1Outputs`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`msp1OutputsTest` (`id`, `name`, `board`, `gpio`, `state`, `requestTime`)
SELECT `id`, `name`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `state`, `requestTime`
FROM `iot_n3_import_staging`.`msp1OutputsTest`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`n3ppOutputs` (`id`, `name`, `board`, `gpio`, `state`, `requestTime`)
SELECT `id`, `name`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `state`, `requestTime`
FROM `iot_n3_import_staging`.`n3ppOutputs`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`n3ppOutputsTest` (`id`, `name`, `board`, `gpio`, `state`, `requestTime`)
SELECT `id`, `name`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `state`, `requestTime`
FROM `iot_n3_import_staging`.`n3ppOutputsTest`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`UploadPhoto1Outputs` (`id`, `name`, `board`, `gpio`, `state`, `requestTime`)
SELECT `id`, `name`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `state`, `requestTime`
FROM `iot_n3_import_staging`.`UploadPhoto1Outputs`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`UploadPhoto2Outputs` (`id`, `name`, `board`, `gpio`, `state`, `requestTime`)
SELECT `id`, `name`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `state`, `requestTime`
FROM `iot_n3_import_staging`.`UploadPhoto2Outputs`
WHERE `board` IS NOT NULL;

INSERT IGNORE INTO `iot_n3_local`.`UploadPhoto3Outputs` (`id`, `name`, `board`, `gpio`, `state`, `requestTime`)
SELECT `id`, `name`, CONVERT(CAST(`board` AS CHAR) USING utf8mb4), `gpio`, `state`, `requestTime`
FROM `iot_n3_import_staging`.`UploadPhoto3Outputs`
WHERE `board` IS NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- AUTO_INCREMENT cohérent après insertions d'id explicites
SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3Data`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3Data` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3Data2`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3Data2` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3Data3`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3Data3` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3DataS3`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3DataS3` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3DataS3Test`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3DataS3Test` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3Heartbeat`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3Heartbeat` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3Heartbeat2`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3Heartbeat2` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3Heartbeat3`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3Heartbeat3` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3HeartbeatS3`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3HeartbeatS3` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3HeartbeatS3Test`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3HeartbeatS3Test` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`msp1Data`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`msp1Data` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`msp1DataTest`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`msp1DataTest` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`n3ppData`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`n3ppData` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`n3ppDataTest`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`n3ppDataTest` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`error_alerts`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`error_alerts` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3Outputs`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3Outputs` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3Outputs2`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3Outputs2` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3Outputs3`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3Outputs3` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3OutputsS3`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3OutputsS3` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`ffp3OutputsS3Test`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`ffp3OutputsS3Test` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`msp1Outputs`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`msp1Outputs` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`msp1OutputsTest`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`msp1OutputsTest` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`n3ppOutputs`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`n3ppOutputs` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`n3ppOutputsTest`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`n3ppOutputsTest` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`UploadPhoto1Outputs`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`UploadPhoto1Outputs` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`UploadPhoto2Outputs`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`UploadPhoto2Outputs` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;

SELECT COALESCE(MAX(`id`), 0) + 1 INTO @ai FROM `iot_n3_local`.`UploadPhoto3Outputs`;
SET @s := CONCAT('ALTER TABLE `iot_n3_local`.`UploadPhoto3Outputs` AUTO_INCREMENT = ', @ai);
PREPARE `_a` FROM @s; EXECUTE `_a`; DEALLOCATE PREPARE `_a`;
