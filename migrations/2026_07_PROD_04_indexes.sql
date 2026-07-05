-- ============================================================================
-- 2026_07_PROD_04 — Index performance post-élagage (P5-06)
-- ============================================================================
-- Idempotent : ignorer erreurs "Duplicate key name"
-- ============================================================================

ALTER TABLE `ffp3Data` ADD INDEX `idx_ffp3_reading_time` (`reading_time`);
ALTER TABLE `n3ppData` ADD INDEX `idx_n3pp_reading_time` (`reading_time`);
ALTER TABLE `msp1Data` ADD INDEX `idx_msp1_reading_time` (`reading_time`);

ALTER TABLE `n3ppOutputs` ADD UNIQUE INDEX `uq_n3pp_board_gpio` (`board`, `gpio`);
ALTER TABLE `msp1Outputs` ADD UNIQUE INDEX `uq_msp1_board_gpio` (`board`, `gpio`);
