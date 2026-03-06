-- ============================================================================
-- Initialisation des lignes de base GPIO dans ffp3Outputs
-- Date: 2025-10-13
-- Description: Crée les lignes de base pour tous les GPIO utilisés
-- ============================================================================

USE oliviera_iot;

-- ============================================================================
-- GPIO physiques (sorties matérielles ESP32)
-- ============================================================================

-- GPIO 2: Chauffage
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (2, 'Chauffage', 'ESP32-MAIN', 0, 'Relais de contrôle du chauffage aquarium')
ON DUPLICATE KEY UPDATE name='Chauffage', board='ESP32-MAIN', description='Relais de contrôle du chauffage aquarium';

-- GPIO 15: UV
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (15, 'UV', 'ESP32-MAIN', 0, 'Relais de contrôle lampe UV')
ON DUPLICATE KEY UPDATE name='UV', board='ESP32-MAIN', description='Relais de contrôle lampe UV';

-- GPIO 16: Pompe Aquarium
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (16, 'Pompe Aquarium', 'ESP32-MAIN', 0, 'Pompe de circulation aquarium (marée)')
ON DUPLICATE KEY UPDATE name='Pompe Aquarium', board='ESP32-MAIN', description='Pompe de circulation aquarium (marée)';

-- GPIO 18: Pompe Réserve
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (18, 'Pompe Réserve', 'ESP32-MAIN', 1, 'Pompe de remplissage depuis réserve')
ON DUPLICATE KEY UPDATE name='Pompe Réserve', board='ESP32-MAIN', description='Pompe de remplissage depuis réserve';

-- ============================================================================
-- GPIO virtuels 100-116 (paramètres de configuration)
-- ============================================================================

-- GPIO 100: Mail destinataire
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (100, 'Email Config', 'CONFIG', 0, 'Adresse email pour notifications')
ON DUPLICATE KEY UPDATE name='Email Config', board='CONFIG', description='Adresse email pour notifications';

-- GPIO 101: Notification mail activée
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (101, 'Notif Mail', 'CONFIG', 1, 'Activation notifications email (0=off, 1=on)')
ON DUPLICATE KEY UPDATE name='Notif Mail', board='CONFIG', description='Activation notifications email (0=off, 1=on)';

-- GPIO 102: Seuil aquarium
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (102, 'Seuil Aquarium', 'CONFIG', 7, 'Niveau eau minimal aquarium (cm)')
ON DUPLICATE KEY UPDATE name='Seuil Aquarium', board='CONFIG', description='Niveau eau minimal aquarium (cm)';

-- GPIO 103: Seuil réserve
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (103, 'Seuil Réserve', 'CONFIG', 10, 'Niveau eau minimal réserve (cm)')
ON DUPLICATE KEY UPDATE name='Seuil Réserve', board='CONFIG', description='Niveau eau minimal réserve (cm)';

-- GPIO 104: Seuil chauffage
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (104, 'Seuil Chauffage', 'CONFIG', 18, 'Température minimale pour chauffage (°C)')
ON DUPLICATE KEY UPDATE name='Seuil Chauffage', board='CONFIG', description='Température minimale pour chauffage (°C)';

-- GPIO 105: Heure nourriture matin
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (105, 'Bouffe Matin', 'CONFIG', 8, 'Heure distribution nourriture matin')
ON DUPLICATE KEY UPDATE name='Bouffe Matin', board='CONFIG', description='Heure distribution nourriture matin';

-- GPIO 106: Heure nourriture midi
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (106, 'Bouffe Midi', 'CONFIG', 12, 'Heure distribution nourriture midi')
ON DUPLICATE KEY UPDATE name='Bouffe Midi', board='CONFIG', description='Heure distribution nourriture midi';

-- GPIO 107: Heure nourriture soir
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (107, 'Bouffe Soir', 'CONFIG', 18, 'Heure distribution nourriture soir')
ON DUPLICATE KEY UPDATE name='Bouffe Soir', board='CONFIG', description='Heure distribution nourriture soir';

-- GPIO 108: Distribution petits poissons
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (108, 'Bouffe Petits', 'CONFIG', 1, 'Distribution nourriture petits poissons')
ON DUPLICATE KEY UPDATE name='Bouffe Petits', board='CONFIG', description='Distribution nourriture petits poissons';

-- GPIO 109: Distribution gros poissons
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (109, 'Bouffe Gros', 'CONFIG', 0, 'Distribution nourriture gros poissons')
ON DUPLICATE KEY UPDATE name='Bouffe Gros', board='CONFIG', description='Distribution nourriture gros poissons';

-- GPIO 110: Mode Reset
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (110, 'Reset Mode', 'CONFIG', 0, 'Redémarrage ESP32 (1=reboot)')
ON DUPLICATE KEY UPDATE name='Reset Mode', board='CONFIG', description='Redémarrage ESP32 (1=reboot)';

-- GPIO 111: Durée distribution gros poissons
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (111, 'Temps Gros', 'CONFIG', 3, 'Durée distribution gros poissons (secondes)')
ON DUPLICATE KEY UPDATE name='Temps Gros', board='CONFIG', description='Durée distribution gros poissons (secondes)';

-- GPIO 112: Durée distribution petits poissons
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (112, 'Temps Petits', 'CONFIG', 2, 'Durée distribution petits poissons (secondes)')
ON DUPLICATE KEY UPDATE name='Temps Petits', board='CONFIG', description='Durée distribution petits poissons (secondes)';

-- GPIO 113: Temps remplissage réserve
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (113, 'Temps Remplissage', 'CONFIG', 60, 'Durée remplissage réserve (secondes)')
ON DUPLICATE KEY UPDATE name='Temps Remplissage', board='CONFIG', description='Durée remplissage réserve (secondes)';

-- GPIO 114: Limite protection inondation
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (114, 'Limite Inondation', 'CONFIG', 5, 'Seuil critique inondation (cm)')
ON DUPLICATE KEY UPDATE name='Limite Inondation', board='CONFIG', description='Seuil critique inondation (cm)';

-- GPIO 115: Réveil ESP32
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (115, 'WakeUp', 'CONFIG', 0, 'Réveil forcé ESP32')
ON DUPLICATE KEY UPDATE name='WakeUp', board='CONFIG', description='Réveil forcé ESP32';

-- GPIO 116: Fréquence réveil
INSERT INTO ffp3Outputs (gpio, name, board, state, description)
VALUES (116, 'Freq WakeUp', 'CONFIG', 300, 'Fréquence réveil ESP32 (secondes)')
ON DUPLICATE KEY UPDATE name='Freq WakeUp', board='CONFIG', description='Fréquence réveil ESP32 (secondes)';

-- ============================================================================
-- Vérification finale
-- ============================================================================

SELECT 'GPIO initialisés avec succès!' as Status;
SELECT gpio, name, board, state, description 
FROM ffp3Outputs 
ORDER BY gpio;

-- ============================================================================
-- IMPORTANT: Répéter pour ffp3Outputs2 (environnement TEST)
-- ============================================================================

-- Copier toutes les lignes ci-dessus en remplaçant ffp3Outputs par ffp3Outputs2
INSERT INTO ffp3Outputs2 (gpio, name, board, state, description)
SELECT gpio, name, board, state, description
FROM ffp3Outputs
ON DUPLICATE KEY UPDATE 
    name = VALUES(name),
    board = VALUES(board),
    description = VALUES(description);

SELECT 'GPIO TEST initialisés avec succès!' as Status;
SELECT gpio, name, board, state, description 
FROM ffp3Outputs2 
ORDER BY gpio;

