-- =============================================================================
-- Validation post-migration — audit prod 2026-05
-- =============================================================================
-- Executer apres APPLY_PROD_AUDIT_2026.sql (ou scripts individuels).
-- Toutes les requetes ci-dessous doivent confirmer la presence des objets.
-- =============================================================================

-- --- post_id sur les 6 tables ffp3Data* ---
SELECT TABLE_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'ffp3Data', 'ffp3Data2', 'ffp3Data3', 'ffp3Data4',
    'ffp3DataS3', 'ffp3DataS3Test'
  )
  AND COLUMN_NAME = 'post_id'
ORDER BY TABLE_NAME;

-- Attendu : 6 lignes

-- --- Colonnes config FFP3 ---
SELECT TABLE_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) AS cols
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ffp3Data2'
  AND COLUMN_NAME IN (
    'tempsGros', 'tempsPetits', 'tempsRemplissageSec', 'limFlood',
    'WakeUp', 'FreqWakeUp', 'configSynced', 'Pression'
  )
GROUP BY TABLE_NAME;

-- Attendu : 8 colonnes listees pour ffp3Data2 (repeter SHOW COLUMNS sur ffp3Data prod)

-- --- Heartbeats legacy ---
SHOW TABLES LIKE 'msp1Heartbeat';
SHOW TABLES LIKE 'n3ppHeartbeat';
SHOW TABLES LIKE 'msp1HeartbeatTest';
SHOW TABLES LIKE 'n3ppHeartbeatTest';

-- --- Poissonglouton ---
SHOW TABLES LIKE 'pglBoards';
SHOW TABLES LIKE 'pglEvents';
SELECT board_id, label FROM pglBoards LIMIT 5;

-- --- OTA trigger (optionnel si phase 4 appliquee) ---
SHOW TABLES LIKE 'ffp3OtaTrigger';

-- --- Maree (souvent deja OK avant migration) ---
SHOW COLUMNS FROM ffp3Data LIKE 'tide%';

-- --- Doublons GPIO (doit etre vide apres FIX si applique) ---
SELECT gpio, COUNT(*) AS nb FROM ffp3Outputs GROUP BY gpio HAVING nb > 1;
SELECT gpio, COUNT(*) AS nb FROM ffp3Outputs2 GROUP BY gpio HAVING nb > 1;

-- --- Dernieres lignes capteur (post-migration firmware actif) ---
SELECT id, post_id, WakeUp, Pression, tideEvent, reading_time
FROM ffp3Data2
ORDER BY reading_time DESC
LIMIT 5;
