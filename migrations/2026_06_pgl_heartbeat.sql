-- Migration : table heartbeat Poissonglouton (supervision en ligne)
-- Date : 2026-06-02
-- Idempotent : CREATE TABLE IF NOT EXISTS

CREATE TABLE IF NOT EXISTS pglHeartbeat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uptime BIGINT NOT NULL COMMENT 'Secondes depuis dernier boot',
    freeHeap INT NOT NULL COMMENT 'Free heap au moment du heartbeat (octets)',
    minHeap INT NOT NULL COMMENT 'Min free heap observe depuis boot (octets)',
    reboots INT NOT NULL COMMENT 'Compteur reboots cumules (bootCount)',
    rssi INT NULL COMMENT 'RSSI WiFi (dBm)',
    sensor VARCHAR(30) NULL,
    version VARCHAR(30) NULL,
    reading_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pgl_heartbeat_time (reading_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
