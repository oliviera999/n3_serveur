-- ============================================================================
-- Pression atmosphérique (hPa) — familles MSP1 et N3PP (serveur 6.39.0)
-- ============================================================================
-- Les firmwares msp v2.74 et n3pp v4.70 postent un champ `Pression` (hPa,
-- 1 décimale) quand un BME280 a été détecté au boot ; le champ est envoyé vide
-- sur les appareils restés en DHT et le serveur enregistre alors NULL.
-- Colonne nullable : aucune valeur par défaut, aucun impact sur les lignes
-- existantes ni sur les appareils non mis à jour.
--
-- Tables concernées (prod + test) — la famille FFP3 a déjà sa colonne
-- (ADD_MISSING_COLUMNS_v11.36.sql).
--
-- Erreur attendue si déjà appliqué : `Duplicate column name 'Pression'`
-- — ignorer le bloc concerné.
-- ============================================================================

ALTER TABLE `msp1Data`
    ADD COLUMN `Pression` DOUBLE DEFAULT NULL AFTER `HumidAirExt`;

ALTER TABLE `msp1DataTest`
    ADD COLUMN `Pression` DOUBLE DEFAULT NULL AFTER `HumidAirExt`;

ALTER TABLE `n3ppData`
    ADD COLUMN `Pression` DOUBLE DEFAULT NULL AFTER `Humidite`;

ALTER TABLE `n3ppDataTest`
    ADD COLUMN `Pression` DOUBLE DEFAULT NULL AFTER `Humidite`;

-- Vérification :
-- SHOW COLUMNS FROM `msp1Data` LIKE 'Pression';
-- SHOW COLUMNS FROM `n3ppData` LIKE 'Pression';
