-- ============================================================================
-- 2026_07_PROD_05 — Index reading_time sur les tables data NON couvertes par 04
-- ============================================================================
-- Contexte :
--   2026_07_PROD_04_indexes.sql a posé `idx_*_reading_time` sur ffp3Data,
--   n3ppData et msp1Data uniquement. Les variantes actives (S3 prod/test et
--   tables de test/dev) restent sans index sur `reading_time`, alors qu'elles
--   sont balayées à chaque requête graphique / export / nettoyage CRON.
--
--   ⚠️ `ffp3DataS3` est de la PRODUCTION (S3) : c'est l'index le plus important
--   ici. Les autres tables listées sont les variantes actives déclarées dans
--   src/Config/TableConfig.php :
--     - ffp3DataS3      → env `s3`       (PROD S3)   [PRIORITAIRE]
--     - ffp3DataS3Test  → env `s3test`
--     - ffp3Data2       → env `test`
--     - ffp3Data3       → env `test3`
--     - n3ppDataTest    → env `n3pp_test`
--     - msp1DataTest    → env `msp_test`
--
-- Idempotence : chaque ajout passe par une procédure qui vérifie via
-- information_schema (1) que la table existe et (2) que l'index est absent,
-- avant d'émettre l'ALTER. Rejouer ce script est donc sans effet et sans
-- erreur, et une table absente dans un environnement donné est simplement
-- ignorée (aucun échec « table doesn't exist »).
-- ============================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS `__ffp3_add_reading_time_index` $$
CREATE PROCEDURE `__ffp3_add_reading_time_index`(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64)
)
BEGIN
    -- N'agit que si la table existe et que l'index n'est pas déjà présent.
    IF EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND INDEX_NAME = p_index
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (`reading_time`)'
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DELIMITER ;

-- FFP3 S3 (PRODUCTION) — prioritaire
CALL `__ffp3_add_reading_time_index`('ffp3DataS3',      'idx_ffp3_s3_reading_time');
CALL `__ffp3_add_reading_time_index`('ffp3DataS3Test',  'idx_ffp3_s3test_reading_time');

-- FFP3 tables de test / test3
CALL `__ffp3_add_reading_time_index`('ffp3Data2',       'idx_ffp3_test_reading_time');
CALL `__ffp3_add_reading_time_index`('ffp3Data3',       'idx_ffp3_test3_reading_time');

-- N3PP / MSP1 variantes de test
CALL `__ffp3_add_reading_time_index`('n3ppDataTest',    'idx_n3pp_test_reading_time');
CALL `__ffp3_add_reading_time_index`('msp1DataTest',    'idx_msp1_test_reading_time');

DROP PROCEDURE IF EXISTS `__ffp3_add_reading_time_index`;
