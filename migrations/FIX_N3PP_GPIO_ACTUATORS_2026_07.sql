-- ============================================================================
-- Migration : alignement GPIO actionneurs N3PP (board 3) sur le contrat firmware
-- Date      : 2026-07-05
-- ----------------------------------------------------------------------------
-- CONTEXTE
--   Le firmware n3pp lit la pompe via la clé JSON "12" (POMPE=12) et l'arrosage
--   manuel via "13" (RELAIS=13). L'UI serveur utilisait GPIO 2/15/16 (legacy FFP3).
--   Résultat : toggle UI OK en BDD mais aucun effet sur l'ESP32, ou 400 si GPIO 12
--   en prod sans whitelist serveur.
--
-- CE SCRIPT (par table n3ppOutputs / n3ppOutputsTest)
--   1. Déplace gpio=2 → gpio=12 (pompe) si gpio=12 absent ;
--   2. Fusionne puis supprime gpio=2 si les deux coexistent ;
--   3. Insère gpio=13 (arrosage manuel one-shot) s'il manque ;
--   4. Conserve gpio=15/16 (legacy UI, non lus par le firmware actuel).
--
-- IDEMPOTENT : re-exécutable sans dommage.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- PROD : n3ppOutputs
-- ---------------------------------------------------------------------------

UPDATE `n3ppOutputs`
SET `gpio` = 12, `name` = 'Pompe irrigation'
WHERE `board` = '3' AND `gpio` = 2
  AND NOT EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `n3ppOutputs` WHERE `board` = '3' AND `gpio` = 12) AS `_has12`
  );

UPDATE `n3ppOutputs` dst
INNER JOIN `n3ppOutputs` src ON src.`board` = '3' AND src.`gpio` = 2
SET dst.`state` = CASE WHEN src.`state` IN ('1', '1.00') THEN '1' ELSE dst.`state` END
WHERE dst.`board` = '3' AND dst.`gpio` = 12;

DELETE FROM `n3ppOutputs`
WHERE `board` = '3' AND `gpio` = 2
  AND EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `n3ppOutputs` WHERE `board` = '3' AND `gpio` = 12) AS `_has12`
  );

INSERT INTO `n3ppOutputs` (`board`, `gpio`, `name`, `state`, `requestTime`)
SELECT '3', 13, 'Arrosage manuel', '0', NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `n3ppOutputs` WHERE `board` = '3' AND `gpio` = 13
);

UPDATE `n3ppOutputs` SET `name` = 'Pompe irrigation' WHERE `board` = '3' AND `gpio` = 12;
UPDATE `n3ppOutputs` SET `name` = 'Arrosage manuel' WHERE `board` = '3' AND `gpio` = 13;

-- ---------------------------------------------------------------------------
-- TEST : n3ppOutputsTest
-- ---------------------------------------------------------------------------

UPDATE `n3ppOutputsTest`
SET `gpio` = 12, `name` = 'Pompe irrigation'
WHERE `board` = '3' AND `gpio` = 2
  AND NOT EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `n3ppOutputsTest` WHERE `board` = '3' AND `gpio` = 12) AS `_has12`
  );

UPDATE `n3ppOutputsTest` dst
INNER JOIN `n3ppOutputsTest` src ON src.`board` = '3' AND src.`gpio` = 2
SET dst.`state` = CASE WHEN src.`state` IN ('1', '1.00') THEN '1' ELSE dst.`state` END
WHERE dst.`board` = '3' AND dst.`gpio` = 12;

DELETE FROM `n3ppOutputsTest`
WHERE `board` = '3' AND `gpio` = 2
  AND EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `n3ppOutputsTest` WHERE `board` = '3' AND `gpio` = 12) AS `_has12`
  );

INSERT INTO `n3ppOutputsTest` (`board`, `gpio`, `name`, `state`, `requestTime`)
SELECT '3', 13, 'Arrosage manuel', '0', NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `n3ppOutputsTest` WHERE `board` = '3' AND `gpio` = 13
);

UPDATE `n3ppOutputsTest` SET `name` = 'Pompe irrigation' WHERE `board` = '3' AND `gpio` = 12;
UPDATE `n3ppOutputsTest` SET `name` = 'Arrosage manuel' WHERE `board` = '3' AND `gpio` = 13;
