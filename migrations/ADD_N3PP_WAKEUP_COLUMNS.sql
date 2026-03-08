-- Migration : Ajout des colonnes WakeUp et FreqWakeUp pour N3PP (serre)
-- Date : 2026-03-08
-- Description : Corrige l'erreur "Unknown column 'WakeUp' in 'INSERT INTO'" lors du POST
--               des donnees du firmware n3pp4_2.
-- Usage : Executer le premier ALTER sur la BDD prod. Si la table n3ppDataTest existe,
--         executer le second ALTER ; sinon l'ignorer (ou commenter le second bloc).

-- Table production n3ppData (executer sur la BDD prod)
ALTER TABLE n3ppData
ADD COLUMN WakeUp INT DEFAULT NULL COMMENT 'Reveil force ESP32 (0/1)' AFTER PontDiv,
ADD COLUMN FreqWakeUp INT DEFAULT NULL COMMENT 'Frequence reveil (secondes)' AFTER WakeUp;

-- Table test n3ppDataTest (si utilisee, executer aussi)
-- Si la table n'existe pas ou si les colonnes sont deja presentes, ignorer ou adapter.
ALTER TABLE n3ppDataTest
ADD COLUMN WakeUp INT DEFAULT NULL COMMENT 'Reveil force ESP32 (0/1)' AFTER PontDiv,
ADD COLUMN FreqWakeUp INT DEFAULT NULL COMMENT 'Frequence reveil (secondes)' AFTER WakeUp;
