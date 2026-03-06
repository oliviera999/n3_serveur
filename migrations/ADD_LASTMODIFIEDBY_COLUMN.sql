-- Migration : Ajouter colonne lastModifiedBy pour tracker la source des modifications
-- Date : 2025-01-15
-- Version : 11.43
-- Description : Ajoute une colonne pour distinguer les modifications faites par l'interface web vs l'ESP32

-- Ajouter la colonne lastModifiedBy aux tables outputs PROD et TEST
ALTER TABLE ffp3Outputs 
ADD COLUMN lastModifiedBy ENUM('web', 'esp32') NULL 
COMMENT 'Source de la dernière modification : web (interface) ou esp32 (capteur)';

ALTER TABLE ffp3Outputs2 
ADD COLUMN lastModifiedBy ENUM('web', 'esp32') NULL 
COMMENT 'Source de la dernière modification : web (interface) ou esp32 (capteur)';

-- Initialiser toutes les valeurs existantes comme 'esp32' (données existantes proviennent de l'ESP32)
UPDATE ffp3Outputs SET lastModifiedBy = 'esp32' WHERE lastModifiedBy IS NULL;
UPDATE ffp3Outputs2 SET lastModifiedBy = 'esp32' WHERE lastModifiedBy IS NULL;

-- Ajouter un index pour optimiser les requêtes de synchronisation
CREATE INDEX idx_lastModifiedBy_requestTime ON ffp3Outputs (lastModifiedBy, requestTime);
CREATE INDEX idx_lastModifiedBy_requestTime ON ffp3Outputs2 (lastModifiedBy, requestTime);

-- Vérifier la structure
DESCRIBE ffp3Outputs;
DESCRIBE ffp3Outputs2;
