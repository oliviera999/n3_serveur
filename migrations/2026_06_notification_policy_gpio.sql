-- GPIO server-only : politique de notifications par famille (notifMode + notifCategories)
-- FFP3 : GPIO 124-125 | MSP/N3PP : GPIO 108-109 | Galeries : GPIO 107-108
-- mailNotif (firmware) reste inchangé ; dérivé par l'API notification-policy.
--
-- Colonnes minimales (board, gpio, name, state, requestTime) : compatible prod
-- sans colonne `description` (schéma Docker local peut l'avoir en plus, ignoré ici).

-- FFP3 prod (board 1)
INSERT INTO ffp3Outputs (board, gpio, name, state, requestTime)
VALUES
('1', 124, 'notifMode', 'important', NOW()),
('1', 125, 'notifCategories', '', NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO ffp3Outputs2 (board, gpio, name, state, requestTime)
SELECT board, gpio, name, state, requestTime
FROM ffp3Outputs WHERE gpio IN (124, 125) AND board = '1'
ON DUPLICATE KEY UPDATE name = VALUES(name), state = VALUES(state);

INSERT INTO ffp3Outputs3 (board, gpio, name, state, requestTime)
SELECT '4', gpio, name, state, NOW()
FROM ffp3Outputs WHERE gpio IN (124, 125) AND board = '1'
ON DUPLICATE KEY UPDATE name = VALUES(name), state = VALUES(state);

INSERT INTO ffp3OutputsS3 (board, gpio, name, state, requestTime)
SELECT '5', gpio, name, state, NOW()
FROM ffp3Outputs WHERE gpio IN (124, 125) AND board = '1'
ON DUPLICATE KEY UPDATE name = VALUES(name), state = VALUES(state);

INSERT INTO ffp3OutputsS3Test (board, gpio, name, state, requestTime)
SELECT '6', gpio, name, state, NOW()
FROM ffp3Outputs WHERE gpio IN (124, 125) AND board = '1'
ON DUPLICATE KEY UPDATE name = VALUES(name), state = VALUES(state);

-- MSP1 (board 2)
INSERT INTO msp1Outputs (board, gpio, name, state, requestTime)
VALUES
('2', 108, 'notifMode', 'important', NOW()),
('2', 109, 'notifCategories', '', NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO msp1OutputsTest (board, gpio, name, state, requestTime)
SELECT board, gpio, name, state, requestTime
FROM msp1Outputs WHERE gpio IN (108, 109) AND board = '2'
ON DUPLICATE KEY UPDATE name = VALUES(name), state = VALUES(state);

-- N3PP (board 3)
INSERT INTO n3ppOutputs (board, gpio, name, state, requestTime)
VALUES
('3', 108, 'notifMode', 'important', NOW()),
('3', 109, 'notifCategories', '', NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO n3ppOutputsTest (board, gpio, name, state, requestTime)
SELECT board, gpio, name, state, requestTime
FROM n3ppOutputs WHERE gpio IN (108, 109) AND board = '3'
ON DUPLICATE KEY UPDATE name = VALUES(name), state = VALUES(state);

-- Galeries
INSERT INTO UploadPhoto1Outputs (board, gpio, name, state, requestTime)
VALUES ('5', 107, 'notifMode', 'important', NOW()), ('5', 108, 'notifCategories', '', NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO UploadPhoto2Outputs (board, gpio, name, state, requestTime)
VALUES ('6', 107, 'notifMode', 'important', NOW()), ('6', 108, 'notifCategories', '', NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO UploadPhoto3Outputs (board, gpio, name, state, requestTime)
VALUES ('7', 107, 'notifMode', 'important', NOW()), ('7', 108, 'notifCategories', '', NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name);
