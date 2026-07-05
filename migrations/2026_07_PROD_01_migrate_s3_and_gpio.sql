-- ============================================================================
-- 2026_07_PROD_01 — Alignement GPIO prod + notifs (juillet 2026)
-- ============================================================================
-- Migration S3 (ffp3Data4 → ffp3DataS3) : fichier séparé 2026_07_PROD_01a_s3_migrate.sql
-- (uniquement si ffp3Data4 existe — prod oliviera_iot).
-- Suite : 2026_07_PROD_02_drop_orphan_tables.sql puis 03_prune_noise_data.sql
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. GPIO N3PP — pompe 12, arrosage 13
-- ---------------------------------------------------------------------------

UPDATE `n3ppOutputs`
SET `gpio` = 12, `name` = 'Pompe irrigation'
WHERE `board` = '3' AND `gpio` = 2
  AND NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `n3ppOutputs` WHERE `board` = '3' AND `gpio` = 12) AS `_has12`);

UPDATE `n3ppOutputs` dst
INNER JOIN `n3ppOutputs` src ON src.`board` = '3' AND src.`gpio` = 2
SET dst.`state` = CASE WHEN src.`state` IN ('1', '1.00') THEN '1' ELSE dst.`state` END
WHERE dst.`board` = '3' AND dst.`gpio` = 12;

DELETE FROM `n3ppOutputs`
WHERE `board` = '3' AND `gpio` = 2
  AND EXISTS (SELECT 1 FROM (SELECT `id` FROM `n3ppOutputs` WHERE `board` = '3' AND `gpio` = 12) AS `_has12`);

INSERT INTO `n3ppOutputs` (`board`, `gpio`, `name`, `state`, `requestTime`)
SELECT '3', 13, 'Arrosage manuel', '0', NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `n3ppOutputs` WHERE `board` = '3' AND `gpio` = 13);

UPDATE `n3ppOutputs` SET `name` = 'Pompe irrigation' WHERE `board` = '3' AND `gpio` = 12;
UPDATE `n3ppOutputs` SET `name` = 'Arrosage manuel' WHERE `board` = '3' AND `gpio` = 13;

DELETE FROM `n3ppOutputs`
WHERE `board` = '3' AND `gpio` = 109 AND (`name` LIKE '%Arrosage%' OR `name` = 'Arrosage manuel');

UPDATE `n3ppOutputsTest`
SET `gpio` = 12, `name` = 'Pompe irrigation'
WHERE `board` = '3' AND `gpio` = 2
  AND NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `n3ppOutputsTest` WHERE `board` = '3' AND `gpio` = 12) AS `_has12`);

DELETE FROM `n3ppOutputsTest`
WHERE `board` = '3' AND `gpio` = 2
  AND EXISTS (SELECT 1 FROM (SELECT `id` FROM `n3ppOutputsTest` WHERE `board` = '3' AND `gpio` = 12) AS `_has12`);

INSERT INTO `n3ppOutputsTest` (`board`, `gpio`, `name`, `state`, `requestTime`)
SELECT '3', 13, 'Arrosage manuel', '0', NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `n3ppOutputsTest` WHERE `board` = '3' AND `gpio` = 13);

DELETE FROM `n3ppOutputsTest`
WHERE `board` = '3' AND `gpio` = 109 AND (`name` LIKE '%Arrosage%' OR `name` = 'Arrosage manuel');

-- ---------------------------------------------------------------------------
-- 3. GPIO MSP — sans pompe (GPIO 2), GPIO 111 = ServoModeAuto
-- ---------------------------------------------------------------------------

DELETE FROM `msp1Outputs` WHERE `board` = '2' AND `gpio` = 2;
DELETE FROM `msp1OutputsTest` WHERE `board` = '2' AND `gpio` = 2;

UPDATE `msp1Outputs`
SET `name` = 'ServoModeAuto', `state` = COALESCE(NULLIF(`state`, ''), '1')
WHERE `board` = '2' AND `gpio` = 111;

UPDATE `msp1OutputsTest`
SET `name` = 'ServoModeAuto'
WHERE `board` = '2' AND `gpio` = 111;

DELETE FROM `msp1Outputs`
WHERE `board` = '2' AND `gpio` = 109 AND (`name` LIKE '%Arrosage%' OR `name` = 'Arrosage manuel');

DELETE FROM `msp1OutputsTest`
WHERE `board` = '2' AND `gpio` = 109 AND (`name` LIKE '%Arrosage%' OR `name` = 'Arrosage manuel');

INSERT INTO `msp1Outputs` (`board`, `gpio`, `name`, `state`, `requestTime`)
SELECT '2', 110, 'resetMode', '0', NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `msp1Outputs` WHERE `board` = '2' AND `gpio` = 110);

INSERT INTO `msp1OutputsTest` (`board`, `gpio`, `name`, `state`, `requestTime`)
SELECT '2', 110, 'resetMode', '0', NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `msp1OutputsTest` WHERE `board` = '2' AND `gpio` = 110);

-- ---------------------------------------------------------------------------
-- 4. Fantômes ffp3Outputs3 gpio 16 NULL (test3)
-- ---------------------------------------------------------------------------

DELETE FROM `ffp3Outputs3`
WHERE `gpio` = 16 AND (`name` IS NULL OR `name` = '');

-- ---------------------------------------------------------------------------
-- 5. Notifications Option B — aligner GPIO 101 depuis GPIO 108
-- ---------------------------------------------------------------------------

UPDATE `n3ppOutputs` dst
INNER JOIN `n3ppOutputs` src ON src.`board` = dst.`board` AND src.`gpio` = 108
SET dst.`state` = src.`state`
WHERE dst.`gpio` = 101 AND dst.`board` = '3'
  AND src.`state` IN ('important', 'partial', 'full', 'none');

UPDATE `msp1Outputs` dst
INNER JOIN `msp1Outputs` src ON src.`board` = dst.`board` AND src.`gpio` = 108
SET dst.`state` = src.`state`
WHERE dst.`gpio` = 101 AND dst.`board` = '2'
  AND src.`state` IN ('important', 'partial', 'full', 'none');

UPDATE `n3ppOutputsTest` dst
INNER JOIN `n3ppOutputsTest` src ON src.`board` = dst.`board` AND src.`gpio` = 108
SET dst.`state` = src.`state`
WHERE dst.`gpio` = 101 AND dst.`board` = '3'
  AND src.`state` IN ('important', 'partial', 'full', 'none');

UPDATE `msp1OutputsTest` dst
INNER JOIN `msp1OutputsTest` src ON src.`board` = dst.`board` AND src.`gpio` = 108
SET dst.`state` = src.`state`
WHERE dst.`gpio` = 101 AND dst.`board` = '2'
  AND src.`state` IN ('important', 'partial', 'full', 'none');
