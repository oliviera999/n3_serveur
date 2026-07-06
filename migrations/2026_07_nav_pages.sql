-- =====================================================================
-- Table `navPages` : pages visibles dans la barre de navigation.
--
-- Remplace l'ancien stockage localStorage (par navigateur) des toggles de
-- la page de supervision par un stockage SERVEUR GLOBAL : l'état est partagé
-- par tous les visiteurs (y compris non connectés) et persistant.
--
-- Table GLOBALE (pas de suffixe d'environnement, pas de TableConfig) : le menu
-- de navigation est commun à toutes les familles / tous les environnements.
--
-- Le repository App\Repository\NavPageRepository crée cette table à la volée si
-- elle est absente (CREATE TABLE IF NOT EXISTS + seed) ; cette migration reste
-- la source de vérité pour un déploiement explicite.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `navPages` (
    `page_key`   VARCHAR(64)  NOT NULL,
    `label`      VARCHAR(128) NOT NULL,
    `url`        VARCHAR(255) NOT NULL,
    `active`     TINYINT(1)   NOT NULL DEFAULT 0,
    `sort_order` INT          NOT NULL DEFAULT 1000,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` VARCHAR(64)  DEFAULT NULL,
    PRIMARY KEY (`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed : pages historiquement présentes en dur dans la barre de navigation.
-- Elles sont activées par défaut pour que le menu reste identique après déploiement.
INSERT INTO `navPages` (`page_key`, `label`, `url`, `active`, `sort_order`) VALUES
    ('home',      'Accueil',        '/',          1, 10),
    ('aquaponie', 'Aquaponie',      '/aquaponie', 1, 20),
    ('potager',   'Potager',        '/meteo',     1, 30),
    ('elevage',   'Élevage',        '/serre',     1, 40),
    ('pgl',       'Poissonglouton', '/pgl',       1, 50),
    ('gallery',   'Galeries',       '/gallery',   1, 60)
ON DUPLICATE KEY UPDATE `active` = VALUES(`active`);
