-- Migration : colonne sensors_present sur pglHeartbeat (supervision capteurs board)
-- Date : 2026-06-26
-- Idempotent : ADD COLUMN IF NOT EXISTS (MySQL 8+) ou ignorer erreur duplicate

ALTER TABLE pglHeartbeat
    ADD COLUMN sensors_present TINYINT UNSIGNED NULL
        COMMENT 'Bitmask PGL_SENS_* : 1=IR, 2=US, 4=PIR'
        AFTER version;
