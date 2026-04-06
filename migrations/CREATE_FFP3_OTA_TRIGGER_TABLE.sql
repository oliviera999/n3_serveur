-- Demande OTA distante (bouton « Vérifier OTA ») : persistance inter-workers PHP-FPM.
-- Une ligne par environnement FFP3 (prod, test, test3, s3, s3test).
-- À exécuter une fois sur la base MySQL de production (et sur les stacks locales qui utilisent ce flux).

CREATE TABLE IF NOT EXISTS `ffp3OtaTrigger` (
    `env` VARCHAR(32) NOT NULL,
    `pending` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`env`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
