# 🌐 Endpoints ESP32 ↔ Serveur - Configuration Complète

**Version FFP5CS (ESP32)** : 13.51 (compat ESP32 et ESP32-S3)
**Version Serveur** : 5.1.0
**Dernière mise à jour** : 19 Mai 2026

> Audit complet 2026-05 : durcissement sécurité (CSP, HSTS, OTA opt-in, masquage IP),
> trait HMAC partagé FFP3/MSP/N3PP, validation board/sensor galeries, JSON Twig durci
> (JSON_HEX_TAG), HMAC nonce (`isValidWithNonce`) prêt côté serveur.

---

## ⚠️ Périmètre de ce document (serveur distant uniquement)

Ce document décrit les **endpoints du serveur distant** (ffp3 sur iot.olution.info) utilisés par l’ESP32 : POST données, GET state, heartbeat. **Le serveur distant n’appelle jamais l’ESP32** ; tout le flux est initié par le firmware.

- **API locale (serveur embarqué ESP32)** : HTTP port 80, WebSocket port 81 path `/ws`. Documentée dans le dépôt firmware : `docs/technical/api-endpoints.yaml` et `docs/technical/VARIABLE_NAMING.md` (noms de champs alignés firmware ↔ serveur distant).

---

## 📍 Endpoints Utilisés par ESP32

### Uploadphotosserver (ESP32-CAM, controle distant)

Le firmware camera unifie (`uploadphotosserver`, envs `msp1`/`n3pp`/`ffp3`) recupere sa configuration a chaque reveil via GET, puis poste sa version firmware.

- **REST unifie** :
  - `GET /gallery/{slug}/api/outputs/state` — **auth device** : header `X-Api-Key` ou paramètre `api_key` (même clé que `API_KEY` serveur)
  - `POST /gallery/{slug}/api/firmware/version` — **auth device** identique
  - `GET /gallery/{slug}/control` (page web de pilotage, auth admin session/token)
- **Validation board/sensor (serveur >= 5.0.307)** :
  - `GET ...outputs/state` : si `board` est fourni, il doit correspondre au module (`msp1=6`, `n3pp=7`, `ffp3=5`), sinon `HTTP 400`.
  - `POST ...firmware/version` : `board` (si fourni) doit matcher le slug ; `sensor` (si fourni) doit etre `cam` ou le slug cible.
- **Aliases legacy `.php` (compatibilite firmware)** :
  - `msp1` (board 6, table `UploadPhoto2Outputs`) :
    - `GET /msp1gallery/uploadphotoserver-outputs-action.php?action=outputs_state&board=6`
    - `POST /msp1gallery/post-uploadphotoserver-version.php`
  - `n3pp` (board 7, table `UploadPhoto3Outputs`) :
    - `GET /n3ppgallery/uploadphotoserver-outputs-action.php?action=outputs_state&board=7`
    - `POST /n3ppgallery/post-uploadphotoserver-version.php`
  - `ffp3` (board 5, table `UploadPhoto1Outputs`) :
    - `GET /ffp3/ffp3gallery/uploadphotoserver-outputs-action.php?action=outputs_state&board=5`
    - `POST /ffp3/ffp3gallery/post-uploadphotoserver-version.php`

Champs controle camera exposes (table outputs) :
- `102` : adresse mail,
- `103` : notifications mail,
- `104` : `forceWakeUp` (one-shot firmware),
- `105` : `sleepTime` (secondes),
- `106` : `resetMode`,
- `100` : version firmware (mise a jour par POST version).

Codes de reponse upload galerie :
- `HTTP 200` : photo acceptee dans la galerie.
- `HTTP 202` : photo recue mais deplacee en corbeille auto (qualite insuffisante).
- `HTTP 400` : aucun fichier reçu (champ `imageFile` attendu) ou `board`/`sensor` invalide.
- `HTTP 401` : cle API absente ou invalide.
- `HTTP 413` : fichier trop volumineux (`MAX_FILE_SIZE = 5 Mo`).
- `HTTP 415` : type non autorise (attendu `image/jpeg`) ou magic bytes non-JPEG.
- `HTTP 429` : rate-limit dépassé (`GALLERY_UPLOAD_RATE_LIMIT_SECONDS`, défaut 10 s/IP).
- `HTTP 500` : erreur serveur (filesystem, permission, etc.).

### Poissonglouton (ESP32-S3 recyclage)

Firmware `firmwires/poissonglouton/` (mode ecran tactile ou headless).

- `POST /pgl/post-data` — **auth device**: `api_key` dans le body, validee cote serveur (`PGL_API_KEY`, fallback `API_KEY`).
- `GET /pgl/{secret}` — page statistiques **cachee** (non menu, non indexee) pour administrateur connaissant l'URL secrete.

Payload `POST /pgl/post-data` (form-urlencoded):

- `sensor` (ex. `poissonglouton`)
- `version` (ex. `0.1.0`)
- `events` (lot compact) : `epoch:count:mode:tandem:batteryMv:rssi,...`
  - `mode`: `1=ir`, `2=us`, `3=tandem`
  - `tandem`: `0` ou `1`

Codes de reponse:

- `HTTP 200` : lot accepte (`{"status":"ok","inserted":N}`)
- `HTTP 400` : payload invalide (events manquant / vide)
- `HTTP 401` : cle API invalide

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
public/index.php (front controller Slim 4)  ← Route Slim Framework
  └─> OutputController::getOutputsState()
