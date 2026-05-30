-- Heartbeats legacy msp1 / n3pp (Docker local — aligne prod audit 2026-05)
-- Voir migrations/CREATE_LEGACY_HEARTBEAT_TABLES.sql

CREATE TABLE IF NOT EXISTS msp1Heartbeat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uptime BIGINT NOT NULL,
    freeHeap INT NOT NULL,
    minHeap INT NOT NULL,
    reboots INT NOT NULL,
    rssi INT NULL,
    sensor VARCHAR(30) NULL,
    version VARCHAR(30) NULL,
    reading_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_msp1_heartbeat_time (reading_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS msp1HeartbeatTest LIKE msp1Heartbeat;
CREATE TABLE IF NOT EXISTS n3ppHeartbeatTest LIKE n3ppHeartbeat;
