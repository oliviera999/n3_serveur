# 🌐 Endpoints ESP32 ↔ Serveur - Configuration Complète

**Version ESP32**: 11.205  
**Version Serveur**: 11.36  
**Date**: 15 Février 2026  

---

## ⚠️ Périmètre de ce document (serveur distant uniquement)

Ce document décrit les **endpoints du serveur distant** (ffp3 sur iot.olution.info) utilisés par l’ESP32 : POST données, GET state, heartbeat. **Le serveur distant n’appelle jamais l’ESP32** ; tout le flux est initié par le firmware.

- **API locale (serveur embarqué ESP32)** : HTTP port 80, WebSocket port 81 path `/ws`. Documentée dans le dépôt firmware : `docs/technical/api-endpoints.yaml` et `docs/technical/VARIABLE_NAMING.md` (noms de champs alignés firmware ↔ serveur distant).

---

## 📍 Endpoints Utilisés par ESP32

### Environnement Actif: **TEST** (`wroom-test`)

**Configuration**: `platformio.ini` ligne 90
```ini
[env:wroom-test]
build_flags = 
    -DPROFILE_TEST  ← Environnement TEST actif
```

**Endpoints** (`include/config.h` namespace `ServerConfig`):

#### 1️⃣ POST Data (Envoi données capteurs + états)
```cpp
POST_DATA_ENDPOINT = "/ffp3/post-data-test"
```

**URL Complète**:
```
http://iot.olution.info/ffp3/post-data-test
```

**Route serveur (Slim)**:
```
/ffp3/post-data-test → PostDataController::handle()
```

#### 2️⃣ GET Outputs State (Récupération états distants)
```cpp
OUTPUT_ENDPOINT = "/ffp3/api/outputs-test/state"
```

**URL Complète**:
```
http://iot.olution.info/ffp3/api/outputs-test/state
```

**Fichier serveur**:
```
/path/to/ffp3/public/index.php  ← Route Slim Framework
  └─> OutputController::getOutputsState()
```

---

## 🔄 Comparaison Environnements

| Aspect | TEST (wroom-test) | TEST3 (wroom-s3-test) | PROD (wroom-prod) | S3 PROD (wroom-s3-prod) |
|--------|-------------------|------------------------|-------------------|--------------------------|
| **Profil** | `PROFILE_TEST` | `PROFILE_TEST` + `USE_TEST3_ENDPOINTS` | `PROFILE_PROD` | `BOARD_S3` + `PROFILE_PROD` |
| **Endpoint POST** | `/ffp3/post-data-test` | `/ffp3/post-data3-test` | `/ffp3/post-data` | `/ffp3/post-data3` |
| **Endpoint GET** | `/ffp3/api/outputs-test/state` | `/ffp3/api/outputs3-test/state` | `/ffp3/api/outputs/state` | `/ffp3/api/outputs3/state` |
| **Endpoint Heartbeat** | `/ffp3/heartbeat-test` | `/ffp3/heartbeat3-test` | `/ffp3/heartbeat` | `/ffp3/heartbeat3` |
| **Table Data** | `ffp3Data2` | `ffp3Data3` | `ffp3Data` | `ffp3Data4` |
| **Table Outputs** | `ffp3Outputs2` | `ffp3Outputs3` | `ffp3Outputs` | `ffp3Outputs4` |
| **Page contrôle** | `/aquaponie-control-test` | `/aquamobile-control-test` | `/aquaponie-control` | `/aquamobile-control` |
| **Page aquaponie** | `/aquaponie-test` | `/aquamobile-test` | `/aquaponie` | `/aquamobile` |

**S3 PROD** : Environnement dédié aux ESP32-S3 en production (`wroom-s3-prod`). Routes serveur sans suffixe `-test` (`post-data3`, `api/outputs3/state`, `heartbeat3`). Configuration firmware dans `include/config.h` (condition `BOARD_S3 && PROFILE_PROD`). Middleware serveur `EnvironmentMiddleware('s3')` → tables `ffp3Data4`, `ffp3Outputs4`, board `5`, `ffp3Heartbeat4`.

---

## ⏱ Timeouts côté client (firmware) et serveur

Le client ESP32 utilise les timeouts suivants (définis dans `include/config.h`, namespace `NetworkConfig`) :

