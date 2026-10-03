-- ============================================================================
-- Banc énergie INA226 — tables `energieData` / `energieDataTest` (serveur 6.40.0)
-- ============================================================================
-- Nouvelle famille « mesure seule » (pas de table outputs ni heartbeat) :
-- firmware n3_firmwires `energie/` (ESP32-S3 + 3 × INA226 : Panneau / Batterie /
-- Conso), mesure chaque seconde, POST agrégé toutes les 10 s sur
-- `/energie/post-data` (prod → energieData) ou `/energie-test/post-data`
-- (env `energie_test` → energieDataTest, banc de test actuel).
--
-- Unités : V, A, W ; EnergiePanneauWh / EnergieConsoWh = énergie (Wh) sur la
-- fenêtre de 10 s (delta) ; BatterieAh = compteur net cumulé (Ah) ; BatterieSoc
-- en % (0-100) ; Uptime en secondes. Courant batterie > 0 = charge, < 0 = décharge.
-- Toutes les colonnes de mesure sont NULLables (champ absent / non numérique).
-- Contrat complet : docs/API_ENERGIE.md.
--
-- Idempotent : CREATE TABLE IF NOT EXISTS + INSERT … ON DUPLICATE KEY UPDATE
-- (l'état actif/inactif courant du lien de menu est conservé s'il existe déjà).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `energieData` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `sensor`           VARCHAR(30) NULL,
    `version`          VARCHAR(30) NULL,
    `PanneauV`         DOUBLE NULL,
    `PanneauI`         DOUBLE NULL,
    `PanneauP`         DOUBLE NULL,
    `PanneauImax`      DOUBLE NULL,
    `BatterieV`        DOUBLE NULL,
    `BatterieVmin`     DOUBLE NULL,
    `BatterieI`        DOUBLE NULL,
    `BatterieImin`     DOUBLE NULL,
    `BatterieImax`     DOUBLE NULL,
    `BatterieP`        DOUBLE NULL,
    `BatterieVadc`     DOUBLE NULL,
    `ConsoV`           DOUBLE NULL,
    `ConsoI`           DOUBLE NULL,
    `ConsoP`           DOUBLE NULL,
    `ConsoImax`        DOUBLE NULL,
    `EnergiePanneauWh` DOUBLE NULL,
    `EnergieConsoWh`   DOUBLE NULL,
    `BatterieAh`       DOUBLE NULL,
    `BatterieSoc`      DOUBLE NULL,
    `InaStatus`        INT NULL,
    `I2cErreurs`       INT NULL,
    `Rssi`             INT NULL,
    `FreeHeap`         INT NULL,
    `BootCount`        INT NULL,
    `Uptime`           BIGINT NULL,
    `reading_time`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_energieData_reading_time` (`reading_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `energieDataTest` LIKE `energieData`;

-- Lien « Énergie (banc) » du menu de navigation (table globale `navPages`, cf.
-- 2026_07_nav_pages.sql) vers la page du banc de test (/energie-test) : la page
-- prod (/energie) reste vide tant que le module réel n'existe pas. Les deux liens
-- sont pilotables ensuite depuis la page de supervision.
-- Prérequis : table `navPages` présente (2026_07_nav_pages.sql, ou auto-créée par
-- App\Repository\NavPageRepository au premier affichage d'une page).
INSERT INTO `navPages` (`page_key`, `label`, `url`, `active`, `sort_order`) VALUES
    ('energie-test', 'Énergie (banc)', '/energie-test', 1, 55)
ON DUPLICATE KEY UPDATE `page_key` = `page_key`;

-- Vérification :
-- SHOW CREATE TABLE `energieData`;
-- SHOW CREATE TABLE `energieDataTest`;
-- SELECT * FROM `navPages` WHERE `page_key` = 'energie-test';
