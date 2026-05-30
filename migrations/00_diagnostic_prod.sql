-- =============================================================================
-- Diagnostic BDD production — audit 2026-05 (oliviera_iot)
-- =============================================================================
-- Usage phpMyAdmin : selectionner la base oliviera_iot, onglet SQL, executer.
-- Aucune modification : requetes SELECT / SHOW uniquement.
-- =============================================================================

-- 1. Colonnes FFP3 attendues par SensorRepository (post_id, config, maree)
SELECT TABLE_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME LIKE 'ffp3Data%'
  AND TABLE_NAME NOT LIKE '%Del%'
  AND COLUMN_NAME IN (
    'post_id', 'tempsGros', 'tempsPetits', 'tempsRemplissageSec', 'limFlood',
    'WakeUp', 'FreqWakeUp', 'configSynced', 'Pression', 'tideEvent'
  )
ORDER BY TABLE_NAME, COLUMN_NAME;

-- 2. Tables heartbeat legacy msp / n3pp
SHOW TABLES LIKE 'msp1Heartbeat%';
SHOW TABLES LIKE 'n3ppHeartbeat%';

-- 3. Poissonglouton
SHOW TABLES LIKE 'pgl%';

-- 4. Trigger OTA distant FFP3
SHOW TABLES LIKE 'ffp3OtaTrigger';

-- 5. Doublons GPIO (prod + test)
SELECT 'ffp3Outputs' AS tbl, gpio, COUNT(*) AS nb
FROM ffp3Outputs
GROUP BY gpio
HAVING nb > 1;

SELECT 'ffp3Outputs2' AS tbl, gpio, COUNT(*) AS nb
FROM ffp3Outputs2
GROUP BY gpio
HAVING nb > 1;

-- 6. lastModifiedBy (deja migre sur prod typiquement)
SELECT TABLE_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME LIKE 'ffp3Outputs%'
  AND COLUMN_NAME = 'lastModifiedBy';

-- 7. Colonnes maree tide* (audit 2026-05 : souvent deja presentes en prod)
SELECT TABLE_NAME, COUNT(*) AS nb_tide_cols
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME LIKE 'ffp3Data%'
  AND TABLE_NAME NOT LIKE '%Del%'
  AND COLUMN_NAME LIKE 'tide%'
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;
