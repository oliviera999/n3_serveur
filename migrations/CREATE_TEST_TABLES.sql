-- ================================================================================
-- CRÉATION DES TABLES TEST (ffp3Data2, ffp3Outputs2, ffp3Heartbeat2)
-- ================================================================================
-- Date: 2025-10-12
-- Objectif: Créer les tables de test manquantes en dupliquant la structure PROD
-- ================================================================================

-- ========================================
-- 1. TABLE ffp3Data2 (données capteurs TEST)
-- ========================================

-- Dupliquer la structure de ffp3Data
CREATE TABLE IF NOT EXISTS `ffp3Data2` LIKE `ffp3Data`;

-- Vérification
SELECT 'Table ffp3Data2 créée avec succès' AS message;


-- ========================================
-- 2. TABLE ffp3Outputs2 (GPIO/relais TEST)
-- ========================================

-- Dupliquer la structure de ffp3Outputs
CREATE TABLE IF NOT EXISTS `ffp3Outputs2` LIKE `ffp3Outputs`;

-- Vérification
SELECT 'Table ffp3Outputs2 créée avec succès' AS message;


-- ========================================
-- 3. TABLE ffp3Heartbeat2 (monitoring TEST)
-- ========================================

-- Dupliquer la structure de ffp3Heartbeat
CREATE TABLE IF NOT EXISTS `ffp3Heartbeat2` LIKE `ffp3Heartbeat`;

-- Vérification
SELECT 'Table ffp3Heartbeat2 créée avec succès' AS message;


-- ================================================================================
-- INITIALISATION DES DONNÉES DE BASE
-- ================================================================================

-- Copier la configuration initiale des outputs depuis PROD vers TEST
-- (Seulement si ffp3Outputs2 est vide)
INSERT INTO `ffp3Outputs2` (gpio, name, state, description)
SELECT gpio, name, 0 AS state, CONCAT(description, ' (TEST)') AS description
FROM `ffp3Outputs`
WHERE NOT EXISTS (SELECT 1 FROM `ffp3Outputs2` LIMIT 1);

-- Vérification
SELECT COUNT(*) AS 'Outputs TEST initialisés' FROM `ffp3Outputs2`;


-- ================================================================================
-- VÉRIFICATION FINALE
-- ================================================================================

-- Vérifier que toutes les tables existent
SELECT 
    TABLE_NAME,
    TABLE_ROWS,
    DATA_LENGTH,
    CREATE_TIME,
    UPDATE_TIME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('ffp3Data', 'ffp3Data2', 'ffp3Outputs', 'ffp3Outputs2', 'ffp3Heartbeat', 'ffp3Heartbeat2')
ORDER BY TABLE_NAME;

-- Afficher la structure des tables TEST pour validation
SHOW CREATE TABLE `ffp3Data2`;
SHOW CREATE TABLE `ffp3Outputs2`;
SHOW CREATE TABLE `ffp3Heartbeat2`;

-- ================================================================================
-- NOTES
-- ================================================================================
-- 
-- Exécuter ce script sur le serveur MySQL :
-- 
--   mysql -u [USER] -p [DATABASE] < CREATE_TEST_TABLES.sql
-- 
-- Ou via phpMyAdmin :
--   1. Se connecter à phpMyAdmin
--   2. Sélectionner la base de données FFP3
--   3. Onglet "SQL"
--   4. Copier/coller ce script
--   5. Cliquer "Exécuter"
-- 
-- Après création :
--   - ffp3Data2 sera vide (prête à recevoir données TEST)
--   - ffp3Outputs2 aura la même structure que ffp3Outputs avec état=0
--   - ffp3Heartbeat2 sera vide (prête à recevoir heartbeats TEST)
-- 
-- ================================================================================

