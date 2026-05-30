-- Migration: post_id sur tables ESP32-S3 (extension de 001_add_post_id.sql)
-- Dedup replay SD ffp5cs environnements s3 / s3test.
-- Idempotent partiel : ignorer erreur "Duplicate column name" si deja applique.

ALTER TABLE ffp3DataS3
    ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;

ALTER TABLE ffp3DataS3
    ADD UNIQUE INDEX idx_post_id (post_id);

ALTER TABLE ffp3DataS3Test
    ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;

ALTER TABLE ffp3DataS3Test
    ADD UNIQUE INDEX idx_post_id (post_id);
