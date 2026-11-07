-- Migration : diagnostic des pertes de contact des modules IoT (serveur 6.59.0).
-- Contexte : docs/DIAGNOSTIC_FFP3_PERTE_CONTACT_2026-11-06.md — le 06/11/2026 le WROOM FFP3 s'est
-- tu à 16:40 juste après la publication OTA 15.33 et il était impossible de prouver la cause :
-- téléchargements OTA non journalisés, rejets HMAC sans trace structurée, horloge du firmware
-- en avance d'un cycle de veille à chaque réveil, rétablissement annoncé à tort.
--
-- ADDITIVE UNIQUEMENT : CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS (MariaDB).
-- Rétrocompatible : tant qu'elle n'est pas appliquée, les enregistreurs créent eux-mêmes les
-- tables (CREATE TABLE IF NOT EXISTS à la première écriture) et les colonnes heartbeat
-- supplémentaires sont ignorées (columnExists). Exécutable aussi via l'outil MCP iot_migrate.

-- 1. Journal de chaque requête d'un module (POST, heartbeat, rapport OTA), ACCEPTÉE OU REJETÉE,
--    avec le motif de rejet et le décalage d'horloge (ts_received − heure serveur).
CREATE TABLE IF NOT EXISTS `device_contacts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `family` VARCHAR(8) NOT NULL COMMENT 'ffp3 | n3pp | msp1 | energie',
    `env` VARCHAR(16) NOT NULL COMMENT 'environnement TableConfig (prod, test, s3...)',
    `kind` VARCHAR(12) NOT NULL COMMENT 'post | heartbeat | ota_report',
    `accepted` TINYINT(1) NOT NULL,
    `http_status` SMALLINT UNSIGNED NOT NULL,
    `auth_mode` VARCHAR(24) DEFAULT NULL COMMENT 'x_sig_body | legacy_timestamp | legacy_nonce | api_key | crc_only | none',
    `reject_reason` VARCHAR(64) DEFAULT NULL,
    `ts_received` BIGINT DEFAULT NULL COMMENT 'horodatage envoyé par le firmware (X-Sig-Timestamp / timestamp)',
    `clock_offset_s` INT DEFAULT NULL COMMENT 'ts_received − heure serveur ; > 0 = firmware en avance',
    `firmware_version` VARCHAR(30) DEFAULT NULL,
    `post_id` VARCHAR(64) DEFAULT NULL,
    `ip` VARCHAR(45) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_dc_family_time` (`family`, `env`, `received_at`),
    KEY `idx_dc_time` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Chaque requête servie par OtaFileController (metadata.json comme .bin) : qui, quoi, quand,
