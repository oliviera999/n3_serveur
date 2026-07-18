-- ============================================================================
-- 2026_07 — Contrainte d'unicité post_id (anti double-insert / TOCTOU)
-- ============================================================================
-- Ferme la fenêtre TOCTOU entre SensorRepository::existsByPostId() (SELECT) et
-- l'INSERT : deux requêtes concurrentes (ou un rejeu SD firmware) pouvaient
-- insérer deux fois le même post_id. L'index UNIQUE l'interdit au niveau BDD ;
-- côté serveur, SensorRepository::insert() traite désormais la violation
-- d'unicité (SQLSTATE 23000 / code MySQL 1062) comme un doublon idempotent
-- (skip silencieux) au lieu d'une erreur 500.
--
-- IDEMPOTENT : rejouable — ignorer l'erreur MySQL 1061 « Duplicate key name »
-- si l'index existe déjà (même convention que 2026_07_PROD_04_indexes.sql).
--
-- ⚠️ PRÉREQUIS — nettoyage des doublons préexistants :
--   ADD UNIQUE INDEX échoue (erreur 1062) s'il existe déjà des post_id en double.
--   Détecter puis purger AVANT d'appliquer, pour CHAQUE table ci-dessous, ex. :
--     SELECT post_id, COUNT(*) c FROM `ffp3Data`
--       WHERE post_id IS NOT NULL GROUP BY post_id HAVING c > 1;
--     -- suppression des doublons en conservant le plus petit id :
--     DELETE d1 FROM `ffp3Data` d1
--       JOIN `ffp3Data` d2 ON d1.post_id = d2.post_id AND d1.id > d2.id
--       WHERE d1.post_id IS NOT NULL;
--
-- NB : plusieurs lignes post_id NULL restent autorisées (un index UNIQUE MySQL
--      n'interdit pas les NULL multiples) — les lignes legacy sans post_id ne
--      sont donc pas impactées. La colonne post_id doit exister sur la table
--      (migration post_id préalable) ; sinon ignorer l'ALTER correspondant.
-- NB : s3 = PRODUCTION (ffp3DataS3) ; s3test = ffp3DataS3Test. Tables dérivées
--      de TableConfig::getDataTable() (prod/test/test3/s3/s3test).
-- ============================================================================

ALTER TABLE `ffp3Data`       ADD UNIQUE INDEX `uq_ffp3_post_id`        (`post_id`);
ALTER TABLE `ffp3Data2`      ADD UNIQUE INDEX `uq_ffp3_post_id_2`      (`post_id`);
ALTER TABLE `ffp3Data3`      ADD UNIQUE INDEX `uq_ffp3_post_id_3`      (`post_id`);
ALTER TABLE `ffp3DataS3`     ADD UNIQUE INDEX `uq_ffp3_post_id_s3`     (`post_id`);
ALTER TABLE `ffp3DataS3Test` ADD UNIQUE INDEX `uq_ffp3_post_id_s3test` (`post_id`);