- **POST post-data** : **18 s** (`HTTP_POST_TIMEOUT_MS`) — dérogation à la règle projet 5 s, justifiée par la latence réseau (4G, hébergement). Le RPC côté firmware attend au plus **26 s** (`HTTP_POST_RPC_TIMEOUT_MS`) avant d’abandonner.
- **GET outputs/state** : 10 s (`OUTPUTS_STATE_HTTP_TIMEOUT_MS`).

Le serveur doit répondre au POST dans le délai client (18 s) pour éviter timeout côté ESP32.

- **PHP** : `PostDataController::handle()` appelle `set_time_limit(30)` au début de la requête (marge par rapport aux 18 s client). La réponse 200 est renvoyée immédiatement après l’insert BDD, la synchro outputs, l’invalidation cache et la mise à jour du timestamp board (aucun appel externe bloquant).
- **À vérifier sur l'hébergement** :
  - `max_execution_time` (php.ini) ≥ 30 s pour les requêtes POST `/ffp3/post-data`, `/ffp3/post-data-test`, `/ffp3/post-data3-test` et `/ffp3/post-data3`.
  - Nginx : `proxy_read_timeout` (et éventuellement `fastcgi_read_timeout`) ≥ 30 s.
  - Apache : `Timeout` et `ProxyTimeout` ≥ 30 s si reverse proxy vers PHP.
- **Réduction de latence** : la route POST fait 1 INSERT (données capteurs) + 1 UPDATE groupé (états GPIO via `CASE gpio WHEN … THEN … END`) + 1 UPDATE (dernière requête board) + invalidation cache en mémoire, pour limiter le nombre d'allers-retours BDD.

---

## 📏 Validation des champs POST (PostDataController)

- **sensor**, **version** : tronqués à 30 caractères (taille colonne BDD).
- **mail**, **mailNotif** : tronqués à 255 caractères avant insertion (évite erreur SQL si colonnes VARCHAR(255)).  
  Fichier : `ffp3/src/Controller/PostDataController.php`.

### Timestamp et signature HMAC (optionnel)

- Si le client envoie **timestamp** et **signature** : le serveur valide la signature HMAC. La fenêtre de validité est définie par la variable d'environnement **SIG_VALID_WINDOW** (secondes, défaut 300). Le RTC de l'ESP32 doit rester synchronisé (NTP + correction de dérive) à ± cette fenêtre pour que la signature soit acceptée. Actuellement le firmware n'envoie pas ces champs dans le POST post-data principal ; l'API reste en mode compatibilité (validation par API_KEY).
- Champ optionnel **device_time** : non utilisé aujourd'hui ; peut être ajouté à l'avenir (epoch ou ISO) pour corrélation logs ESP32 / serveur et diagnostic. Non obligatoire pour le contrat actuel.

---

## 🔧 Diagnostic : « Les commandes distantes n’ont pas d’effet sur l’ESP32 »

**Contrat côté serveur** : les commandes envoyées depuis la page de contrôle (toggle, paramètres) sont enregistrées dans la table **correspondant à l’environnement de la page** :

- **Page `/aquaponie-control` (PROD)** → toggle/paramètres → table **ffp3Outputs** (PROD).
- **Page `/aquaponie-control-test` (TEST)** → toggle/paramètres → table **ffp3Outputs2** (TEST).
- **Page `/aquamobile-control-test` (TEST3, ex. ESP32-S3 test)** → toggle/paramètres → table **ffp3Outputs3** (TEST3).
- **Page `/aquamobile-control` (S3 PROD)** → toggle/paramètres → table **ffp3Outputs4** (S3 PROD).

**Protection des changements web** : Les changements faits depuis l'interface web sont protégés pendant 10 s (20 s pour nourrissage) contre l'écrasement par le POST ESP ; voir `SYNCHRONISATION_BIDIRECTIONNELLE.md`.

**Pour que l'ESP32 applique ces commandes**, il doit **lire la même table** en faisant un GET sur le **même environnement** :

- Si vous pilotez depuis **aquaponie-control-test** : l’ESP32 doit faire `GET /ffp3/api/outputs-test/state` (table ffp3Outputs2).
- Si vous pilotez depuis **aquamobile-control-test** (profil wroom-s3-test) : l'ESP32 doit faire `GET /ffp3/api/outputs3-test/state` (table ffp3Outputs3).
- Si vous pilotez depuis **aquamobile-control** (profil wroom-s3-prod) : l’ESP32 doit faire `GET /ffp3/api/outputs3/state` (table ffp3Outputs4).
- Si vous pilotez depuis **aquaponie-control** (prod) : l’ESP32 doit faire `GET /ffp3/api/outputs/state` (table ffp3Outputs).

