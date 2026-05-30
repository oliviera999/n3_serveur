-- Migration: colonnes config FFP3 manquantes sur tables ffp3Data*
-- Date: 2026-05 (audit prod oliviera_iot3 vs serveur 5.1.3)
-- Reference: SensorRepository optionalCols, ENDPOINTS_ESP32_SERVEUR.md
--
-- Colonnes: tempsGros, tempsPetits, tempsRemplissageSec, limFlood,
--           WakeUp, FreqWakeUp, configSynced, Pression
--
-- Executer APRES 001_add_post_id.sql et 001b_add_post_id_s3.sql si applicable.
-- Ignorer "Duplicate column name" si une colonne existe deja.
-- ffp3Data (~1M lignes) : preferer une plage horaire creuse.

ALTER TABLE ffp3Data
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

ALTER TABLE ffp3Data2
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

ALTER TABLE ffp3Data3
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

ALTER TABLE ffp3Data4
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

ALTER TABLE ffp3DataS3
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;

ALTER TABLE ffp3DataS3Test
    ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir,
    ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros,
    ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits,
    ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec,
    ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood,
    ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp,
    ADD COLUMN configSynced INT DEFAULT NULL AFTER FreqWakeUp,
    ADD COLUMN Pression DOUBLE DEFAULT NULL AFTER configSynced;
