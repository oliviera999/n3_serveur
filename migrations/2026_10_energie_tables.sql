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
-- Idempotent : CREATE TABLE IF NOT EXISTS uniquement — fichier ADDITIF, donc appliqué
-- seul par le CRON (AutoMigrateCommand) ou via l'outil MCP iot_migrate.
--
-- Serveur 6.62.0 : l'`INSERT … ON DUPLICATE KEY UPDATE` du lien de menu `navPages`
-- (« Énergie (banc) » → /energie-test) a été RETIRÉ de ce fichier. Il le rendait « non
-- additif » : AutoMigrate l'ignorait, les deux tables n'ont jamais été créées en prod et
-- `GET /energie` répondait 500 (« Table 'energieData' doesn't exist », 14/11/2026). Le lien
-- est déjà semé (inactif ou actif selon la page de supervision) par
-- App\Repository\NavPageRepository::SEED à la création de `navPages` ; il se pilote depuis
-- /supervision (bascule « Afficher dans le menu »).
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

-- Vérification :
-- SHOW CREATE TABLE `energieData`;
-- SHOW CREATE TABLE `energieDataTest`;
