-- Migration : diagnostic d'écho ultrason FFP3 (serveur 6.57.0, firmware ffp5cs ≥ 15.33)
--
-- Le firmware poste wlAquaDiag / wlPotaDiag = "<etat>,<timeouts>,<rejets>,<mm>"
-- (n3_firmwires ffp5cs/include/echo_diag.h). Le serveur les range dans ces colonnes :
--   wl*Status    ok | no_echo | out_of_range | disabled
--   wl*Timeouts  tirs sans écho dans le cycle de lecture
--   wl*Rejected  échos reçus hors plage [20 mm ; 4000 mm[
--   wl*RawMm     distance du dernier écho rejeté (mm, 0 = aucun)
-- Contexte : docs/DIAGNOSTIC_FFP3_NIVEAU_AQUARIUM_2026-11.md (aucun moyen, à distance, de
-- distinguer câble/capteur mort d'un objet ou d'eau collés au capteur).
--
-- Rétrocompatible : colonnes NULL. Tant qu'elle n'est pas appliquée, le serveur ignore ces
-- champs (SensorRepository::columnExists, McpReadRepository : colonnes optionnelles).
-- Idempotent (MariaDB : ADD COLUMN IF NOT EXISTS). Une table absente produit une erreur
-- sans effet sur les autres : exécuter bloc par bloc dans phpMyAdmin si besoin.

ALTER TABLE `ffp3Data`
    ADD COLUMN IF NOT EXISTS `wlAquaStatus` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaTimeouts` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaRejected` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaRawMm` SMALLINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaStatus` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaTimeouts` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaRejected` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaRawMm` SMALLINT UNSIGNED DEFAULT NULL;

ALTER TABLE `ffp3Data2`
    ADD COLUMN IF NOT EXISTS `wlAquaStatus` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaTimeouts` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaRejected` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaRawMm` SMALLINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaStatus` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaTimeouts` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaRejected` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaRawMm` SMALLINT UNSIGNED DEFAULT NULL;

ALTER TABLE `ffp3Data3`
    ADD COLUMN IF NOT EXISTS `wlAquaStatus` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaTimeouts` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaRejected` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaRawMm` SMALLINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaStatus` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaTimeouts` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaRejected` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaRawMm` SMALLINT UNSIGNED DEFAULT NULL;

ALTER TABLE `ffp3DataS3`
    ADD COLUMN IF NOT EXISTS `wlAquaStatus` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaTimeouts` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaRejected` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaRawMm` SMALLINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaStatus` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaTimeouts` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaRejected` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaRawMm` SMALLINT UNSIGNED DEFAULT NULL;

ALTER TABLE `ffp3DataS3Test`
    ADD COLUMN IF NOT EXISTS `wlAquaStatus` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaTimeouts` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaRejected` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlAquaRawMm` SMALLINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaStatus` VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaTimeouts` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaRejected` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `wlPotaRawMm` SMALLINT UNSIGNED DEFAULT NULL;
