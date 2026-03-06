# 🔧 Résumé de la correction - Doublons GPIO v4.5.17

## 📊 Vue d'ensemble

**Problème** : 4 lignes vides avec `gpio=16` se créent automatiquement dans `ffp3Outputs`  
**Cause** : Code PumpService.php + absence de contrainte UNIQUE  
**Solution** : INSERT ON DUPLICATE KEY UPDATE + migrations SQL  
**Statut** : ✅ CODE CORRIGÉ - ⏳ MIGRATIONS À APPLIQUER

---

## 📁 Fichiers modifiés

### Code source
- ✅ `src/Service/PumpService.php` - Méthode setState() refactorisée
- ✅ `VERSION` - Incrémenté de 4.5.16 à 4.5.17
- ✅ `CHANGELOG.md` - Documentation complète de la correction

---

## 📁 Nouveaux fichiers créés

### Scripts de migration SQL (dossier `migrations/`)
1. **`FIX_DUPLICATE_GPIO_ROWS.sql`** (164 lignes)
   - Nettoyage automatique des doublons
   - Ajout contrainte UNIQUE sur gpio
   - Vérifications avant/après
   
2. **`INIT_GPIO_BASE_ROWS.sql`** (192 lignes)
   - Initialisation de tous les GPIO (2, 15, 16, 18, 100-116)
   - Attribution de noms, boards et descriptions
   - Synchronisation PROD/TEST

3. **`README.md`** (Migration guide)
   - Documentation complète
   - Procédure d'application
   - Dépannage

### Documentation
4. **`CORRECTION_DOUBLONS_GPIO_v4.5.17.md`** (430 lignes)
   - Analyse détaillée du problème
   - Explication de la solution
   - Tests de validation
   - Suivi post-déploiement

5. **`APPLIQUER_CORRECTIONS_v4.5.17.txt`** (Instructions)
   - Checklist étape par étape
   - Commandes à exécuter
   - Procédure phpMyAdmin

6. **`RESUME_CORRECTION_GPIO.md`** (ce fichier)
   - Vue d'ensemble rapide

---

## 🔍 Diagnostic du problème

```
┌─────────────────────────────────────────────────────────────┐
│  AVANT : Comportement problématique                          │
└─────────────────────────────────────────────────────────────┘

ESP32 envoie données → PostDataController
                              ↓
                    SensorRepository insert()
                              ↓
              ┌───────────────┴───────────────┐
              ↓                               ↓
    CleanDataCommand (CRON)        ProcessTasksCommand (CRON)
              ↓                               ↓
      PumpService.stopPompeTank()    PumpService.stopPompeAqua()
              ↓                               ↓
         setState(18, 1)                 setState(16, 0)
              ↓                               ↓
    UPDATE ffp3Outputs WHERE gpio=18   UPDATE ffp3Outputs WHERE gpio=16
              ↓                               ↓
        rowCount() === 0 ?              rowCount() === 0 ?
              ↓ YES                           ↓ YES
    INSERT (gpio=18, state=1)          INSERT (gpio=16, state=0)
              ↓                               ↓
      NOUVELLE LIGNE VIDE            ❌ NOUVELLE LIGNE VIDE ❌
                                     (répété 4 fois = 4 doublons)

Problème : Aucune contrainte UNIQUE → MySQL accepte les doublons
```

---

## ✅ Solution appliquée

```
┌─────────────────────────────────────────────────────────────┐
│  APRÈS : Comportement corrigé                                │
└─────────────────────────────────────────────────────────────┘

ESP32 envoie données → PostDataController
                              ↓
                    SensorRepository insert()
                              ↓
              ┌───────────────┴───────────────┐
              ↓                               ↓
    CleanDataCommand (CRON)        ProcessTasksCommand (CRON)
              ↓                               ↓
      PumpService.stopPompeTank()    PumpService.stopPompeAqua()
              ↓                               ↓
         setState(18, 1)                 setState(16, 0)
              ↓                               ↓
INSERT INTO ffp3Outputs (gpio, state)  INSERT INTO ffp3Outputs (gpio, state)
VALUES (18, 1)                         VALUES (16, 0)
ON DUPLICATE KEY UPDATE state=1        ON DUPLICATE KEY UPDATE state=0
              ↓                               ↓
    Si gpio=18 existe → UPDATE         Si gpio=16 existe → UPDATE ✅
    Si gpio=18 n'existe pas → INSERT   Si gpio=16 n'existe pas → INSERT
              ↓                               ↓
    UNE SEULE LIGNE                    ✅ UNE SEULE LIGNE ✅

Protection : Contrainte UNIQUE sur gpio → MySQL rejette les doublons
```

---

