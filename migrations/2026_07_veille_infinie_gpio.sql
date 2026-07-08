-- Interrupteur « veille infinie sous seuil batterie » télécommandable (N3PP + MSP1).
--
-- Objectif : permettre d'activer/désactiver depuis l'interface de contrôle la mise en
-- veille infinie (sommeil GPIO-only, sleepSeconds=0) que le firmware déclenche quand la
-- batterie passe sous SeuilPontDiv (pont diviseur). Le firmware lit ce nouveau GPIO
-- virtuel et, s'il vaut 0, retombe sur le sommeil timer normal (FreqWakeUp) au lieu de la
-- veille infinie.
--
--   112 veilleInfinie -> 1 = veille infinie active (comportement historique, défaut),
--                        0 = désactivée (cycle de réveil normal malgré batterie basse)
--
-- CONTRAT FIRMWARE : ce GPIO n'est PAS server-only -> il est renvoyé au firmware par
-- getStateForFirmware(). Firmwares : n3pp >= 4.55, msp >= 2.53 (dépôt n3_firmwires).
-- Colonnes minimales (board, gpio, name, state, requestTime) : compatibles prod.

-- MSP1 prod (board 2)
INSERT INTO msp1Outputs (board, gpio, name, state, requestTime)
VALUES ('2', 112, 'veilleInfinie', '1', NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- MSP1 test
INSERT INTO msp1OutputsTest (board, gpio, name, state, requestTime)
SELECT board, gpio, name, state, requestTime
FROM msp1Outputs WHERE gpio = 112 AND board = '2'
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- N3PP prod (board 3)
INSERT INTO n3ppOutputs (board, gpio, name, state, requestTime)
VALUES ('3', 112, 'veilleInfinie', '1', NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- N3PP test
INSERT INTO n3ppOutputsTest (board, gpio, name, state, requestTime)
SELECT board, gpio, name, state, requestTime
FROM n3ppOutputs WHERE gpio = 112 AND board = '3'
ON DUPLICATE KEY UPDATE name = VALUES(name);
