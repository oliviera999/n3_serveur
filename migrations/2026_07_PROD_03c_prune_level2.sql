-- ============================================================================
-- 2026_07_PROD_03c — Niveaux 2a–2b (suite de 03 / 03b)
-- ============================================================================
-- À exécuter après 2026_07_PROD_03b_prune_n3pp_double_post_batched.sql
-- (ou si 03 a échoué sur 1a mais 1b–1c sont déjà passés).
-- Idempotent.
-- ============================================================================

SET SESSION wait_timeout = 28800;
SET SESSION interactive_timeout = 28800;

-- Niveau 2a — lectures DHT invalides N3PP (0/0)
DELETE FROM `n3ppData` WHERE `TempAir` = 0 AND `Humidite` = 0;
DELETE FROM `n3ppDataTest` WHERE `TempAir` = 0 AND `Humidite` = 0;

-- Niveau 2b — emails historiques aberrants N3PP
DELETE FROM `n3ppData` WHERE `mail` LIKE '%gmailsdfg%';
DELETE FROM `n3ppDataTest` WHERE `mail` LIKE '%gmailsdfg%';