## 📋 Checklist d'application

### 1. Code (✅ FAIT)
- [x] Modifier PumpService.php
- [x] Incrémenter VERSION
- [x] Mettre à jour CHANGELOG.md
- [x] Créer scripts de migration
- [x] Rédiger documentation

### 2. Git (⏳ À FAIRE)
- [ ] `git add .`
- [ ] `git commit -m "fix: correction doublons GPIO v4.5.17"`
- [ ] `git push origin main`

### 3. Déploiement serveur (⏳ À FAIRE)
- [ ] `git pull` sur le serveur
- [ ] Appliquer `FIX_DUPLICATE_GPIO_ROWS.sql`
- [ ] Appliquer `INIT_GPIO_BASE_ROWS.sql`

### 4. Vérifications (⏳ À FAIRE)
- [ ] Vérifier qu'il n'y a plus de doublons
- [ ] Vérifier que GPIO 16 a le nom "Pompe Aquarium"
- [ ] Vérifier que la contrainte UNIQUE existe
- [ ] Tester l'interface de contrôle
- [ ] Attendre 10 min et vérifier qu'aucun doublon ne se crée

---

## 🎯 Résultat attendu

### Base de données AVANT
```sql
SELECT gpio, name, state FROM ffp3Outputs WHERE gpio=16;

+------+------+-------+
| gpio | name | state |
+------+------+-------+
|   16 |      |     0 |  ← Ligne vide 1
|   16 |      |     0 |  ← Ligne vide 2
|   16 |      |     1 |  ← Ligne vide 3
|   16 |      |     0 |  ← Ligne vide 4
+------+------+-------+
4 rows in set
```

### Base de données APRÈS
```sql
SELECT gpio, name, board, state FROM ffp3Outputs WHERE gpio=16;

+------+----------------+------------+-------+
| gpio | name           | board      | state |
+------+----------------+------------+-------+
|   16 | Pompe Aquarium | ESP32-MAIN |     1 |  ← Ligne unique avec nom
+------+----------------+------------+-------+
1 row in set
```

### Test de protection
```sql
-- Tentative de création d'un doublon
INSERT INTO ffp3Outputs (gpio, state) VALUES (16, 0);

-- Résultat attendu :
ERROR 1062 (23000): Duplicate entry '16' for key 'unique_gpio'
                    ↑ La contrainte UNIQUE empêche le doublon !
```

---

## 📚 Documentation disponible

| Fichier | Usage |
|---------|-------|
| `migrations/README.md` | Guide complet des migrations |
| `CORRECTION_DOUBLONS_GPIO_v4.5.17.md` | Analyse technique détaillée |
| `APPLIQUER_CORRECTIONS_v4.5.17.txt` | Instructions pas à pas |
| `CHANGELOG.md` | Historique version 4.5.17 |

---

## ⚡ Commandes rapides

```bash
# Déploiement complet (à exécuter sur le serveur)
cd /var/www/html/ffp3
git pull origin main

# Sauvegarde
mysqldump -u oliviera_iot -p oliviera_iot ffp3Outputs ffp3Outputs2 > backup.sql

# Application migrations
mysql -u oliviera_iot -p oliviera_iot < migrations/FIX_DUPLICATE_GPIO_ROWS.sql
mysql -u oliviera_iot -p oliviera_iot < migrations/INIT_GPIO_BASE_ROWS.sql

# Vérification
mysql -u oliviera_iot -p oliviera_iot -e "SELECT gpio, COUNT(*) as nb FROM ffp3Outputs GROUP BY gpio HAVING COUNT(*) > 1;"
# Résultat attendu : Empty set (0 doublons)
```

---

## 🎉 Impact final

### Avant la correction
- ❌ 4 lignes vides créées automatiquement
- ❌ Suppression manuelle nécessaire régulièrement
- ❌ Interface de contrôle confuse (GPIO sans nom)
- ❌ Aucune prévention au niveau base de données

### Après la correction
- ✅ Une seule ligne par GPIO, toujours
- ✅ Noms et descriptions clairs pour tous les GPIO
- ✅ Contrainte UNIQUE empêche les doublons définitivement
- ✅ Code plus robuste (INSERT ON DUPLICATE KEY UPDATE)
- ✅ Interface de contrôle plus professionnelle

---

## 📞 Support

En cas de problème pendant l'application :
1. Consulter `migrations/README.md` → Section "Dépannage"
2. Vérifier les logs : `cronlog.txt`
3. Restaurer le backup si nécessaire

---

**Version** : 4.5.17  
**Date** : 13 octobre 2025  
**Type** : PATCH (bug fix)  
**Statut** : ✅ Code prêt - ⏳ Migrations à appliquer

