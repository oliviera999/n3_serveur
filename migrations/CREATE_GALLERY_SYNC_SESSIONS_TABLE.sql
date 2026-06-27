-- Sessions de synchronisation des galeries photo (uploadphotosserver : msp1 / n3pp / ffp3).
--
-- Renforce le mode hors-ligne des firmwares ESP32-CAM : la carte SD sert de file
-- d'attente locale. Quand le WiFi revient, le firmware ouvre une « session de sync »
-- en annoncant le nombre de photos en attente (total), pousse les photos manquantes
-- (en-tete HTTP X-Sync-Session sur upload.php) puis cloture la session.
--
-- Cote serveur, cette table alimente l'indicateur de connexion + la jauge de
-- progression (X/Y) sur la page de controle de galerie, et declenche le mail
-- recapitulatif de fin de transfert (NotificationService, categorie Camera).
--
-- Table unique, non prefixee par environnement (comme UploadPhotoNOutputs) :
-- le slug (msp1/n3pp/ffp3) discrimine la galerie.

CREATE TABLE IF NOT EXISTS gallerySyncSessions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug             VARCHAR(16)     NOT NULL,                 -- msp1 | n3pp | ffp3
    board            INT             NOT NULL DEFAULT 0,
    device_session   VARCHAR(64)     NOT NULL DEFAULT '',      -- identifiant de session genere par le firmware (idempotence)
    firmware_version VARCHAR(64)     NOT NULL DEFAULT '',
    total            INT UNSIGNED    NOT NULL DEFAULT 0,       -- photos en attente annoncees par le firmware
    received         INT UNSIGNED    NOT NULL DEFAULT 0,       -- photos effectivement recues (incrementees par upload.php)
    failed           INT UNSIGNED    NOT NULL DEFAULT 0,       -- echecs rapportes par le firmware a la cloture
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
