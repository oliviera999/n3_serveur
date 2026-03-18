-- Migration v11.38 : Nettoyage des lignes NULL inutiles dans ffp3outputs
-- Date: 2025-01-16
-- Problème: L'ESP32 créait des lignes avec name=NULL qui se régénéraient automatiquement
-- Solution: Supprimer les lignes sans nom ET ajouter une contrainte pour éviter leur recréation

-- ========================================
-- 1. SUPPRIMER LES LIGNES NULL EXISTANTES
-- ========================================

-- Production
DELETE FROM ffp3Outputs WHERE name IS NULL OR name = '';

-- Test  
DELETE FROM ffp3Outputs2 WHERE name IS NULL OR name = '';

-- ========================================
-- 2. AJOUTER UNE CONTRAINTE (OPTIONNEL)
-- ========================================

-- Production : Empêcher la création de nouvelles lignes sans nom
-- ALTER TABLE ffp3Outputs ADD CONSTRAINT chk_name_not_empty CHECK (name IS NOT NULL AND name != '');

-- Test : Empêcher la création de nouvelles lignes sans nom  
-- ALTER TABLE ffp3Outputs2 ADD CONSTRAINT chk_name_not_empty CHECK (name IS NOT NULL AND name != '');

-- ========================================
-- 3. VÉRIFICATION POST-NETTOYAGE
-- ========================================

-- Compter les lignes restantes
SELECT 'ffp3Outputs (PROD)' as table_name, COUNT(*) as total_rows, 
       COUNT(CASE WHEN name IS NOT NULL AND name != '' THEN 1 END) as named_rows
FROM ffp3Outputs
UNION ALL
SELECT 'ffp3Outputs2 (TEST)' as table_name, COUNT(*) as total_rows,
       COUNT(CASE WHEN name IS NOT NULL AND name != '' THEN 1 END) as named_rows  
FROM ffp3Outputs2;