**À vérifier en priorité (côté ESP32)** :

1. **URL de poll** : l’ESP32 utilise-t-il `/api/outputs-test/state` quand vous êtes en env test, et `/api/outputs/state` en prod ?
2. **Application des valeurs** : le firmware applique-t-il bien les champs reçus (GPIO, state) aux relais/paramètres après chaque GET réussi ?

Si l’URL de poll et la page de contrôle sont sur le même environnement (test↔test ou prod↔prod), le serveur renvoie bien les dernières valeurs écrites par la page. Si l’effet n’apparaît pas sur l’ESP32, la cause est alors côté firmware (poll, parsing ou application des états).

---

## 🔍 Diagnostic Erreur HTTP 500

Si `/ffp3/post-data-test`, `/ffp3/post-data3-test`, `/ffp3/post-data3` ou `/ffp3/post-data` renvoie 500 :

### Possibilité 1: **Erreur PHP côté Slim**
```
Consulter les logs PHP/serveur web (ex: /var/log/apache2/error.log)
```

### Possibilité 2: **Erreur SQL (INSERT/UPDATE)**
Vérifier que **tous les GPIO existent** dans `ffp3Outputs2` :

```sql
SELECT gpio, name, state 
FROM ffp3Outputs2 
WHERE gpio IN (2, 15, 16, 18, 100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114, 115, 116)
ORDER BY gpio;

-- Doit retourner 21 lignes
-- Si lignes manquantes, exécuter: ffp3/migrations/INIT_GPIO_BASE_ROWS.sql
```

---

## 🔍 Diagnostic : données non publiées en ligne (mails OK)

Lorsque l’ESP32 cesse d’envoyer des données alors que les mails partent correctement, vérifier côté **serveur** :

1. **Logs PHP** : rechercher `PostData OK sensor=...` (succès 200) vs `PostData: ...`, `Clé API incorrecte` (401), `Champs requis manquants` (400), `Erreur insertion données` (500). Corréler avec les horodatages des logs série firmware (`[HTTP] POST ...`, code réponse, durée).
2. **Cohérence API_KEY** : la valeur dans `.env` (`API_KEY`) doit être identique à celle du firmware (`include/config.h` ou secrets). En cas de signature HMAC, vérifier aussi `API_SIG_SECRET` et la fenêtre `SIG_VALID_WINDOW`.
3. **Durée d’exécution** : si les requêtes POST dépassent systématiquement ~15–18 s, le client ESP32 peut timeout avant de recevoir la réponse. Vérifier les timings dans les logs (`timing_ms: insert=... sync=... total=...`) et optimiser BDD/cache si besoin.

Côté **firmware**, les logs série à rechercher : `[netRPC] Pool plein`, `[netRPC] Requête abandonnée: file net pleine`, `[netRPC] Timeout (... ms), abandon`, `[Sync] POST échoué (...)`, `[Sync] Envoi POST bloqué`, et `GET /api/remote-flags` pour vérifier `sendEnabled`.

---

## 📊 Résumé Endpoints

### ESP32 → Serveur (POST)

**Environnement TEST** (wroom-test actuel):
```
URL: http://iot.olution.info/ffp3/post-data-test
Route: /ffp3/post-data-test (Slim → PostDataController::handle)
Méthode: POST
Content-Type: application/x-www-form-urlencoded

Payload (exemple) : `api_key` (valeur côté firmware dans `include/config.h` ApiConfig::API_KEY ; côté serveur dans `.env` API_KEY), puis champs capteurs et GPIO. Ne pas dupliquer la clé en clair dans la doc.

Exemple (31 paramètres) :
api_key=<valeur .env>
&sensor=esp32-wroom
&version=11.35
&TempAir=28.0
&Humidite=60.0
&TempEau=28.0
&EauPotager=209
&EauAquarium=209
&EauReserve=209
&diffMaree=0
&Luminosite=813
&etatPompeAqua=0
&etatPompeTank=0
&etatHeat=0          ← État chauffage
&etatUV=1
&bouffeMatin=8
&bouffeMidi=12
&bouffeSoir=19
&tempsGros=2
&tempsPetits=2
&aqThreshold=18
&tankThreshold=80
&chauffageThreshold=18
&mail=oliv.arn.lau@gmail.com
&mailNotif=checked
&resetMode=0
&tempsRemplissageSec=5
&limFlood=8
&WakeUp=0
&FreqWakeUp=6
&bouffePetits=0
&bouffeGros=0

Actions serveur:
1. INSERT INTO ffp3Data2 (sans tempsGros/tempsPetits/tempsRemplissageSec/limFlood/WakeUp/FreqWakeUp)
2. UPDATE ffp3Outputs2 (17 GPIO) ← CRITIQUE pour chauffage
Note: les durées/limites/wake-up sont stockées uniquement dans ffp3Outputs2.
```

