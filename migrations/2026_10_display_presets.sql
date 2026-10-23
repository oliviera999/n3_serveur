-- Presets d'affichage des pages de suivi (MSP1, N3PP, ENERGIE) — serveur 6.41.0.
-- Un preset = ordre + visibilité des cadres, cartes et séries (layout_json),
-- appliqué au catalogue déclaratif de la page (src/Display/). Table globale
-- (presets partagés par prod et test d'une même famille), créée aussi à la volée
-- par DisplayPresetRepository si absente. Sans ligne, les presets intégrés
-- « simple » et « étendu » (code PHP) s'appliquent.
CREATE TABLE IF NOT EXISTS `displayPresets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_key` VARCHAR(32) NOT NULL,
    `slug` VARCHAR(40) NOT NULL,
    `label` VARCHAR(64) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `layout_json` TEXT NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `show_on_page` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 100,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` VARCHAR(64) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_page_slug` (`page_key`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
