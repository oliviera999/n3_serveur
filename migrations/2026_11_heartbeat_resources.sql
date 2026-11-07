-- Migration additive — heartbeat : ressources mémoire / CPU / NVS (firmware ffp5cs >= 15.35)
-- Suite de l'analyse mémoire et charge du 2026-11-07 (n3_firmwires docs/DIAGNOSTIC_PERTE_CONTACT_FIRMWARE.md § 8) :
--   maxBlock   = plus grand bloc libre du tas (octets) : un heap « libre » mais fragmenté sous le seuil
--                TLS de 35 Ko est la panne silencieuse que `freeHeap` ne montre pas ;
--   idlePct    = % de temps idle CPU (2 coeurs) sur le dernier intervalle d'échantillonnage ;
--   nvsUsedPct = % d'entrées utilisées de la partition NVS (20 Ko sur WROOM).
-- Rappel : depuis ffp5cs 15.35 la colonne historique `minHeap` est le plancher DEPUIS LE BOOT
-- (avant : plancher historique rechargé de la NVS, identique sur plusieurs boots).
-- Colonnes NULL, ignorées par le serveur tant qu'elles n'existent pas (HeartbeatRepository::columnExists).

ALTER TABLE `ffp3Heartbeat`
    ADD COLUMN IF NOT EXISTS `maxBlock` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `idlePct` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `nvsUsedPct` TINYINT UNSIGNED DEFAULT NULL;

ALTER TABLE `ffp3Heartbeat2`
    ADD COLUMN IF NOT EXISTS `maxBlock` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `idlePct` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `nvsUsedPct` TINYINT UNSIGNED DEFAULT NULL;

ALTER TABLE `ffp3HeartbeatS3`
    ADD COLUMN IF NOT EXISTS `maxBlock` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `idlePct` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `nvsUsedPct` TINYINT UNSIGNED DEFAULT NULL;

ALTER TABLE `n3ppHeartbeat`
    ADD COLUMN IF NOT EXISTS `maxBlock` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `idlePct` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `nvsUsedPct` TINYINT UNSIGNED DEFAULT NULL;

ALTER TABLE `msp1Heartbeat`
    ADD COLUMN IF NOT EXISTS `maxBlock` INT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `idlePct` TINYINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `nvsUsedPct` TINYINT UNSIGNED DEFAULT NULL;
