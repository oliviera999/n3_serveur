-- GPIO server-only FFP3 : pilotage BDD du seuil hors-ligne (facteur nuit) et des seuils d'alerte.
--
-- Objectif : la supervision serveur (OfflineThresholdResolver / CronOrchestrator /
-- SystemHealthService) lit désormais ses seuils dans la BDD de contrôle au lieu du `.env`,
-- pour rester alignée sur le temps de veille réel des modules (piloté par FreqWakeUp).
--
--   126 nightSleepMult       -> multiplicateur de veille la nuit (miroir NIGHT_SLEEP_MULTIPLIER firmware)
--   127 nightStartHour       -> début de la fenêtre de nuit (heure locale, défaut 19)
--   128 nightEndHour         -> fin de la fenêtre de nuit  (heure locale, défaut 6)
--   129 tideStddevThreshold  -> seuil d'écart-type « problème de marées »
--   130 reserveLowThresholdMm-> seuil réserve basse en mm (vide = désactivé, opt-in préservé)
--
-- SERVER-ONLY : ces GPIO ne sont PAS listés par OutputController::getOutputsState() -> jamais
-- envoyés au firmware. Le firmware n'est pas modifié : ces lignes ne servent qu'au serveur.
-- Colonnes minimales (board, gpio, name, state, requestTime) : compatibles prod.

-- FFP3 prod (board 1)
INSERT INTO ffp3Outputs (board, gpio, name, state, requestTime)
VALUES
('1', 126, 'nightSleepMult', '3', NOW()),
('1', 127, 'nightStartHour', '19', NOW()),
('1', 128, 'nightEndHour', '6', NOW()),
('1', 129, 'tideStddevThreshold', '1', NOW()),
('1', 130, 'reserveLowThresholdMm', '', NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO ffp3Outputs2 (board, gpio, name, state, requestTime)
SELECT board, gpio, name, state, requestTime
FROM ffp3Outputs WHERE gpio IN (126, 127, 128, 129, 130) AND board = '1'
ON DUPLICATE KEY UPDATE name = VALUES(name), state = VALUES(state);

INSERT INTO ffp3Outputs3 (board, gpio, name, state, requestTime)
SELECT '4', gpio, name, state, NOW()
FROM ffp3Outputs WHERE gpio IN (126, 127, 128, 129, 130) AND board = '1'
ON DUPLICATE KEY UPDATE name = VALUES(name), state = VALUES(state);

INSERT INTO ffp3OutputsS3 (board, gpio, name, state, requestTime)
SELECT '5', gpio, name, state, NOW()
FROM ffp3Outputs WHERE gpio IN (126, 127, 128, 129, 130) AND board = '1'
ON DUPLICATE KEY UPDATE name = VALUES(name), state = VALUES(state);

INSERT INTO ffp3OutputsS3Test (board, gpio, name, state, requestTime)
SELECT '6', gpio, name, state, NOW()
FROM ffp3Outputs WHERE gpio IN (126, 127, 128, 129, 130) AND board = '1'
ON DUPLICATE KEY UPDATE name = VALUES(name), state = VALUES(state);
