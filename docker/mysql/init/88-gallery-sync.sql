-- Galeries photo — sessions de synchronisation (Docker local)
-- Voir migrations/CREATE_GALLERY_SYNC_SESSIONS_TABLE.sql

CREATE TABLE IF NOT EXISTS gallerySyncSessions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug             VARCHAR(16)     NOT NULL,
    board            INT             NOT NULL DEFAULT 0,
    device_session   VARCHAR(64)     NOT NULL DEFAULT '',
    firmware_version VARCHAR(64)     NOT NULL DEFAULT '',
    total            INT UNSIGNED    NOT NULL DEFAULT 0,
    received         INT UNSIGNED    NOT NULL DEFAULT 0,
    failed           INT UNSIGNED    NOT NULL DEFAULT 0,
    bytes_received   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status           ENUM('active','completed','aborted') NOT NULL DEFAULT 'active',
    started_at       DATETIME        NOT NULL,
    updated_at       DATETIME        NOT NULL,
    finished_at      DATETIME        NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug_device_session (slug, device_session),
    KEY idx_slug_status (slug, status),
    KEY idx_slug_updated (slug, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
