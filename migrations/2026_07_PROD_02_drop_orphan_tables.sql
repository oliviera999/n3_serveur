-- ============================================================================
-- 2026_07_PROD_02 — Suppression tables orphelines (Annexe B niveau 0)
-- ============================================================================
-- Prérequis : 2026_07_PROD_01 exécuté + vérifier COUNT(ffp3DataS3) ≈ COUNT(ffp3Data4)
-- Export phpMyAdmin optionnel avant DROP ffp3Data4
-- ============================================================================

DROP TABLE IF EXISTS `ffp3Data4`;
DROP TABLE IF EXISTS `ffp3Outputs4`;
DROP TABLE IF EXISTS `ffp3Heartbeat4`;

DROP TABLE IF EXISTS `ffp3DataDel`;
DROP TABLE IF EXISTS `n3ppDataOld`;

DROP TABLE IF EXISTS `ffp3Outputs_backup_20251013`;
DROP TABLE IF EXISTS `ffp3Outputs2_backup_20251013`;