--    Range, statut, octets servis / taille, durée, transfert complet ou partiel.
CREATE TABLE IF NOT EXISTS `ota_events` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `occurred_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip` VARCHAR(45) DEFAULT NULL,
    `path` VARCHAR(190) NOT NULL,
    `channel` VARCHAR(40) NOT NULL COMMENT 'premier segment du chemin (esp32-wroom, n3pp, cam...) ou root',
    `kind` VARCHAR(10) NOT NULL COMMENT 'metadata | bin | other',
    `board` VARCHAR(64) DEFAULT NULL COMMENT 'en-tête X-Ota-Board si le firmware le fournit',
    `firmware_version` VARCHAR(30) DEFAULT NULL COMMENT 'en-tête X-Ota-Version si fourni',
    `range_header` VARCHAR(64) DEFAULT NULL,
    `http_status` SMALLINT UNSIGNED NOT NULL,
    `bytes_sent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `file_size` BIGINT UNSIGNED DEFAULT NULL,
    `duration_ms` INT UNSIGNED DEFAULT NULL,
    `complete` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = le fichier entier a été servi (200 ou 206 couvrant tout)',
    `note` VARCHAR(120) DEFAULT NULL COMMENT 'paused | canary_denied | auth_rejected...',
    PRIMARY KEY (`id`),
    KEY `idx_ota_events_time` (`occurred_at`),
    KEY `idx_ota_events_channel` (`channel`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Publications OTA détectées (changement de metadata.json) : version, canal, carte, empreinte,
--    taille, horodatage et auteur quand il est connu (git).
CREATE TABLE IF NOT EXISTS `ota_releases` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target` VARCHAR(40) NOT NULL COMMENT 'ffp5 | n3pp | n3pp-test | msp | msp-test | cam',
    `channel` VARCHAR(40) NOT NULL COMMENT 'prod | test | <cible> pour les manifestes à canal unique',
    `board` VARCHAR(40) NOT NULL COMMENT 'esp32-wroom | esp32-s3 | msp1 | n3pp | ffp3 | -',
    `version` VARCHAR(30) NOT NULL,
    `hash` VARCHAR(64) DEFAULT NULL COMMENT 'sha256 ou md5 annoncé par le manifeste',
    `size` BIGINT UNSIGNED DEFAULT NULL,
    `bin_url` VARCHAR(255) DEFAULT NULL,
    `published_at` DATETIME NOT NULL COMMENT 'mtime du manifeste au moment de la détection',
    `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `author` VARCHAR(120) DEFAULT NULL COMMENT 'auteur du dernier commit git du manifeste, si lisible',
    `source` VARCHAR(20) NOT NULL DEFAULT 'cron' COMMENT 'cron | deploy',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ota_release` (`target`, `channel`, `board`, `version`, `hash`),
    KEY `idx_ota_releases_time` (`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Événements rapportés PAR LE FIRMWARE : rapport OTA (/ota-report : check, start, progress,
--    done, fail, rollback, marked_valid) et journal de bord (échecs WiFi, DNS/TLS, codes HTTP,
--    NTP, veille, panic) vidé au heartbeat, plus les infos de boot du premier heartbeat.
CREATE TABLE IF NOT EXISTS `device_events` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `family` VARCHAR(8) NOT NULL,
    `env` VARCHAR(16) NOT NULL,
    `board` VARCHAR(64) DEFAULT NULL,
    `firmware_version` VARCHAR(30) DEFAULT NULL,
    `source` VARCHAR(16) NOT NULL COMMENT 'ota_report | heartbeat_log | boot',
    `seq` INT UNSIGNED DEFAULT NULL COMMENT 'numéro de séquence firmware (acquittement / dédoublonnage)',
    `device_ts` BIGINT DEFAULT NULL COMMENT 'horloge firmware au moment de l\'événement (0 = inconnue)',
    `uptime_s` INT UNSIGNED DEFAULT NULL,
    `event` VARCHAR(32) NOT NULL,
    `detail` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_device_event_seq` (`family`, `env`, `board`, `source`, `seq`),
    KEY `idx_device_events_time` (`family`, `env`, `received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Heartbeat enrichi (firmware ffp5cs ≥ 15.34, n3pp ≥ 4.76, msp ≥ 2.79) : raison de boot,
--    partition, état OTA de l'image, boots ratés consécutifs, mode sans échec, décalage d'horloge,
--    WiFi. Colonnes NULL, ignorées par le serveur tant qu'elles n'existent pas.
ALTER TABLE `ffp3Heartbeat`
    ADD COLUMN IF NOT EXISTS `rssi` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `version` VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `resetReason` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `partition` VARCHAR(8) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `otaState` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `failedBoots` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `safeMode` TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `clockOffsetS` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wifiConnectMs` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wifiAttempts` TINYINT UNSIGNED DEFAULT NULL;

ALTER TABLE `ffp3Heartbeat2`
    ADD COLUMN IF NOT EXISTS `rssi` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `version` VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `resetReason` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `partition` VARCHAR(8) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `otaState` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `failedBoots` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `safeMode` TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `clockOffsetS` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wifiConnectMs` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wifiAttempts` TINYINT UNSIGNED DEFAULT NULL;

ALTER TABLE `ffp3HeartbeatS3`
    ADD COLUMN IF NOT EXISTS `rssi` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `version` VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `resetReason` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `partition` VARCHAR(8) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `otaState` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `failedBoots` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `safeMode` TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `clockOffsetS` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wifiConnectMs` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wifiAttempts` TINYINT UNSIGNED DEFAULT NULL;

ALTER TABLE `n3ppHeartbeat`
    ADD COLUMN IF NOT EXISTS `resetReason` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `partition` VARCHAR(8) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `otaState` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `failedBoots` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `safeMode` TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `clockOffsetS` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wifiConnectMs` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wifiAttempts` TINYINT UNSIGNED DEFAULT NULL;

ALTER TABLE `msp1Heartbeat`
    ADD COLUMN IF NOT EXISTS `resetReason` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `partition` VARCHAR(8) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `otaState` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `failedBoots` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `safeMode` TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `clockOffsetS` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wifiConnectMs` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wifiAttempts` TINYINT UNSIGNED DEFAULT NULL;
