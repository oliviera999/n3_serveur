-- Migration: ajout colonne post_id pour déduplication des POSTs replay SD
-- À exécuter sur chaque table de données (ffp3Data, ffp3Data2, ffp3Data3, ffp3Data4)
-- La colonne est nullable : les anciens enregistrements restent sans post_id.
-- L'index UNIQUE permet la déduplication rapide et empêche les doublons au niveau BDD.

ALTER TABLE ffp3Data ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3Data ADD UNIQUE INDEX idx_post_id (post_id);

ALTER TABLE ffp3Data2 ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3Data2 ADD UNIQUE INDEX idx_post_id (post_id);

ALTER TABLE ffp3Data3 ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3Data3 ADD UNIQUE INDEX idx_post_id (post_id);

ALTER TABLE ffp3Data4 ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3Data4 ADD UNIQUE INDEX idx_post_id (post_id);
