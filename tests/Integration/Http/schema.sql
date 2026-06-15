-- ---------------------------------------------------------------------------
-- Schéma minimal pour les tests d'intégration HTTP du panneau de contrôle FFP3.
--
-- Couvre uniquement les tables exercées par les endpoints
--   GET  /api/outputs-test/state
--   POST /api/outputs-test/toggle
--   POST /api/outputs-test/parameters
-- en environnement ENV=test (tables suffixées « 2 »), afin de rester léger et
-- indépendant d'un dump de production.
--
-- Idempotent : DROP IF EXISTS + CREATE, rejouable à volonté entre runs.
-- Aligné sur :
--   * App\Config\TableConfig (ffp3Outputs2 / ffp3Data2 en env=test)
--   * App\Repository\OutputRepository (colonnes id, board, gpio, name, state,
--     requestTime, lastModifiedBy)
--   * App\Repository\BoardRepository (Boards.board, Boards.last_request)
--   * App\Service\OutputCacheService (ffp3OtaTrigger : env, pending)
-- ---------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `ffp3Outputs2`;
CREATE TABLE `ffp3Outputs2` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `board`          VARCHAR(32) NOT NULL DEFAULT '1',
    `gpio`           INT NOT NULL,
    `name`           VARCHAR(128) NULL,
    `state`          VARCHAR(32) NOT NULL DEFAULT '0',
    `requestTime`    DATETIME NULL DEFAULT NULL,
    `lastModifiedBy` VARCHAR(32) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_gpio` (`gpio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jeu de GPIO minimal mais représentatif (actionneurs + paramètres + horaires).
-- Les valeurs sont arbitraires mais valides ; les tests vérifient les variations.
INSERT INTO `ffp3Outputs2` (`board`, `gpio`, `name`, `state`) VALUES
    ('1', 2,   'Radiateur 1',                  '0'),
    ('1', 15,  'Lumiere',                      '0'),
    ('1', 16,  'Pompe aquarium',               '0'),
    ('1', 18,  'Pompe reserve',                '1'),
    ('1', 100, 'mail',                         'dev@example.local'),
    ('1', 101, 'Notifications',                '1'),
    ('1', 102, 'Seuil aquarium',               '18'),
    ('1', 103, 'Seuil reserve',                '80'),
    ('1', 104, 'Seuil chauffage',              '18'),
    ('1', 105, 'Heure nourrissage matin',      '8'),
    ('1', 106, 'Heure nourrissage midi',       '12'),
    ('1', 107, 'Heure nourrissage soir',       '19'),
    ('1', 108, 'Nourrissage petits poissons',  '0'),
    ('1', 109, 'Nourrissage gros poissons',    '0'),
    ('1', 110, 'reset',                        '0'),
    ('1', 111, 'Temps gros',                   '3'),
    ('1', 112, 'Temps petits',                 '2'),
    ('1', 113, 'Temps remplissage',            '120'),
    ('1', 114, 'Limite debordement',           '8'),
    ('1', 115, 'WakeUp',                       '0'),
    ('1', 116, 'FreqWakeUp',                   '600');

DROP TABLE IF EXISTS `ffp3Data2`;
CREATE TABLE `ffp3Data2` (
    `id`           INT NOT NULL AUTO_INCREMENT,
    `reading_time` DATETIME NOT NULL,
    `etatHeat`     INT NULL DEFAULT NULL,
    `etatUV`       INT NULL DEFAULT NULL,
    `etatPompeAqua` INT NULL DEFAULT NULL,
    `etatPompeTank` INT NULL DEFAULT NULL,
    `version`      VARCHAR(32) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_reading_time` (`reading_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ffp3Data2` (`reading_time`, `etatHeat`, `etatUV`, `etatPompeAqua`, `etatPompeTank`, `version`) VALUES
    ('2026-01-01 10:00:00', 0, 0, 0, 1, 'test-1.0.0');

DROP TABLE IF EXISTS `Boards`;
CREATE TABLE `Boards` (
    `board`        VARCHAR(32) NOT NULL,
    `last_request` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`board`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `Boards` (`board`, `last_request`) VALUES
    ('1', '2026-01-01 09:59:00');

DROP TABLE IF EXISTS `ffp3OtaTrigger`;
CREATE TABLE `ffp3OtaTrigger` (
    `env`        VARCHAR(32) NOT NULL,
    `pending`    TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`env`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
