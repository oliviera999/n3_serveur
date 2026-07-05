-- ============================================================================
-- 2026_07_PROD_03b — Niveau 1a N3PP (double-POST) en lots
-- ============================================================================
-- Contexte : la requête monolithique de 2026_07_PROD_03_prune_noise_data.sql
-- provoque #2006 "MySQL server has gone away" sur ~1,8 M lignes (phpMyAdmin /
-- timeout mémoire). Ce script remplace UNIQUEMENT le bloc 1a.
--
-- Prérequis :
--   - Niveaux 1b et 1c déjà exécutés (OK si relancés : idempotents).
--   - Backup prod.
--   - MySQL 8+ (fenêtres analytiques LEAD).
--
-- phpMyAdmin :
--   1) Exécuter la section A (création table) une fois.
--   2) Exécuter chaque bloc INSERT de la section B (un par un, attendre la fin).
--   3) Exécuter le bloc C en boucle jusqu'à SELECT COUNT(*) = 0 sur la table aide.
--   4) Exécuter 2026_07_PROD_03c_prune_level2.sql pour les niveaux 2a–2b.
--
-- CLI (recommandé si SSH disponible) :
--   mysql ... < migrations/2026_07_PROD_03b_prune_n3pp_double_post_batched.sql
-- ============================================================================

SET SESSION wait_timeout = 28800;
SET SESSION interactive_timeout = 28800;
SET SESSION net_read_timeout = 600;
SET SESSION net_write_timeout = 600;

-- --------------------------------------------------------------------------
-- A — Table de travail (ids à supprimer)
-- --------------------------------------------------------------------------
DROP TABLE IF EXISTS `_n3pp_dup_delete`;
CREATE TABLE `_n3pp_dup_delete` (
    `id` BIGINT NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- B — Collecte des doublons par fenêtres d'id (exécuter bloc par bloc)
--     Chaque INSERT IGNORE est idempotent.
-- --------------------------------------------------------------------------

-- B.01  id 1 .. 100000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 1 AND 100000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.02  id 100001 .. 200000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 100001 AND 200000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.03  id 200001 .. 300000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 200001 AND 300000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.04  id 300001 .. 400000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 300001 AND 400000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.05  id 400001 .. 500000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 400001 AND 500000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.06  id 500001 .. 600000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 500001 AND 600000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.07  id 600001 .. 700000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 600001 AND 700000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.08  id 700001 .. 800000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 700001 AND 800000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.09  id 800001 .. 900000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 800001 AND 900000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.10  id 900001 .. 1000000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 900001 AND 1000000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.11  id 1000001 .. 1100000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 1000001 AND 1100000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.12  id 1100001 .. 1200000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 1100001 AND 1200000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.13  id 1200001 .. 1300000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 1200001 AND 1300000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.14  id 1300001 .. 1400000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 1300001 AND 1400000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.15  id 1400001 .. 1500000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 1400001 AND 1500000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.16  id 1500001 .. 1600000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 1500001 AND 1600000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.17  id 1600001 .. 1700000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 1600001 AND 1700000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.18  id 1700001 .. 1800000
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` BETWEEN 1700001 AND 1800000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- B.19  id > 1800000 (queue)
INSERT IGNORE INTO `_n3pp_dup_delete` (`id`)
SELECT `dup_id` FROM (
    SELECT
        `id`,
        LEAD(`id`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_id`,
        `reading_time`,
        LEAD(`reading_time`) OVER (
            PARTITION BY `TempAir`, `Humidite`, `Luminosite`, `HumidMoy`, `etatPompe`, `sensor`, `version`
            ORDER BY `id`
        ) AS `dup_rt`
    FROM `n3ppData`
    WHERE `id` > 1800000
) AS `t`
WHERE `dup_id` IS NOT NULL
  AND `dup_rt` BETWEEN `reading_time` AND DATE_ADD(`reading_time`, INTERVAL 2 SECOND);

-- Contrôle avant DELETE
SELECT COUNT(*) AS `doublons_a_supprimer` FROM `_n3pp_dup_delete`;

-- --------------------------------------------------------------------------
-- C — Suppression par lots de 3000 (répéter ce bloc jusqu'à count = 0)
-- --------------------------------------------------------------------------
DELETE FROM `n3ppData`
WHERE `id` IN (
    SELECT `id` FROM (
        SELECT `id` FROM `_n3pp_dup_delete` ORDER BY `id` LIMIT 3000
    ) AS `batch`
);

DELETE FROM `_n3pp_dup_delete`
WHERE `id` IN (
    SELECT `id` FROM (
        SELECT `id` FROM `_n3pp_dup_delete` ORDER BY `id` LIMIT 3000
    ) AS `batch`
);

SELECT COUNT(*) AS `reste_a_supprimer` FROM `_n3pp_dup_delete`;

-- Quand reste_a_supprimer = 0 :
DROP TABLE IF EXISTS `_n3pp_dup_delete`;
