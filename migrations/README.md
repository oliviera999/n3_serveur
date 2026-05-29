# Migrations Base de Données FFP3

Ce dossier contient les scripts SQL de migration pour corriger et initialiser la base de données.

## 🔧 Correction des doublons GPIO (2025-10-13)

### Problème identifié
Des lignes vides avec `gpio=16` (et potentiellement d'autres GPIO) se créent automatiquement dans la table `ffp3Outputs`, causant des doublons.

**Cause** : L'ancien code du `PumpService` créait une nouvelle ligne à chaque fois qu'un UPDATE ne trouvait pas de ligne existante, sans vérifier les doublons.

### Solution appliquée

1. **Code** : Modification du `PumpService.php` pour utiliser `INSERT ... ON DUPLICATE KEY UPDATE`
2. **Base de données** : Nettoyage des doublons + ajout d'une contrainte UNIQUE sur la colonne `gpio`

### 📋 Procédure d'application

#### Étape 1 : Appliquer la migration de correction
```bash
# Via mysql CLI
mysql -u oliviera_iot -p oliviera_iot < migrations/FIX_DUPLICATE_GPIO_ROWS.sql

# OU via phpMyAdmin:
# 1. Se connecter à phpMyAdmin
# 2. Sélectionner la base oliviera_iot
# 3. Aller dans l'onglet SQL
# 4. Copier-coller le contenu de FIX_DUPLICATE_GPIO_ROWS.sql
# 5. Exécuter
```

**Ce script va :**
- ✅ Afficher les doublons actuels
- ✅ Supprimer tous les doublons en conservant les lignes avec le plus de données
- ✅ Ajouter une contrainte `UNIQUE` sur la colonne `gpio` pour les deux tables
- ✅ Afficher l'état final

#### Étape 2 : Initialiser les GPIO de base (optionnel mais recommandé)
```bash
# Via mysql CLI
mysql -u oliviera_iot -p oliviera_iot < migrations/INIT_GPIO_BASE_ROWS.sql

# OU via phpMyAdmin (même procédure)
```

**Ce script va :**
- ✅ Créer ou mettre à jour toutes les lignes GPIO nécessaires (2, 15, 16, 18, 100-116)
- ✅ Ajouter des noms, boards et descriptions appropriés
- ✅ Synchroniser ffp3Outputs2 avec ffp3Outputs

#### Étape 3 : Vérification
Après l'application des migrations, vérifier :

```sql
-- Vérifier qu'il n'y a plus de doublons
SELECT gpio, COUNT(*) as nb 
FROM ffp3Outputs 
GROUP BY gpio 
HAVING COUNT(*) > 1;

-- Devrait retourner 0 lignes

-- Vérifier que la contrainte UNIQUE existe
SHOW INDEXES FROM ffp3Outputs WHERE Key_name = 'unique_gpio';

-- Vérifier les GPIO initialisés
SELECT gpio, name, board, state, description 
FROM ffp3Outputs 
ORDER BY gpio;
```

### 🛡️ Prévention future

La contrainte `UNIQUE` sur `gpio` empêchera MySQL d'insérer des doublons :
- Si une tentative d'insertion d'un GPIO existant est faite, MySQL retournera une erreur
- Le nouveau code du `PumpService` utilise `ON DUPLICATE KEY UPDATE` qui met à jour la ligne existante au lieu d'en créer une nouvelle

### ⚠️ Notes importantes

1. **Sauvegarde** : Bien que le script soit testé, il est recommandé de faire une sauvegarde avant :
   ```bash
   mysqldump -u oliviera_iot -p oliviera_iot ffp3Outputs ffp3Outputs2 > backup_outputs_$(date +%Y%m%d).sql
   ```

2. **Environnements** : Les scripts traitent automatiquement les deux environnements :
   - Production : `ffp3Outputs`
   - Test : `ffp3Outputs2`

3. **Ordre d'exécution** : Respecter l'ordre des scripts :
   1. D'abord `FIX_DUPLICATE_GPIO_ROWS.sql` (nettoyage + contrainte)
   2. Ensuite `INIT_GPIO_BASE_ROWS.sql` (initialisation)

4. **Si erreur "Duplicate key"** pendant la migration :
   - C'est normal si des contraintes existent déjà
   - Ignorer l'erreur et continuer

### 📊 Impact

- **Tables affectées** : `ffp3Outputs` et `ffp3Outputs2`
- **Downtime** : Aucun (opération rapide < 1 seconde)
- **Réversibilité** : Utiliser le backup pour revenir en arrière si besoin

### 🔍 Dépannage

**Problème** : "Duplicate entry for key 'unique_gpio'"
- **Cause** : Des doublons existent encore
- **Solution** : Réexécuter `FIX_DUPLICATE_GPIO_ROWS.sql`

**Problème** : "Can't DROP 'unique_gpio'; check that column/key exists"
- **Cause** : La contrainte n'existe pas encore (première exécution)
- **Solution** : Normal, ignorer l'erreur

**Problème** : Des lignes vides continuent de se créer après la migration
- **Cause** : L'ancien code est peut-être encore utilisé quelque part
- **Solution** : Vérifier que le nouveau `PumpService.php` est bien déployé

---

## 🚀 Déploiement en production

Après avoir testé sur l'environnement de test :

1. Appliquer les migrations sur la base de production
2. Vérifier les logs CRON (`cronlog.txt`) pour détecter d'éventuelles erreurs
3. Vérifier l'interface de contrôle (`/ffp3control/securecontrol/`)
4. Supprimer manuellement les éventuelles lignes vides restantes

## Marées min/max — colonnes `tide*` (2026-05-25)

### Contexte

Le firmware FFP5CS v13.81+ envoie des métadonnées d'inflexion marée dans le POST `post-data*` :
`tideEvent`, `tideTrend`, `tideNoiseMm`, `tideWindowMs`, `tideExtremeMm`.

Le serveur PHP (v5.1.1+) accepte et persiste ces champs **si les colonnes existent** (`SensorRepository::columnExists`).
Sans migration, l'ingestion reste rétrocompatible mais les valeurs ne sont pas stockées en BDD.

### Application

```bash
mysql -u oliviera_iot -p oliviera_iot < migrations/002_add_tide_event_columns.sql
```

Tables concernées : `ffp3Data`, `ffp3Data2`, `ffp3Data3`, `ffp3Data4`, `ffp3DataS3`, `ffp3DataS3Test`.

### Vérification

```sql
SHOW COLUMNS FROM ffp3Data LIKE 'tide%';
SELECT tideEvent, tideTrend, tideExtremeMm, reading_time
FROM ffp3Data2
WHERE tideEvent IN ('peak', 'trough')
ORDER BY reading_time DESC
LIMIT 10;
```

Si une table n'existe pas sur l'hébergement (ex. `ffp3DataS3Test`), commenter ou supprimer le bloc `ALTER` correspondant avant exécution.

---

## 📝 Changelog

- **2026-05-25** : `002_add_tide_event_columns.sql` — colonnes marée/inflexion sur tables `ffp3Data*`
- **2025-10-13** : Création des migrations pour correction doublons GPIO
  - `FIX_DUPLICATE_GPIO_ROWS.sql` : Nettoyage + contrainte UNIQUE
  - `INIT_GPIO_BASE_ROWS.sql` : Initialisation des GPIO de base

