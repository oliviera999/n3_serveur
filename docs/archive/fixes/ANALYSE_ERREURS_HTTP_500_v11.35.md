# 🚨 Analyse Erreurs HTTP 500 - v11.35

**Date**: 14 Octobre 2025 10:47  
**Problème**: Serveur retourne HTTP 500 systématiquement  
**Impact**: Aucune donnée enregistrée (14 payloads en queue)  

---

## 🔍 Observation

### Logs ESP32
```
[HTTP] → http://iot.olution.info/ffp3/post-data-test (460 bytes)
[HTTP] payload: api_key=...&version=11.35&tempsGros=2&tempsPetits=2&...
[HTTP] 🌐 Using HTTP (attempt 1/3)
[HTTP] ← code 500, 14 bytes
[HTTP] === DÉBUT RÉPONSE ===
Erreur serveur
[HTTP] === FIN RÉPONSE ===
[HTTP] ⚠️ Retry 2/3 in 200 ms...
[HTTP] ← code 500 (tentative 2/3)
[HTTP] ← code 500 (tentative 3/3)
[Network] sendFullUpdate FAILED
[DataQueue] ✓ Payload enregistré (460 bytes, total: 13 entrées)
```

**Constat**: 100% des requêtes échouent → Serveur ne peut pas traiter

---

## 🎯 Causes Probables

### Cause 1: Modifications PHP Non Déployées (PROBABLE)

Les modifications faites à `ffp3/public/post-data.php` sont **locales uniquement** :
- ✅ Code modifié localement
- ❌ **PAS encore déployé** sur serveur distant

**Solution**: Déployer les fichiers PHP sur le serveur

### Cause 2: Colonnes BDD Manquantes (PROBABLE)

La table `ffp3Data2` ne contient peut-être pas les nouvelles colonnes :
```sql
-- Colonnes ajoutées dans SensorRepository.php:
tempsGros
tempsPetits  
tempsRemplissageSec
limFlood
WakeUp
FreqWakeUp
```

**Solution**: Ajouter colonnes manquantes en BDD

### Cause 3: Erreur dans Code PHP (POSSIBLE)

Le code ajouté peut contenir une erreur PHP qui cause une exception.

**Solution**: Vérifier logs serveur PHP

---

## ✅ Solutions Immédiates

### Solution 1: Vérifier Structure BDD

```sql
-- Vérifier colonnes table ffp3Data2
DESCRIBE ffp3Data2;

-- Si colonnes manquantes, les ajouter:
ALTER TABLE ffp3Data2 ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3Data2 ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros;
ALTER TABLE ffp3Data2 ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits;
ALTER TABLE ffp3Data2 ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec;
ALTER TABLE ffp3Data2 ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood;
ALTER TABLE ffp3Data2 ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp;

-- Même chose pour ffp3Data (PROD)
ALTER TABLE ffp3Data ADD COLUMN tempsGros INT DEFAULT NULL AFTER bouffeSoir;
ALTER TABLE ffp3Data ADD COLUMN tempsPetits INT DEFAULT NULL AFTER tempsGros;
ALTER TABLE ffp3Data ADD COLUMN tempsRemplissageSec INT DEFAULT NULL AFTER tempsPetits;
ALTER TABLE ffp3Data ADD COLUMN limFlood INT DEFAULT NULL AFTER tempsRemplissageSec;
ALTER TABLE ffp3Data ADD COLUMN WakeUp INT DEFAULT NULL AFTER limFlood;
ALTER TABLE ffp3Data ADD COLUMN FreqWakeUp INT DEFAULT NULL AFTER WakeUp;
```

### Solution 2: Option Temporaire - Ne Pas Envoyer Nouveaux Champs

Si les colonnes n'existent pas, on peut temporairement désactiver leur envoi côté ESP32.

**OU** modifier le code PHP pour ignorer gracieusement les colonnes manquantes.

---

## 🔧 Actions Prioritaires

### Priorité 1: Vérifier Logs Serveur
```bash
# Se connecter au serveur
ssh user@iot.olution.info

# Consulter logs erreurs PHP
tail -f /path/to/ffp3/var/logs/post-data.log
# OU
tail -f /var/log/apache2/error.log
```

### Priorité 2: Ajouter Colonnes BDD
Si erreur SQL "Unknown column", exécuter les ALTER TABLE ci-dessus.

### Priorité 3: Déployer Code PHP
Une fois BDD prête, déployer les fichiers modifiés.

---

## ⚠️ Impact Actuel

**Données en queue**: 14 payloads  
**Durée depuis dernière sync**: Inconnue  
**Risque**: Perte données si queue déborde (max 50)  

**Bonne nouvelle**: 
- ✅ Système queue fonctionne
- ✅ Données sauvegardées localement
- ✅ Seront envoyées quand serveur fonctionnera

---

## 📊 État Système

### ESP32 v11.35
- ✅ Fonctionne correctement
- ✅ HC-SR04 valeurs stables (208-209 cm)
- ✅ Pas d'erreur watchdog
- ✅ Pas d'erreur NVS
- ✅ Queue gère échecs serveur

### Serveur (v11.35 - Sans modifications)
- ❌ Code PHP ancien (sans update outputs)
- ❌ BDD peut-être sans nouvelles colonnes
- ❌ Toutes requêtes échouent (500)

---

## 🎯 Plan Action Immédiat

1. **Vérifier structure BDD** (colonnes manquantes ?)
2. **Consulter logs serveur PHP** (erreur exacte ?)
3. **Déployer code PHP v11.36** si BDD OK
4. **Tester à nouveau** après corrections

Veux-tu que je crée un script SQL pour ajouter les colonnes manquantes ?


