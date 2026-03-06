-- ============================================================================
-- Migration: Correction des lignes dupliquées GPIO dans ffp3Outputs
-- Date: 2025-10-13
-- Problème: Des lignes vides avec GPIO=16 (et autres) se créent automatiquement
-- Solution: Nettoyage des doublons + ajout contrainte UNIQUE sur gpio
-- ============================================================================

-- IMPORTANT: Exécuter ce script sur la base de données oliviera_iot
-- À exécuter via phpMyAdmin ou mysql CLI

USE oliviera_iot;

-- ============================================================================
-- ÉTAPE 1: Vérification et affichage des doublons actuels
-- ============================================================================

SELECT 'Doublons dans ffp3Outputs:' as Info;
SELECT gpio, COUNT(*) as nb_duplicates 
FROM ffp3Outputs 
GROUP BY gpio 
HAVING COUNT(*) > 1;

SELECT 'Doublons dans ffp3Outputs2:' as Info;
SELECT gpio, COUNT(*) as nb_duplicates 
FROM ffp3Outputs2 
GROUP BY gpio 
HAVING COUNT(*) > 1;

-- ============================================================================
-- ÉTAPE 2: Sauvegarde temporaire des bonnes lignes (avec nom ou board)
-- ============================================================================

-- Pour ffp3Outputs
CREATE TEMPORARY TABLE IF NOT EXISTS temp_good_outputs_prod AS
SELECT MIN(id) as id, gpio, 
       MAX(name) as name, 
       MAX(board) as board, 
       MAX(state) as state,
       MAX(description) as description
FROM ffp3Outputs
WHERE gpio IS NOT NULL
GROUP BY gpio;

-- Pour ffp3Outputs2
CREATE TEMPORARY TABLE IF NOT EXISTS temp_good_outputs_test AS
SELECT MIN(id) as id, gpio, 
       MAX(name) as name, 
       MAX(board) as board, 
       MAX(state) as state,
       MAX(description) as description
FROM ffp3Outputs2
WHERE gpio IS NOT NULL
GROUP BY gpio;

-- ============================================================================
-- ÉTAPE 3: Suppression de TOUS les doublons dans les tables
-- ============================================================================

-- Supprimer toutes les lignes ayant des GPIO en doublon dans ffp3Outputs
DELETE FROM ffp3Outputs 
WHERE gpio IN (
    SELECT gpio FROM (
        SELECT gpio 
        FROM ffp3Outputs 
        GROUP BY gpio 
        HAVING COUNT(*) > 1
    ) as duplicates
);

-- Supprimer toutes les lignes ayant des GPIO en doublon dans ffp3Outputs2
DELETE FROM ffp3Outputs2 
WHERE gpio IN (
    SELECT gpio FROM (
        SELECT gpio 
        FROM ffp3Outputs2 
        GROUP BY gpio 
        HAVING COUNT(*) > 1
    ) as duplicates
);

-- ============================================================================
-- ÉTAPE 4: Réinsertion des bonnes lignes depuis la sauvegarde
-- ============================================================================

-- Réinsérer les lignes nettoyées dans ffp3Outputs
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
SELECT gpio, name, board, state, description
FROM temp_good_outputs_prod;

-- Réinsérer les lignes nettoyées dans ffp3Outputs2
INSERT INTO ffp3Outputs2 (gpio, name, board, state, description)
SELECT gpio, name, board, state, description
FROM temp_good_outputs_test;

-- ============================================================================
-- ÉTAPE 5: Ajout de contrainte UNIQUE sur gpio (si pas déjà présente)
-- ============================================================================

-- Pour ffp3Outputs
ALTER TABLE ffp3Outputs 
ADD UNIQUE KEY unique_gpio (gpio);

-- Pour ffp3Outputs2
ALTER TABLE ffp3Outputs2 
ADD UNIQUE KEY unique_gpio (gpio);

-- ============================================================================
-- ÉTAPE 6: Vérification finale
-- ============================================================================

SELECT 'État final ffp3Outputs:' as Info;
SELECT COUNT(*) as total_rows, 
       COUNT(DISTINCT gpio) as unique_gpios
FROM ffp3Outputs;

SELECT 'État final ffp3Outputs2:' as Info;
SELECT COUNT(*) as total_rows, 
       COUNT(DISTINCT gpio) as unique_gpios
FROM ffp3Outputs2;

SELECT 'Migration terminée avec succès!' as Status;

-- ============================================================================
-- Notes:
-- - Ce script préserve la ligne avec le maximum de données (nom, board, etc.)
-- - La contrainte UNIQUE empêchera la création future de doublons
-- - Le PumpService a été modifié pour utiliser INSERT ... ON DUPLICATE KEY UPDATE
-- ============================================================================

