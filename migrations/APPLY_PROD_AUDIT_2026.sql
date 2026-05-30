-- =============================================================================
-- APPLY_PROD_AUDIT_2026.sql — bundle migrations production oliviera_iot
-- =============================================================================
-- Serveur cible : iot.olution.info (base oliviera_iot)
-- Reference audit : dump oliviera_iot3.sql vs serveur 5.1.3
--
-- AVANT D'EXECUTER :
--   1. Exporter un backup complet via phpMyAdmin
--   2. Executer 00_diagnostic_prod.sql pour etat initial
--   3. Preferer une plage creuse pour ALTER sur ffp3Data (~1M lignes)
--
-- ORDRE (ce fichier enchaine les phases 1 a 4 du plan d'audit) :
--   Phase 1a : post_id (001)
--   Phase 1b : post_id S3 (001b)
--   Phase 1c : colonnes config FFP3 (ADD_MISSING_COLUMNS_v11.36)
--   Phase 2  : heartbeats msp/n3pp (CREATE_LEGACY_HEARTBEAT_TABLES)
--   Phase 3  : Poissonglouton (CREATE_PGL_TABLES)
--   Phase 4  : ffp3OtaTrigger (CREATE_FFP3_OTA_TRIGGER_TABLE)
--
-- NON INCLUS (conditionnel — executer separement si diagnostic le demande) :
--   FIX_DUPLICATE_GPIO_ROWS.sql + INIT_GPIO_BASE_ROWS.sql
--   002_add_tide_event_columns.sql (deja present sur prod au 2026-05)
--
-- Erreurs "Duplicate column" / "Duplicate key name" : migration deja partielle, continuer.
-- =============================================================================

-- =============================================================================
-- Phase 1a — post_id (ffp3Data, ffp3Data2, ffp3Data3, ffp3Data4)
-- =============================================================================

ALTER TABLE ffp3Data ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3Data ADD UNIQUE INDEX idx_post_id (post_id);

ALTER TABLE ffp3Data2 ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3Data2 ADD UNIQUE INDEX idx_post_id (post_id);

ALTER TABLE ffp3Data3 ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3Data3 ADD UNIQUE INDEX idx_post_id (post_id);

ALTER TABLE ffp3Data4 ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3Data4 ADD UNIQUE INDEX idx_post_id (post_id);

-- =============================================================================
-- Phase 1b — post_id S3
-- =============================================================================

ALTER TABLE ffp3DataS3 ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3DataS3 ADD UNIQUE INDEX idx_post_id (post_id);

ALTER TABLE ffp3DataS3Test ADD COLUMN post_id VARCHAR(64) DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3DataS3Test ADD UNIQUE INDEX idx_post_id (post_id);

-- =============================================================================
-- Phase 1c — colonnes config FFP3
-- =============================================================================

ALTER TABLE ffp3Data
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

ALTER TABLE ffp3Data2
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

ALTER TABLE ffp3Data3
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

ALTER TABLE ffp3Data4
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

ALTER TABLE ffp3DataS3
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

ALTER TABLE ffp3DataS3Test
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

-- =============================================================================
-- Phase 2 — heartbeats legacy msp / n3pp
-- =============================================================================

CREATE TABLE IF NOT EXISTS msp1Heartbeat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uptime BIGINT NOT NULL COMMENT 'Secondes depuis dernier boot',
    freeHeap INT NOT NULL COMMENT 'Free heap au moment du heartbeat (octets)',
    minHeap INT NOT NULL COMMENT 'Min free heap observe depuis boot (octets)',
    reboots INT NOT NULL COMMENT 'Compteur reboots cumules (bootCount)',
    rssi INT NULL COMMENT 'RSSI WiFi (dBm)',
    sensor VARCHAR(30) NULL,
    version VARCHAR(30) NULL,
    reading_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_msp1_heartbeat_time (reading_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS n3ppHeartbeat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uptime BIGINT NOT NULL,
    freeHeap INT NOT NULL,
    minHeap INT NOT NULL,
    reboots INT NOT NULL,
    rssi INT NULL,
    sensor VARCHAR(30) NULL,
    version VARCHAR(30) NULL,
    reading_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_n3pp_heartbeat_time (reading_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS msp1HeartbeatTest LIKE msp1Heartbeat;
CREATE TABLE IF NOT EXISTS n3ppHeartbeatTest LIKE n3ppHeartbeat;

-- =============================================================================
-- Phase 3 — Poissonglouton
-- POST-MIGRATION : remplacer secret_url_token par une valeur forte en prod.
-- UPDATE pglBoards SET secret_url_token = '...' WHERE board_id = 'poissonglouton';
-- =============================================================================

CREATE TABLE IF NOT EXISTS pglBoards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    board_id VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    location VARCHAR(255) NOT NULL,
    api_key_hash VARCHAR(255) DEFAULT NULL,
    secret_url_token VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pglEvents (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    board VARCHAR(64) NOT NULL,
    event_time DATETIME NOT NULL,
    count_delta INT NOT NULL DEFAULT 1,
    battery_v DECIMAL(5,2) DEFAULT NULL,
    fw_version VARCHAR(30) DEFAULT NULL,
    rssi INT DEFAULT NULL,
    sensor_mode ENUM('ir', 'us', 'tandem', 'unknown') NOT NULL DEFAULT 'unknown',
    is_tandem_validated TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pgl_event_time (event_time),
    INDEX idx_pgl_board (board)
);

INSERT IGNORE INTO pglBoards (board_id, label, location, secret_url_token)
VALUES ('poissonglouton', 'Poissonglouton principal', 'Salle aeree n3', 'a1b2c3d4e5f6a7b8');

-- =============================================================================
-- Phase 4 — trigger OTA FFP3 (optionnel : auto-cree aussi en serveur 5.1.3+)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `ffp3OtaTrigger` (
    `env` VARCHAR(32) NOT NULL,
    `pending` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`env`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fin — executer 99_validate_prod.sql pour controle.