### Serveur → ESP32 (GET)

**Environnement TEST** (wroom-test actuel):
```
URL: http://iot.olution.info/ffp3/api/outputs-test/state
Fichier: /path/to/ffp3/public/index.php
Route: Slim Framework → OutputController::getOutputsState()
Méthode: GET

Réponse JSON : clés numériques (GPIO) + clés symboliques (alignées `gpio_mapping.h` / VARIABLE_NAMING.md). L’ESP32 accepte les deux formats. Champs additionnels pour la page de contrôle : `dataStates`, `dataStatesReadingTime`, `triggerOtaCheck` (une fois) — l’ESP32 n’utilise que les clés GPIO et `triggerOtaCheck`.

Exemple (extrait) :
```json
{
  "2": "0", "15": "1", "16": "0", "18": 0,
  "100": "...", "101": "1", "102": "18", "103": "80", "104": "18",
  "105": "8", "106": "12", "107": "19", "108": "0", "109": "0", "110": "0",
  "111": "2", "112": "2", "113": "5", "114": "8", "115": "0", "116": "6",
  "etatHeat": "0", "etatUV": "1", "etatPompeAqua": "0", "etatPompeTank": 0,
  "mail": "...", "mailNotif": "1", "aqThreshold": "18", "tankThreshold": "80",
  "chauffageThreshold": "18", "bouffeMatin": "8", "bouffeMidi": "12", "bouffeSoir": "19",
  "bouffePetits": "0", "bouffeGros": "0", "resetMode": "0",
  "tempsGros": "2", "tempsPetits": "2", "tempsRemplissageSec": "5",
  "limFlood": "8", "WakeUp": "0", "FreqWakeUp": "6"
}
```

Source : `OutputCacheService::getOutputsState()` (SELECT gpio, state depuis table outputs + noms symboliques via `OutputSyncService::getGpioMapping()`).
```

---

## 🎯 Conclusion

### **L'ancien fichier legacy fait DÉJÀ tout correctement !**

✅ Il met à jour **TOUS les GPIO** nécessaires (17)  
✅ Le chauffage **DEVRAIT** rester allumé

### **Donc pourquoi HTTP 500 ?**

Possibilités :
1. ❌ Erreur PHP dans `PostDataController::handle`
2. ❌ Erreur SQL (GPIO manquant dans ffp3Outputs2)
3. ❌ Problème permissions MySQL
4. ❌ Erreur PHP (variables undefined, payload inattendu)

---

## 🔧 Action Immédiate

**Vérifier les logs serveur PHP** pour voir l'erreur exacte :

```bash
ssh user@iot.olution.info
tail -f /var/log/apache2/error.log
# OU
tail -f /path/to/ffp3/error_log
```

Ou créer un fichier de test pour diagnostiquer :
```php
// test-post.php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Tester connexion BDD
$conn = new mysqli("localhost", "oliviera_iot", "Iot#Olution1", "oliviera_iot");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
echo "BDD OK\n";

// Tester table existe
$result = $conn->query("SHOW TABLES LIKE 'ffp3Data2'");
echo "Table ffp3Data2: " . ($result->num_rows > 0 ? "EXISTS" : "NOT FOUND") . "\n";

// Tester GPIO existe
$result = $conn->query("SELECT COUNT(*) as c FROM ffp3Outputs2 WHERE gpio IN (2,15,16,18,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114,115,116)");
$row = $result->fetch_assoc();
echo "GPIO count: " . $row['c'] . " (attendu: 21)\n";
?>
```

Veux-tu que je crée un script de diagnostic complet pour identifier l'erreur exacte ?
