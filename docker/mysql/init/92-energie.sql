-- Banc énergie INA226 (panneau / batterie / conso) - schema local Docker
-- Voir migrations/2026_10_energie_tables.sql (source de vérité) et docs/API_ENERGIE.md.
-- Le lien de menu `navPages` n'est pas inséré ici : la table navPages est créée et
-- semée à la volée par App\Repository\NavPageRepository (SEED, clé `energie`).

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
