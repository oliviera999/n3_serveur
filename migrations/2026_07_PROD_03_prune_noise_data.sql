-- ============================================================================
-- 2026_07_PROD_03 — Élagage qualitatif niveaux 1–2 (Annexe B)
-- ============================================================================
-- Niveau 3 (fallback FFP3 TempAir=20/Humidite=50) : HORS PÉRIMÈTRE — aucun DELETE
-- Prérequis : backup prod ; comptage double-POST documenté (P0-U09)
-- ============================================================================

-- P0-U09 — comptage double-POST (lecture seule, à documenter avant DELETE 1a)
-- SELECT COUNT(*) AS paires_doublons FROM n3ppData d1
-- INNER JOIN n3ppData d2 ON d2.id = (
--   SELECT MIN(x.id) FROM n3ppData x WHERE x.id > d1.id
--     AND x.reading_time BETWEEN d1.reading_time AND DATE_ADD(d1.reading_time, INTERVAL 2 SECOND)
--     AND x.TempAir <=> d1.TempAir AND x.Humidite <=> d1.Humidite
--     AND x.Luminosite <=> d1.Luminosite AND x.HumidMoy <=> d1.HumidMoy
--     AND x.etatPompe <=> d1.etatPompe AND x.sensor <=> d1.sensor AND x.version <=> d1.version
-- ) WHERE d1.id < d2.id;

-- Niveau 1b — pollution early-stage N3PP
DELETE FROM `n3ppData` WHERE `sensor` = 'msp1';
DELETE FROM `n3ppDataTest` WHERE `sensor` = 'msp1';

-- Niveau 1c — fantômes ffp3Outputs3 gpio 16 NULL
DELETE FROM `ffp3Outputs3`
WHERE `gpio` = 16 AND (`name` IS NULL OR `name` = '');

-- Niveau 1a — double-POST N3PP (supprime la 2e ligne de chaque paire identique Δt ≤ 2 s)
DELETE d2 FROM `n3ppData` d1
INNER JOIN `n3ppData` d2
  ON d2.`id` = (
    SELECT MIN(x.`id`) FROM `n3ppData` x
    WHERE x.`id` > d1.`id`
      AND x.`reading_time` BETWEEN d1.`reading_time` AND DATE_ADD(d1.`reading_time`, INTERVAL 2 SECOND)
      AND x.`TempAir` <=> d1.`TempAir`
      AND x.`Humidite` <=> d1.`Humidite`
      AND x.`Luminosite` <=> d1.`Luminosite`
      AND x.`HumidMoy` <=> d1.`HumidMoy`
      AND x.`etatPompe` <=> d1.`etatPompe`
      AND x.`sensor` <=> d1.`sensor`
      AND x.`version` <=> d1.`version`
  )
WHERE d1.`id` < d2.`id`;

-- Niveau 2a — lectures DHT invalides N3PP (0/0)
DELETE FROM `n3ppData` WHERE `TempAir` = 0 AND `Humidite` = 0;
DELETE FROM `n3ppDataTest` WHERE `TempAir` = 0 AND `Humidite` = 0;

-- Niveau 2b — emails historiques aberrants N3PP
DELETE FROM `n3ppData` WHERE `mail` LIKE '%gmailsdfg%';
DELETE FROM `n3ppDataTest` WHERE `mail` LIKE '%gmailsdfg%';
