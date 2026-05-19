-- Poissonglouton - schema local Docker

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
