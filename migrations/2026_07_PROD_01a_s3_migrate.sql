-- ============================================================================
-- 2026_07_PROD_01a — Migration S3 Option A uniquement (prod avec ffp3Data4)
-- ============================================================================
-- NE PAS exécuter si la table ffp3Data4 n'existe pas (Docker seed local).
-- Prérequis : backup + vérifier COUNT(ffp3DataS3)=0 avant INSERT.
-- ============================================================================

INSERT INTO `ffp3DataS3` (
  `id`, `sensor`, `version`, `TempAir`, `Humidite`, `TempEau`, `EauPotager`, `EauAquarium`, `EauReserve`,
  `diffMaree`, `Luminosite`, `etatPompeAqua`, `etatPompeTank`, `etatHeat`, `etatUV`,
  `bouffeMatin`, `bouffeMidi`, `bouffePetits`, `bouffeGros`, `aqThreshold`, `tankThreshold`, `chauffageThreshold`,
  `mail`, `mailNotif`, `resetMode`, `bouffeSoir`, `post_id`, `reading_time`
)
SELECT
  d4.`id`, d4.`sensor`, d4.`version`, d4.`TempAir`, d4.`Humidite`, d4.`TempEau`, d4.`EauPotager`, d4.`EauAquarium`, d4.`EauReserve`,
  d4.`diffMaree`, d4.`Luminosite`, d4.`etatPompeAqua`, d4.`etatPompeTank`, d4.`etatHeat`, d4.`etatUV`,
  d4.`bouffeMatin`, d4.`bouffeMidi`, d4.`bouffePetits`, d4.`bouffeGros`, d4.`aqThreshold`, d4.`tankThreshold`, d4.`chauffageThreshold`,
  d4.`mail`, d4.`mailNotif`, d4.`resetMode`, d4.`bouffeSoir`, d4.`post_id`, d4.`reading_time`
FROM `ffp3Data4` d4
WHERE (SELECT COUNT(*) FROM `ffp3DataS3`) = 0
  AND (SELECT COUNT(*) FROM `ffp3Data4`) > 0;

INSERT INTO `ffp3OutputsS3` (`board`, `gpio`, `name`, `state`, `description`, `requestTime`, `lastModifiedBy`)
SELECT '5', o.`gpio`, o.`name`, o.`state`, o.`description`, NOW(), 'migration_2026_07'
FROM `ffp3Outputs4` o
WHERE NOT EXISTS (SELECT 1 FROM `ffp3OutputsS3` WHERE `board` = '5' LIMIT 1);

INSERT INTO `ffp3HeartbeatS3` (`uptime`, `freeHeap`, `minHeap`, `reboots`, `rssi`, `sensor`, `version`, `reading_time`)
SELECT h.`uptime`, h.`freeHeap`, h.`minHeap`, h.`reboots`, h.`rssi`, h.`sensor`, h.`version`, h.`reading_time`
FROM `ffp3Heartbeat4` h
WHERE NOT EXISTS (SELECT 1 FROM `ffp3HeartbeatS3` LIMIT 1);
