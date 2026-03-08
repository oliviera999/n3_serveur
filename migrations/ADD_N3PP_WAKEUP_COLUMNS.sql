-- Migration : Ajouter colonnes WakeUp et FreqWakeUp aux tables N3PP (serre / elevage)
-- Date : 2026-03-08
-- Description : Corrige l'erreur "Unknown column 'WakeUp' in 'INSERT INTO'" lors du POST
--               des donnees firmware n3pp4_2. Les colonnes sont utilisees par N3ppSensorRepository.

-- Table production (n3ppData)
ALTER TABLE n3ppData
ADD COLUMN WakeUp INT NULL COMMENT 'Reveil force ESP32 (GPIO 115)',
ADD COLUMN FreqWakeUp INT NULL COMMENT 'Frequence reveil ESP32 en secondes (GPIO 116)';

-- Table test (n3ppDataTest) : decommenter et executer si cette table existe sur la BDD
-- ALTER TABLE n3ppDataTest
-- ADD COLUMN WakeUp INT NULL COMMENT 'Reveil force ESP32 (GPIO 115)',
-- ADD COLUMN FreqWakeUp INT NULL COMMENT 'Frequence reveil ESP32 en secondes (GPIO 116)';
