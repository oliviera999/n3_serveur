-- =============================================================================
-- Validation post-migration — audit prod (rev. 2026-07-05)
-- =============================================================================
-- Lecture seule. Ne suppose aucune table obligatoire : compatible prod partielle
-- (ex. ffp3Data absente si migration S3, PGL non deploye, etc.).
--
-- Poissonglouton absent : executer migrations/CREATE_PGL_TABLES.sql
-- =============================================================================

-- --- Inventaire tables ffp3Data* presentes ---
SELECT TABLE_NAME, TABLE_ROWS AS approx_rows
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME LIKE 'ffp3Data%'
ORDER BY TABLE_NAME;

-- --- post_id sur les tables ffp3Data* existantes ---
SELECT c.TABLE_NAME, c.COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE()
  AND c.TABLE_NAME LIKE 'ffp3Data%'
  AND c.COLUMN_NAME = 'post_id'
ORDER BY c.TABLE_NAME;

-- --- Colonnes config FFP3 (toutes tables ffp3Data* existantes) ---
SELECT c.TABLE_NAME, GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.COLUMN_NAME) AS cols
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE()
  AND c.TABLE_NAME LIKE 'ffp3Data%'
  AND c.COLUMN_NAME IN (
    'tempsGros', 'tempsPetits', 'tempsRemplissageSec', 'limFlood',
    'WakeUp', 'FreqWakeUp', 'configSynced', 'Pression'
  )
GROUP BY c.TABLE_NAME
ORDER BY c.TABLE_NAME;

-- --- Heartbeats legacy ---
SELECT TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'msp1Heartbeat', 'n3ppHeartbeat',
    'msp1HeartbeatTest', 'n3ppHeartbeatTest'
  )
ORDER BY TABLE_NAME;

-- --- Poissonglouton (optionnel) ---
SELECT
    t.TABLE_NAME AS pgl_table,
    t.TABLE_ROWS AS approx_rows,
    CASE t.TABLE_NAME
        WHEN 'pglEvents' THEN (
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS c
            WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA
              AND c.TABLE_NAME = 'pglEvents'
              AND c.COLUMN_NAME = 'device_event_id'
        )
        WHEN 'pglHeartbeat' THEN (
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS c
            WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA
              AND c.TABLE_NAME = 'pglHeartbeat'
              AND c.COLUMN_NAME = 'sensors_present'
        )
        ELSE NULL
    END AS schema_check
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_SCHEMA = DATABASE()
  AND t.TABLE_NAME IN ('pglBoards', 'pglEvents', 'pglHeartbeat')
ORDER BY t.TABLE_NAME;

-- Attendu PGL deploye : 3 lignes ; schema_check = 1 pour colonnes juin 2026

-- --- OTA trigger (optionnel) ---
SELECT TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ffp3OtaTrigger';

-- --- Maree (colonnes tide* sur toutes tables ffp3Data* existantes) ---
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME LIKE 'ffp3Data%'
  AND COLUMN_NAME LIKE 'tide%'
ORDER BY TABLE_NAME, COLUMN_NAME;

-- --- Doublons GPIO ffp3Outputs (si table presente) ---
SET @gpio_out_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffp3Outputs'
);
SET @sql := IF(
    @gpio_out_exists > 0,
    'SELECT ''ffp3Outputs'' AS tbl, gpio, COUNT(*) AS nb FROM ffp3Outputs GROUP BY gpio HAVING nb > 1',
    'SELECT ''ffp3Outputs'' AS tbl, NULL AS gpio, 0 AS nb WHERE 0'
);
PREPARE stmt_gpio_out FROM @sql;
EXECUTE stmt_gpio_out;
DEALLOCATE PREPARE stmt_gpio_out;

-- --- Doublons GPIO ffp3Outputs2 (si table presente) ---
SET @gpio_out2_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffp3Outputs2'
);
SET @sql := IF(
    @gpio_out2_exists > 0,
    'SELECT ''ffp3Outputs2'' AS tbl, gpio, COUNT(*) AS nb FROM ffp3Outputs2 GROUP BY gpio HAVING nb > 1',
    'SELECT ''ffp3Outputs2'' AS tbl, NULL AS gpio, 0 AS nb WHERE 0'
);
PREPARE stmt_gpio_out2 FROM @sql;
EXECUTE stmt_gpio_out2;
DEALLOCATE PREPARE stmt_gpio_out2;

-- --- Dernieres lignes capteur FFP3 (premiere table data trouvee) ---
SET @data_tbl := (
    SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('ffp3Data2', 'ffp3Data', 'ffp3DataS3', 'ffp3Data3', 'ffp3Data4')
    ORDER BY FIELD(TABLE_NAME, 'ffp3Data2', 'ffp3Data', 'ffp3DataS3', 'ffp3Data3', 'ffp3Data4')
    LIMIT 1
);
SET @has_post_id := IF(@data_tbl IS NULL, 0, (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = @data_tbl
      AND COLUMN_NAME = 'post_id'
));
SET @has_tide := IF(@data_tbl IS NULL, 0, (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = @data_tbl
      AND COLUMN_NAME = 'tideEvent'
));
SET @sql := IF(
    @data_tbl IS NULL,
    'SELECT ''aucune_table_ffp3Data'' AS source_table, NULL AS id, NULL AS reading_time WHERE 0',
    CONCAT(
        'SELECT ''', @data_tbl, ''' AS source_table, id, reading_time',
        IF(@has_post_id > 0, ', post_id', ''),
        IF(@has_tide > 0, ', tideEvent', ''),
        ' FROM `', @data_tbl, '` ORDER BY reading_time DESC LIMIT 5'
    )
);
PREPARE stmt_latest FROM @sql;
EXECUTE stmt_latest;
DEALLOCATE PREPARE stmt_latest;

-- Attendu : au moins une table ffp3Data* dans l inventaire ; doublons GPIO = 0 ligne
