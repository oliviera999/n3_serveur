-- Migration : creation des tables Heartbeat pour les firmwares legacy n3pp et msp.
-- Date : 2026-05 (Phase 4 audit firmwares n3pp/msp)
-- Description : ajoute le suivi heartbeat (uptime, free heap, min heap, reboots,
--               RSSI, version) recu par POST /msp1/heartbeat et /n3pp/heartbeat.
--               Aligne sur le contrat FFP3 (ffp3Heartbeat) mais avec auth HMAC
--               aligne sur API_SIG_SECRET (cf. .env.example).
--
-- Usage : executer sur la BDD prod. Les variantes Test ne sont creees que si
--         les tables msp1DataTest / n3ppDataTest existent (envs msp_test / n3pp_test).
--         Idempotent : utilise CREATE TABLE IF NOT EXISTS.

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

-- Variantes test (executer uniquement si les envs msp_test / n3pp_test sont utilises)
CREATE TABLE IF NOT EXISTS msp1HeartbeatTest LIKE msp1Heartbeat;
CREATE TABLE IF NOT EXISTS n3ppHeartbeatTest LIKE n3ppHeartbeat;
