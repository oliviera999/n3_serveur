-- Migration : ajout de device_event_id sur pglEvents pour l'idempotence offline-sync
-- Poissonglouton — firmware offline-first (SD journal)
-- Compatible MySQL 8.0 (sans ADD COLUMN IF NOT EXISTS).

SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pglEvents'
      AND COLUMN_NAME = 'device_event_id'
);
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE pglEvents ADD COLUMN device_event_id INT UNSIGNED DEFAULT NULL COMMENT ''Identifiant monotone assigne par le firmware (NULL = ancien firmware sans event_id)''',
    'SELECT ''device_event_id deja present'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pglEvents'
      AND CONSTRAINT_NAME = 'uq_pgl_board_event_id'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE pglEvents ADD CONSTRAINT uq_pgl_board_event_id UNIQUE (board, device_event_id)',
    'SELECT ''uq_pgl_board_event_id deja present'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
