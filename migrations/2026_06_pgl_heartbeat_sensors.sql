-- Migration : colonne sensors_present sur pglHeartbeat (supervision capteurs board)
-- Date : 2026-06-26
-- Compatible MySQL 8.0 (sans ADD COLUMN IF NOT EXISTS).

SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pglHeartbeat'
      AND COLUMN_NAME = 'sensors_present'
);
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE pglHeartbeat ADD COLUMN sensors_present TINYINT UNSIGNED NULL COMMENT ''Bitmask PGL_SENS_* : 1=IR, 2=US, 4=PIR'' AFTER version',
    'SELECT ''sensors_present deja present'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