```

**Declenchement OTA distant** : quand la page de controle envoie une demande "Verifier OTA", le serveur ajoute `triggerOtaCheck: true` une seule fois dans la reponse du GET firmware. Les polls web de l'interface utilisent `?fresh=1` et restent en lecture seule pour ce flag, afin de ne pas consommer la commande avant le prochain poll de l'ESP32.

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

**Compatibilité base URL** : Le serveur accepte les deux formes d’URL (avec ou sans préfixe `/ffp3/`) pour que tous les firmwares fonctionnent quel que soit leur `serverBase` : `POST /post-data3-test` et `POST /ffp3/post-data3-test` pointent vers le même handler (env test3). Idem pour `GET /ffp3/api/outputs3-test/state` et les autres endpoints post-data / heartbeat.

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
  Fichier : `src/Controller/Ffp3/PostDataController.php` (relatif à la racine du dépôt serveur).

### Timestamp et signature HMAC

#### Mode actuel (compat firmware) — défaut

- FFP5CS v13.80+ peut envoyer les en-têtes **X-Sig-Timestamp**, **X-Sig-Nonce** et **X-Sig-Hmac**.
- Format du message HMAC signé par ces en-têtes : `HMAC-SHA256("<timestamp>\n<nonce>\n<body_brut>", API_SIG_SECRET)`.
- Si ces en-têtes sont valides, la requête est authentifiée sans exiger `api_key` (mode dual de migration).
- Si le client envoie **timestamp** et **signature** : le serveur valide la signature HMAC.
- Si ces champs sont absents : fallback automatique sur la validation `api_key` (la clé API doit alors être valide).
- Fenêtre temporelle : **SIG_VALID_WINDOW** (secondes, défaut 300). Le RTC ESP32 doit être synchronisé NTP à ± cette fenêtre.
- Format du message HMAC : `HMAC-SHA256(timestamp_string, API_SIG_SECRET)`. Hash en hex minuscules.

#### Mode strict (à activer après migration de TOUS les firmwares)

- `HMAC_STRICT_MODE=true` dans `.env` : refuse l'absence de HMAC, retourne **401**.
- À activer une fois que FFP5CS, MSP1 et N3PP envoient systématiquement `timestamp + signature`.

#### Mode avec nonce (anti-replay strict)

- `HMAC_NONCE_REQUIRED=true` dans `.env` : exige aussi `post_id` (servant de nonce).
- Format du message HMAC : `HMAC-SHA256("<timestamp>|<post_id>", API_SIG_SECRET)`.
- Le firmware DOIT signer avec ce format. La déduplication `post_id` (UNIQUE BDD) bloque tout replay strict.

#### Implémentation côté serveur

- Validator : `src/Security/SignatureValidator.php`
  - `isValidForBody($timestamp, $nonce, $body, $signature, $secret, $window)` — en-têtes FFP5CS v13.80+
  - `isValid($timestamp, $signature, $secret, $window)` — sans nonce
  - `isValidWithNonce($timestamp, $nonce, $signature, $secret, $window)` — avec nonce
- Controllers : `src/Controller/Ffp3/PostDataController.php` (FFP3), `src/Controller/Concerns/HmacAuthTrait.php` (MSP / N3PP).

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

**Deux sources en BDD (pompe aquarium, GPIO 16)** : la colonne **etatPompeAqua** dans les **lignes de mesures capteurs** (POST `post-data*`) reflète le dernier état rapporté par le firmware. Le **GET** utilisé par l’ESP32 pour appliquer les commandes est construit à partir de la table **outputs** (`ffp3Outputs*`, ligne **gpio = 16**), mise à jour par la synchro POST (`OutputRepository::syncStatesFromSensorData`) et par les actions web. Pour un diagnostic du type « la BDD indique ON mais le module reste OFF », comparer explicitement **gpio 16 dans outputs** avec **etatPompeAqua** dans la dernière insertion capteurs : ce ne sont pas la même ligne ni le même usage (commande poll vs dernier relevé).

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
```

**Unités (niveaux d’eau)** : les champs `EauPotager`, `EauAquarium` et `EauReserve` sont enregistrés en base en **millimètres**. L’interface web (pages aquaponie, dashboard FFP3, API temps réel capteurs) les expose en **centimètres** (conversion ÷10 côté serveur, affichage décimal avec virgule).

```
Actions serveur:
1. INSERT INTO `ffp3Data*` — colonnes de durées/config/WakeUp/`Pression`/`configSynced` si la colonne existe en BDD (sinon ignorées).
2. UPDATE `ffp3Outputs*` (GPIO) ← synchro état / seuils (dont durées si `configSynced`).
3. POST **ACK-only** (`ack_command` / `ack_status`) : pas d’INSERT mesure ; heartbeat board uniquement.
```

### Serveur → ESP32 (GET)

**Environnement TEST** (wroom-test actuel):
```
URL: http://iot.olution.info/ffp3/api/outputs-test/state
Fichier: /path/to/ffp3/public/index.php
Route: Slim Framework → OutputController::getOutputsState()
Méthode: GET

Réponse JSON : clés numériques (GPIO) + clés symboliques (alignées `gpio_mapping.h` / VARIABLE_NAMING.md). L’ESP32 accepte les deux formats. Champs additionnels pour la page de contrôle : `dataStates`, `dataStatesReadingTime`, `triggerOtaCheck` (une fois) — l’ESP32 n’utilise que les clés GPIO et `triggerOtaCheck`. Depuis **serveur 5.0.300**, `triggerOtaCheck` est posé puis consommé via la table **`ffp3OtaTrigger`** (fiable sous PHP-FPM multi-workers) ; voir `migrations/CREATE_FFP3_OTA_TRIGGER_TABLE.sql`.

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
