-- Migration : ajout de device_event_id sur pglEvents pour l'idempotence offline-sync
-- Poissonglouton — firmware offline-first (SD journal)
-- À appliquer une seule fois sur la base de production.

-- 1. Ajout de la colonne device_event_id (nullable, NULL = ancien firmware sans event_id)
ALTER TABLE pglEvents
    ADD COLUMN IF NOT EXISTS device_event_id INT UNSIGNED DEFAULT NULL
        COMMENT 'Identifiant monotone assigne par le firmware (NULL = ancien firmware sans event_id)';

-- 2. Contrainte d'unicité (board + device_event_id) pour garantir l'idempotence
--    Les lignes avec device_event_id = NULL ne sont pas concernées par la contrainte UNIQUE.
ALTER TABLE pglEvents
    ADD CONSTRAINT uq_pgl_board_event_id UNIQUE (board, device_event_id);

-- Vérification
SELECT
    COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pglEvents'
  AND COLUMN_NAME = 'device_event_id';

SELECT
    CONSTRAINT_NAME, CONSTRAINT_TYPE
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pglEvents'
  AND CONSTRAINT_NAME = 'uq_pgl_board_event_id';
