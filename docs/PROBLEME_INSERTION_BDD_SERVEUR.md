# 🔴 PROBLÈME: Données non insérées en BDD - Endpoint serveur

**Date:** 2025-10-11  
**Version ESP32:** 11.04  
**Symptôme:** POST envoyé avec succès (HTTP 200) mais données non insérées en base de données

---

## 🎯 RÉSUMÉ DU PROBLÈME

### ✅ Ce qui fonctionne (ESP32)

- POST envoyé correctement toutes les 2 minutes ✅
- Payload complet (487 bytes) avec toutes les données ✅
- Serveur répond HTTP 200 OK ✅
- Aucune erreur côté ESP32 ✅

### ❌ Ce qui ne fonctionne PAS (Serveur)

- **Le serveur renvoie une page HTML complète (4079 bytes) au lieu de traiter les données**
- Les données ne sont probablement **PAS insérées en base de données**
- L'endpoint semble renvoyer une page de listing au lieu de traiter le POST

---

## 🔍 ANALYSE DÉTAILLÉE

### Configuration actuelle

**ESP32 (environnement TEST):**
```
BASE_URL: http://iot.olution.info
ENDPOINT: /ffp3/public/post-data-test
URL COMPLÈTE: http://iot.olution.info/ffp3/public/post-data-test
```

**Données envoyées:**
```
Method: POST
Content-Type: application/x-www-form-urlencoded
Payload size: 487 bytes

api_key=<API_KEY .env>&
sensor=esp32-wroom&
version=11.04&
TempAir=26.4&
Humidite=63.0&
TempEau=28.0&
EauPotager=209&
EauAquarium=208&
EauReserve=208&
diffMaree=1&
Luminosite=1741&
etatPompeAqua=1&
etatPompeTank=0&
etatHeat=0&
etatUV=0&
bouffeMatin=8&
bouffeMidi=12&
bouffeSoir=19&
tempsGros=10&
tempsPetits=10&
... (+ autres champs)
```

### Réponse du serveur

**Code HTTP:** 200 OK ✅ (mais trompeur)

**Contenu réponse:** 4079 bytes de HTML

```html
<!DOCTYPE HTML>
<html>
<head>
<title>n3 iot datas</title>
<meta charset=utf-8 />
<meta name=viewport content="width=device-width, initial-scale=1, user-scalable=no"/>
<link rel=stylesheet href="https://iot.olution.info/assets/css/main.css"/>
<noscript><link rel=stylesheet href="https://iot.olution.i ... (truncated)
```

**Analyse:**
- C'est une page HTML complète, pas une réponse JSON d'API
- Le titre "n3 iot datas" suggère une page de visualisation
- Le serveur a probablement renvoyé une page de listing au lieu de traiter le POST

---

## 🔴 CAUSES POSSIBLES

### 1. Endpoint n'existe pas ou mal configuré

**Symptôme:** Le serveur répond 200 mais affiche une page par défaut

**Causes possibles:**
- L'endpoint `/ffp3/public/post-data-test` n'existe pas
- Le routeur/dispatcher redirige vers une page d'accueil
- Le fichier PHP n'existe pas à cet emplacement

**Action:** Vérifier l'existence du fichier sur le serveur
```bash
# Sur le serveur
ls -la /var/www/html/ffp3/public/post-data-test*
# ou
ls -la /var/www/html/ffp3/public/post-data-test.php
```

---

### 2. Méthode HTTP non acceptée

**Symptôme:** Le serveur accepte GET mais ignore POST

**Causes possibles:**
- Le fichier PHP n'a pas de code pour traiter `$_POST`
- Le serveur est configuré pour GET uniquement
- .htaccess bloque les requêtes POST

**Action:** Vérifier le code PHP
```php
<?php
// Le fichier doit avoir quelque chose comme:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Traitement des données POST
    $data = $_POST;
    // Insertion en BDD...
} else {
    // Peut-être qu'il affiche juste une page HTML si GET
}
?>
```

---

### 3. Endpoint de TEST non fonctionnel

**Symptôme:** L'endpoint `-test` existe mais ne fait rien

**Causes possibles:**
- L'endpoint de test est un stub/placeholder
- Il affiche juste les données sans les insérer en BDD
- Il n'a pas été implémenté complètement

**Action:** Comparer avec l'endpoint de PRODUCTION
- Tester avec `/ffp3/public/post-data` (sans -test)
- Vérifier si l'endpoint de production fonctionne

---

### 4. Erreur PHP silencieuse

**Symptôme:** Le serveur répond 200 mais le code PHP crash

