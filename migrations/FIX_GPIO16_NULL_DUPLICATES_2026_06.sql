-- ============================================================================
-- Migration : Nettoyage des lignes fantomes / doublons GPIO dans ffp3Outputs*
-- Date      : 2026-06-24
-- Version   : 5.3.11
-- ----------------------------------------------------------------------------
-- CONTEXTE
--   La prod `oliviera_iot` presente ~403 lignes fantomes gpio=16
--   (name=NULL, board=NULL, state='1'), toutes datees du 2025-10-16 15:13:29.
--   Cause racine : l'ancien `PumpService` ecrivait via
--   `INSERT ... ON DUPLICATE KEY UPDATE`, mais la table prod n'a AUCUNE
--   contrainte UNIQUE (seulement PRIMARY KEY(id)). Sans cle UPSERT ne pouvait
--   matcher aucune cle -> chaque ecriture pompe creait une nouvelle ligne.
--   Le correctif v11.38 (PumpService passe en UPDATE seul) a stoppe la fuite ;
--   ces lignes residuelles n'ont jamais ete purgees (les migrations
--   CLEAN_NULL_OUTPUTS_v11.38 / FIX_DUPLICATE_GPIO_ROWS n'ont pas ete appliquees).
--
-- CE SCRIPT (par table)
--   1. sauvegarde la table (backup horodate, idempotent) ;
--   2. supprime les lignes sans nom (fantomes) ;
--   3. deduplique : conserve 1 ligne par gpio (la plus recente, tie-break id min) ;
--   4. ajoute une contrainte UNIQUE(gpio) pour empecher toute recidive.
--
-- IDEMPOTENT : re-executable sans dommage.
-- CIBLE       : MariaDB (>= 10.0.2 pour `ADD UNIQUE KEY IF NOT EXISTS`).
--               Prod observee : MariaDB 11.4.12.
-- USAGE       : phpMyAdmin sur la base `oliviera_iot`. Conserver uniquement les
--               blocs des tables existantes dans votre environnement.
-- ============================================================================

USE `oliviera_iot`;

-- ---------------------------------------------------------------------------
-- (Optionnel) Diagnostic avant nettoyage : compter les doublons par table
-- ---------------------------------------------------------------------------
-- SELECT gpio, COUNT(*) AS nb FROM `ffp3Outputs`  GROUP BY gpio HAVING COUNT(*) > 1;
-- SELECT gpio, COUNT(*) AS nb FROM `ffp3Outputs2` GROUP BY gpio HAVING COUNT(*) > 1;

-- ===========================================================================
-- TABLE PROD : ffp3Outputs
-- ===========================================================================

-- 1) Sauvegarde (conserve la 1re sauvegarde si le script est relance)
CREATE TABLE IF NOT EXISTS `ffp3Outputs_backup_20260624` AS SELECT * FROM `ffp3Outputs`;

-- 2) Supprimer les lignes fantomes (sans nom) -> les ~403 doublons gpio 16
DELETE FROM `ffp3Outputs` WHERE `name` IS NULL OR `name` = '';

-- 3) Dedupliquer : garder la ligne la plus recente par gpio (tie-break id min)
DELETE t1 FROM `ffp3Outputs` t1
JOIN `ffp3Outputs` t2
  ON t1.`gpio` = t2.`gpio`
 AND ( t2.`requestTime` > t1.`requestTime`
    OR (t2.`requestTime` = t1.`requestTime` AND t2.`id` < t1.`id`) )
WHERE t1.`gpio` IS NOT NULL;

-- 4) Empecher toute recidive : contrainte UNIQUE sur gpio
ALTER TABLE `ffp3Outputs` ADD UNIQUE KEY IF NOT EXISTS `uq_ffp3Outputs_gpio` (`gpio`);

-- ===========================================================================
-- TABLE TEST : ffp3Outputs2  (retirer ce bloc si la table n'existe pas)
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `ffp3Outputs2_backup_20260624` AS SELECT * FROM `ffp3Outputs2`;

DELETE FROM `ffp3Outputs2` WHERE `name` IS NULL OR `name` = '';

DELETE t1 FROM `ffp3Outputs2` t1
JOIN `ffp3Outputs2` t2
  ON t1.`gpio` = t2.`gpio`
 AND ( t2.`requestTime` > t1.`requestTime`
    OR (t2.`requestTime` = t1.`requestTime` AND t2.`id` < t1.`id`) )
WHERE t1.`gpio` IS NOT NULL;

ALTER TABLE `ffp3Outputs2` ADD UNIQUE KEY IF NOT EXISTS `uq_ffp3Outputs2_gpio` (`gpio`);

-- ===========================================================================
-- AUTRES ENVIRONNEMENTS (decommenter si les tables existent)
-- ffp3Outputs3 (test3), ffp3OutputsS3 (s3), ffp3OutputsS3Test (s3test)
-- Memes 4 etapes ; adapter les noms de table + de backup + de cle unique.
-- ===========================================================================
-- CREATE TABLE IF NOT EXISTS `ffp3Outputs3_backup_20260624` AS SELECT * FROM `ffp3Outputs3`;
-- DELETE FROM `ffp3Outputs3` WHERE `name` IS NULL OR `name` = '';
-- DELETE t1 FROM `ffp3Outputs3` t1 JOIN `ffp3Outputs3` t2
--   ON t1.`gpio` = t2.`gpio`
--  AND ( t2.`requestTime` > t1.`requestTime`
--     OR (t2.`requestTime` = t1.`requestTime` AND t2.`id` < t1.`id`) )
-- WHERE t1.`gpio` IS NOT NULL;
-- ALTER TABLE `ffp3Outputs3` ADD UNIQUE KEY IF NOT EXISTS `uq_ffp3Outputs3_gpio` (`gpio`);

-- ===========================================================================
-- VERIFICATION POST-NETTOYAGE
-- ===========================================================================
SELECT 'ffp3Outputs'  AS tbl, COUNT(*) AS total_rows, COUNT(DISTINCT `gpio`) AS unique_gpios FROM `ffp3Outputs`
UNION ALL
SELECT 'ffp3Outputs2' AS tbl, COUNT(*) AS total_rows, COUNT(DISTINCT `gpio`) AS unique_gpios FROM `ffp3Outputs2`;

-- Doit afficher total_rows == unique_gpios (plus aucun doublon).
-- En cas de probleme, restaurer :
--   DROP TABLE `ffp3Outputs`; RENAME TABLE `ffp3Outputs_backup_20260624` TO `ffp3Outputs`;
-- ============================================================================
