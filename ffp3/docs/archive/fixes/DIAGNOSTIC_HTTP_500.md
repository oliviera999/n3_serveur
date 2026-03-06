# 🔍 Diagnostic HTTP 500 - Post Data Test

**Version**: 11.35  
**Date**: 14 Octobre 2025  
**Problème**: HTTP 500 sans logs PHP  

---

## 🚨 Problème

- ESP32 POST → `http://iot.olution.info/ffp3/post-data-test`
- Serveur retourne **HTTP 500**
- **Logs PHP vides** (erreur catchée silencieusement)

---

## 🔎 Cause Probable

Le fichier `post-data-test.php` legacy utilise :

```php
if ($conn->multi_query($sql) === TRUE) {
    echo "New record created successfully";
} else {
    echo "Erreur serveur";  // ← Retourne ça SANS logguer l'erreur
}
```

**Résultat** : L'erreur est catchée mais **jamais loggée** !

---

## ✅ Actions de Diagnostic

### 1. Test PowerShell Local

```powershell
.\test_endpoint_diagnostic.ps1
```

**Ce qu'il fait** :
- ✓ Teste POST avec payload complet
- ✓ Vérifie si fichier existe (GET)
- ✓ Vérifie outputs state (GET)

**Résultats attendus** :
- POST → HTTP 500 (confirme le problème)
- GET fichier → HTTP 405 (fichier existe, refuse GET) ✓
- GET outputs → JSON valide ✓

---

### 2. Diagnostic PHP Serveur

**Uploader sur serveur** :
```bash
scp ffp3/public/diagnostic-post.php user@iot.olution.info:/path/to/ffp3/public/
```

**Exécuter** :
```
http://iot.olution.info/ffp3/public/diagnostic-post.php
```

**Ce qu'il teste** :
1. ✓ Connexion BDD
2. ✓ Tables `ffp3Data2` et `ffp3Outputs2` existent
3. ✓ 21 GPIO présents dans ffp3Outputs2
4. ✓ INSERT ffp3Data2 fonctionne
5. ✓ UPDATE ffp3Outputs2 fonctionne
6. ✓ multi_query() fonctionne

**Résultats possibles** :

#### Cas A: Tous ✓ = Erreur de syntaxe PHP
```
✓ Connexion OK
✓ Tables OK
✓ GPIO OK (21/21)
✓ INSERT OK
✓ UPDATE OK
✓ multi_query OK

→ Le problème vient des variables POST dans post-data-test.php
```

**Solution** : Ajouter logging dans `post-data-test.php` :

```php
<?php
// EN HAUT du fichier
error_log("[POST-DATA-TEST] Début traitement " . date('Y-m-d H:i:s'));
error_log("[POST-DATA-TEST] POST vars: " . print_r($_POST, true));

// DANS le else
} else {
    $error = $conn->error;
    error_log("[POST-DATA-TEST] SQL Error: $error");
    error_log("[POST-DATA-TEST] SQL: $sql");
    echo "Erreur serveur";
}
?>
```

#### Cas B: Erreur BDD ✗
```
✓ Connexion OK
✗ Table ffp3Data2 NOT FOUND

→ Mauvaise base de données
```

**Solution** : Vérifier `post-data-test.php` utilise `oliviera_iot`

#### Cas C: GPIO manquants ✗
```
✓ Connexion OK
✓ Tables OK
✗ GPIO manquants (18/21)
  GPIO manquants:
    - GPIO 111
    - GPIO 112
    - GPIO 113

→ Table ffp3Outputs2 incomplète
```

**Solution** : Exécuter `ffp3/migrations/INIT_GPIO_BASE_ROWS.sql`

#### Cas D: multi_query échoue ✗
```
✓ Connexion OK
✓ Tables OK
✓ GPIO OK
✗ multi_query ERREUR: Column 'xxx' not found

→ Colonne manquante dans ffp3Data2
```

**Solution** : Exécuter `ffp3/migrations/ADD_MISSING_COLUMNS_v11.36.sql`

---

## 🎯 Plan d'Action

### Étape 1: Test Local
```powershell
cd "C:\Users\olivi\Mon Drive\travail\##olution\##Projets\##prototypage\platformIO\Projects\ffp5cs"
.\test_endpoint_diagnostic.ps1
```

### Étape 2: Diagnostic Serveur

**Option A - Navigateur** :
```
http://iot.olution.info/ffp3/public/diagnostic-post.php
```

**Option B - Curl** :
```powershell
curl.exe -s http://iot.olution.info/ffp3/public/diagnostic-post.php
```

### Étape 3: Selon Résultat