**Causes possibles:**
- Erreur dans le code PHP (connexion BDD échoue)
- Exception non gérée
- Le script affiche une page d'erreur HTML au lieu de JSON

**Action:** Consulter les logs PHP
```bash
# Sur le serveur
tail -f /var/log/apache2/error.log
# ou
tail -f /var/log/nginx/error.log
# ou
tail -f /var/www/html/ffp3/logs/error.log
```

---

### 5. Problème de permissions BDD

**Symptôme:** Le script PHP s'exécute mais ne peut pas insérer

**Causes possibles:**
- Utilisateur BDD n'a pas les droits INSERT
- Table n'existe pas
- Connexion BDD échoue

**Action:** Vérifier les logs PHP et tester manuellement
```sql
-- Tester l'insertion manuelle
INSERT INTO iot_data (sensor, version, temp_air, humidity, ...) 
VALUES ('esp32-wroom', '11.04', 26.4, 63.0, ...);
```

---

## 🔧 ACTIONS DE DIAGNOSTIC

### 1. Vérifier l'existence de l'endpoint

**Sur le serveur:**
```bash
cd /var/www/html/ffp3/public/
ls -la post-data*
cat post-data-test  # ou post-data-test.php
```

### 2. Tester l'endpoint manuellement

**Avec curl:**
```bash
curl -X POST http://iot.olution.info/ffp3/public/post-data-test \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "api_key=$API_KEY&sensor=test&version=11.04&TempAir=25.0" \
  -v
```

**Résultat attendu:**
- Soit: `{"status":"ok","inserted":true}` (JSON)
- Soit: Code HTTP 201 Created
- **PAS:** Une page HTML complète

### 3. Consulter les logs serveur

```bash
# Logs Apache
tail -f /var/log/apache2/access.log | grep post-data
tail -f /var/log/apache2/error.log

# Logs Nginx
tail -f /var/log/nginx/access.log | grep post-data
tail -f /var/log/nginx/error.log

# Logs PHP
tail -f /var/www/html/ffp3/logs/error.log
```

### 4. Vérifier la base de données

```sql
-- Dernières entrées insérées
SELECT * FROM iot_data 
WHERE sensor = 'esp32-wroom' 
ORDER BY created_at DESC 
LIMIT 10;

-- Compter les entrées d'aujourd'hui
SELECT COUNT(*) FROM iot_data 
WHERE sensor = 'esp32-wroom' 
AND DATE(created_at) = CURDATE();
```

---

## 💡 SOLUTIONS PROPOSÉES

### Solution 1: Utiliser l'endpoint de PRODUCTION

**Action:** Changer l'environnement de compilation

**Fichier:** `platformio.ini`

```ini
# AVANT (TEST)
[env:wroom-test]
build_flags = 
    -DPROFILE_TEST
    ...

# APRÈS (PRODUCTION)
[env:wroom-prod]
build_flags = 
    -DPROFILE_PROD
    ...
```

**Recompiler:**
```bash
pio run -e wroom-prod -t upload
```

**Résultat:** Utilisera `/ffp3/public/post-data` au lieu de `-test`

---

### Solution 2: Corriger l'endpoint de TEST côté serveur

**Créer/Corriger:** `/var/www/html/ffp3/public/post-data-test.php`

```php
<?php
// Configuration BDD
$host = 'localhost';
$dbname = 'iot_database';
$user = 'iot_user';
$pass = 'password';

// Connexion BDD
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log("BDD Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Vérifier que c'est un POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Récupérer les données POST
$data = $_POST;

// Vérifier l'API key
if (!isset($data['api_key']) || !hash_equals($_ENV['API_KEY'] ?? '', (string) $data['api_key'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API key']);
    exit;
}

// Préparer l'insertion
$sql = "INSERT INTO iot_data (
    sensor, version, temp_air, humidity, temp_eau,
    eau_potager, eau_aquarium, eau_reserve, diff_maree, luminosite,
    etat_pompe_aqua, etat_pompe_tank, etat_heat, etat_uv,
    bouffe_matin, bouffe_midi, bouffe_soir,
    created_at
) VALUES (
    :sensor, :version, :temp_air, :humidity, :temp_eau,
    :eau_potager, :eau_aquarium, :eau_reserve, :diff_maree, :luminosite,
    :etat_pompe_aqua, :etat_pompe_tank, :etat_heat, :etat_uv,
    :bouffe_matin, :bouffe_midi, :bouffe_soir,
    NOW()
)";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sensor' => $data['sensor'] ?? 'unknown',
        ':version' => $data['version'] ?? '0.0',
        ':temp_air' => $data['TempAir'] ?? null,
        ':humidity' => $data['Humidite'] ?? null,
        ':temp_eau' => $data['TempEau'] ?? null,
        ':eau_potager' => $data['EauPotager'] ?? null,
        ':eau_aquarium' => $data['EauAquarium'] ?? null,
        ':eau_reserve' => $data['EauReserve'] ?? null,
        ':diff_maree' => $data['diffMaree'] ?? null,
        ':luminosite' => $data['Luminosite'] ?? null,
        ':etat_pompe_aqua' => $data['etatPompeAqua'] ?? 0,
        ':etat_pompe_tank' => $data['etatPompeTank'] ?? 0,
        ':etat_heat' => $data['etatHeat'] ?? 0,
        ':etat_uv' => $data['etatUV'] ?? 0,
        ':bouffe_matin' => $data['bouffeMatin'] ?? null,
        ':bouffe_midi' => $data['bouffeMidi'] ?? null,
        ':bouffe_soir' => $data['bouffeSoir'] ?? null,
    ]);
    
    // Log pour debug
    error_log("POST data-test: Inserted row ID " . $pdo->lastInsertId());
    
    // Réponse JSON (PAS HTML!)
    http_response_code(201);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'inserted' => true,
        'id' => $pdo->lastInsertId(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch(PDOException $e) {
    error_log("Insert Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Insert failed']);
}
?>
```

