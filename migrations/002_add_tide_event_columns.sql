-- Migration: colonnes marée / inflexion (distance capteur -> surface)
-- À exécuter sur chaque table de données FFP3.
-- Rétrocompatible : colonnes NULL, anciennes lignes inchangées.
--
-- Champs firmware POST (optionnels) :
--   tideEvent     none | peak | trough
--   tideTrend     -1 | 0 | 1
--   tideNoiseMm   hystérésis inflexion (mm)
--   tideWindowMs  fenêtre diffMaree (ms)
--   tideExtremeMm dernier extrême confirmé (mm, distance)
--
-- Tables prod/test : ffp3Data, ffp3Data2, ffp3Data3, ffp3Data4
-- Tables ESP32-S3 : ffp3DataS3, ffp3DataS3Test

ALTER TABLE ffp3Data
    ADD COLUMN tideEvent VARCHAR(16) DEFAULT NULL AFTER diffMaree,
    ADD COLUMN tideTrend TINYINT DEFAULT NULL AFTER tideEvent,
    ADD COLUMN tideNoiseMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideTrend,
    ADD COLUMN tideWindowMs INT UNSIGNED DEFAULT NULL AFTER tideNoiseMm,
    ADD COLUMN tideExtremeMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideWindowMs;

ALTER TABLE ffp3Data2
    ADD COLUMN tideEvent VARCHAR(16) DEFAULT NULL AFTER diffMaree,
    ADD COLUMN tideTrend TINYINT DEFAULT NULL AFTER tideEvent,
    ADD COLUMN tideNoiseMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideTrend,
    ADD COLUMN tideWindowMs INT UNSIGNED DEFAULT NULL AFTER tideNoiseMm,
    ADD COLUMN tideExtremeMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideWindowMs;

ALTER TABLE ffp3Data3
    ADD COLUMN tideEvent VARCHAR(16) DEFAULT NULL AFTER diffMaree,
    ADD COLUMN tideTrend TINYINT DEFAULT NULL AFTER tideEvent,
    ADD COLUMN tideNoiseMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideTrend,
    ADD COLUMN tideWindowMs INT UNSIGNED DEFAULT NULL AFTER tideNoiseMm,
    ADD COLUMN tideExtremeMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideWindowMs;

ALTER TABLE ffp3Data4
    ADD COLUMN tideEvent VARCHAR(16) DEFAULT NULL AFTER diffMaree,
    ADD COLUMN tideTrend TINYINT DEFAULT NULL AFTER tideEvent,
    ADD COLUMN tideNoiseMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideTrend,
    ADD COLUMN tideWindowMs INT UNSIGNED DEFAULT NULL AFTER tideNoiseMm,
    ADD COLUMN tideExtremeMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideWindowMs;

ALTER TABLE ffp3DataS3
    ADD COLUMN tideEvent VARCHAR(16) DEFAULT NULL AFTER diffMaree,
    ADD COLUMN tideTrend TINYINT DEFAULT NULL AFTER tideEvent,
    ADD COLUMN tideNoiseMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideTrend,
    ADD COLUMN tideWindowMs INT UNSIGNED DEFAULT NULL AFTER tideNoiseMm,
    ADD COLUMN tideExtremeMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideWindowMs;

ALTER TABLE ffp3DataS3Test
    ADD COLUMN tideEvent VARCHAR(16) DEFAULT NULL AFTER diffMaree,
    ADD COLUMN tideTrend TINYINT DEFAULT NULL AFTER tideEvent,
    ADD COLUMN tideNoiseMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideTrend,
    ADD COLUMN tideWindowMs INT UNSIGNED DEFAULT NULL AFTER tideNoiseMm,
    ADD COLUMN tideExtremeMm SMALLINT UNSIGNED DEFAULT NULL AFTER tideWindowMs;