**Si diagnostic OK** → Ajouter logging dans `post-data-test.php`

**Si erreur BDD** → Corriger configuration/migrations

**Si GPIO manquants** → Exécuter INIT_GPIO_BASE_ROWS.sql

**Si colonnes manquantes** → Exécuter ADD_MISSING_COLUMNS_v11.36.sql

---

## 🔧 Correctifs Possibles

### Correctif A: Logging Détaillé

Modifier `/path/to/ffp3/post-data-test.php` :

```php
<?php
// Activer logs
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/post-data-errors.log');

// Logger début
error_log("=== POST DATA " . date('Y-m-d H:i:s') . " ===");
error_log("POST: " . print_r($_POST, true));

// ... code existant ...

// Dans le multi_query
if ($conn->multi_query($sql) === TRUE) {
    error_log("SUCCESS: Data inserted");
    echo "New record created successfully";
} else {
    error_log("ERROR: " . $conn->error);
    error_log("SQL: " . substr($sql, 0, 500));
    echo "Erreur serveur: " . $conn->error;  // Afficher l'erreur
}
?>
```

Puis checker :
```bash
tail -f /path/to/ffp3/post-data-errors.log
```

### Correctif B: Variables POST Manquantes

Si certaines variables POST sont `undefined`, ajouter defaults :

```php
$api_key = $_POST['api_key'] ?? '';
$sensor = $_POST['sensor'] ?? 'unknown';
$version = $_POST['version'] ?? '0.0';
// etc...
```

### Correctif C: Colonnes BDD

Vérifier que `ffp3Data2` a bien **25 colonnes** :

```sql
DESCRIBE ffp3Data2;
```

Si colonnes manquantes, exécuter :

```sql
-- ADD_MISSING_COLUMNS_v11.36.sql
ALTER TABLE ffp3Data2 
ADD COLUMN IF NOT EXISTS tempsGros INT NULL,
ADD COLUMN IF NOT EXISTS tempsPetits INT NULL,
ADD COLUMN IF NOT EXISTS tempsRemplissageSec INT NULL,
ADD COLUMN IF NOT EXISTS limFlood INT NULL,
ADD COLUMN IF NOT EXISTS WakeUp INT NULL,
ADD COLUMN IF NOT EXISTS FreqWakeUp INT NULL;
```

---

## 📊 Checklist

- [ ] Test local PowerShell exécuté
- [ ] Diagnostic PHP uploadé sur serveur
- [ ] Diagnostic PHP exécuté (navigateur/curl)
- [ ] Erreurs identifiées
- [ ] Logging ajouté dans post-data-test.php
- [ ] Migrations BDD appliquées si nécessaire
- [ ] Test ESP32 POST réussi (HTTP 200)
- [ ] Chauffage reste allumé après activation

---

## 🚀 Commandes Rapides

```powershell
# 1. Test local
.\test_endpoint_diagnostic.ps1

# 2. Diagnostic serveur
curl.exe http://iot.olution.info/ffp3/public/diagnostic-post.php

# 3. Test POST manuel
curl.exe -X POST http://iot.olution.info/ffp3/post-data-test `
  -d "api_key=fdGTMoptd5CD2ert3" `
  -d "sensor=test" `
  -d "version=11.35" `
  -d "TempAir=25" `
  -d "Humidite=60" `
  -d "TempEau=28" `
  -d "EauPotager=200" `
  -d "EauAquarium=200" `
  -d "EauReserve=200" `
  -d "diffMaree=0" `
  -d "Luminosite=1000" `
  -d "etatPompeAqua=0" `
  -d "etatPompeTank=0" `
  -d "etatHeat=1" `
  -d "etatUV=1" `
  -d "bouffeMatin=8" `
  -d "bouffeMidi=12" `
  -d "bouffeSoir=19" `
  -d "bouffePetits=0" `
  -d "bouffeGros=0" `
  -d "tempsGros=2" `
  -d "tempsPetits=2" `
  -d "aqThreshold=18" `
  -d "tankThreshold=80" `
  -d "chauffageThreshold=18" `
  -d "mail=test@test.com" `
  -d "mailNotif=checked" `
  -d "resetMode=0" `
  -d "tempsRemplissageSec=5" `
  -d "limFlood=8" `
  -d "WakeUp=0" `
  -d "FreqWakeUp=6"
```

---

## 🎯 Prochaine Étape

**Exécute le diagnostic** :

```powershell
.\test_endpoint_diagnostic.ps1
```

Puis donne-moi les résultats pour qu'on identifie la cause exacte du HTTP 500 !