---

### Solution 3: Ajouter logging côté ESP32

**Pour voir ce que le serveur renvoie vraiment:**

**Fichier:** `src/web_client.cpp` ligne 68-75

```cpp
// Modifier pour afficher TOUTE la réponse
if (code > 0) {
    response = _http.getString();
    Serial.printf("[HTTP] ← code %d, %u bytes\n", code, response.length());
    
    // NOUVEAU: Afficher toute la réponse pour diagnostic
    Serial.println("[HTTP] FULL RESPONSE:");
    Serial.println(response);
    Serial.println("[HTTP] END RESPONSE");
}
```

---

## 📋 CHECKLIST DE VÉRIFICATION

### Sur le serveur web

- [ ] Fichier `/ffp3/public/post-data-test.php` existe
- [ ] Fichier est exécutable (chmod 755 ou 644)
- [ ] Propriétaire correct (www-data ou apache)
- [ ] Code PHP traite les requêtes POST
- [ ] Connexion BDD fonctionne
- [ ] Table `iot_data` existe et est accessible
- [ ] Utilisateur BDD a les droits INSERT
- [ ] Logs PHP ne montrent pas d'erreurs
- [ ] Test manuel avec curl fonctionne

### Sur la base de données

- [ ] Table `iot_data` existe
- [ ] Colonnes correspondent aux champs envoyés
- [ ] Utilisateur BDD a les permissions
- [ ] Pas de contraintes bloquantes (UNIQUE, FK)
- [ ] Insertion manuelle fonctionne

### Sur l'ESP32

- [ ] POST envoyé avec succès (✅ déjà confirmé)
- [ ] Payload correct (✅ déjà confirmé)
- [ ] Fréquence correcte (✅ déjà confirmé)

---

## 🎯 RECOMMANDATION FINALE

### PRIORITÉ 1: Diagnostic serveur

1. **Vérifier que l'endpoint existe:**
   ```bash
   ssh user@iot.olution.info
   cd /var/www/html/ffp3/public/
   ls -la post-data*
   ```

2. **Tester manuellement avec curl:**
   ```bash
   curl -X POST http://iot.olution.info/ffp3/public/post-data-test \
     -H "Content-Type: application/x-www-form-urlencoded" \
     -d "api_key=$API_KEY&sensor=test&version=11.04&TempAir=25.0" \
     -i
   ```

3. **Consulter les logs:**
   ```bash
   tail -f /var/log/apache2/error.log
   ```

### PRIORITÉ 2: Solution temporaire

**Si l'endpoint de test ne fonctionne pas, compiler en mode PRODUCTION:**

```bash
pio run -e wroom-prod -t upload
```

Cela utilisera `/ffp3/public/post-data` au lieu de `-test`.

---

## 📊 CONCLUSION

**Le problème est à 100% côté SERVEUR, pas côté ESP32.**

L'ESP32 fait son travail correctement:
- ✅ POST envoyé toutes les 2 minutes
- ✅ Payload complet et valide
- ✅ Serveur répond 200 OK

Mais le serveur ne traite PAS les données:
- ❌ Renvoie une page HTML au lieu de JSON
- ❌ Probablement ne fait pas d'insertion en BDD
- ❌ Endpoint `-test` mal configuré ou inexistant

**Action immédiate:** Vérifier la configuration de l'endpoint côté serveur.

---

**Fin du rapport - 2025-10-11**

