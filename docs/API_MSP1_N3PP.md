# API serveur — msp1 (station météo) et n3pp (serre / aquaponie)

Référence du contrat HTTP entre les firmwares legacy **msp** (board=2) et **n3pp** (board=3) et le serveur unifié Slim 4.

> Documentation OTA dédiée : [`OTA_N3PP_MSP.md`](OTA_N3PP_MSP.md).
> Firmwares `msp` et `n3pp` : dépôts externes (hors de ce dépôt serveur).

---

## 1. Vue d'ensemble

| Domaine | n3pp | msp |
|---------|------|-----|
| Préfixe URL prod | `/n3pp/` | `/msp1/` |
| Préfixe URL test | `/n3pp-test/` | `/msp1-test/` |
| Board ID (GET outputs) | **3** | **2** |
| Tables BDD prod | `n3ppData`, `n3ppOutputs`, `n3ppHeartbeat` | `msp1Data`, `msp1Outputs`, `msp1Heartbeat` |
| Tables BDD test | `n3ppDataTest`, `n3ppOutputsTest`, `n3ppHeartbeatTest` | `msp1DataTest`, `msp1OutputsTest`, `msp1HeartbeatTest` |
| Env Slim | `prod` / `n3pp_test` | `prod` / `msp_test` |

---

## 2. Endpoints

### 2.1 POST données capteurs

```
POST /n3pp/n3ppdatas/post-n3pp-data.php      (legacy historique)
POST /n3ppdatas/post-n3pp-data.php           (alias court prod)
POST /n3pp/post-data                         (alias moderne 2026-05)

POST /msp1/msp1datas/post-msp1-data.php      (legacy historique)
POST /msp1datas/post-msp1-data.php           (alias court prod)
POST /msp1/post-data                         (alias moderne 2026-05)
```

**Content-Type :** `application/x-www-form-urlencoded` (ou `application/json` accepté via [`RequestHelper`](../src/Util/RequestHelper.php)).

**Authentification** (par ordre de priorité, via [`HmacAuthTrait`](../src/Controller/Concerns/HmacAuthTrait.php)) :

1. **HMAC** : si le body contient `timestamp` ET `signature`, on valide :
   - `signature = HMAC-SHA256(timestamp, API_SIG_SECRET)`
   - fenêtre temporelle : `SIG_VALID_WINDOW` (défaut 300 s)
2. **API_KEY** legacy : sinon, le champ `api_key` doit être égal à `$_ENV['API_KEY']` (comparé en `hash_equals`).

**Champs obligatoires :**

| Champ | Type | Description |
|-------|------|-------------|
| `api_key` | string | Auth legacy (requise si pas de HMAC) |
| `sensor` | string ≤30 | Nom firmware (`"n3pp"` / `"msp1"`) |
| `version` | string ≤30 | `FIRMWARE_VERSION` (ex. `"4.38"` / `"2.42"`) |

**Champs métier n3pp** (voir [`N3ppSensorData`](../src/Domain/N3ppSensorData.php)) :

`TempAir`, `Humidite`, `Luminosite`, `Humid1`, `Humid2`, `Humid3`, `Humid4`, `HumidMoy`, `PontDiv`, `WakeUp`, `ArrosageManu`, `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`, `HeureArrosage`, `resetMode`, `etatPompe`, `tempsArrosage`, `bootCount`.

**Champs métier msp** (voir [`MspSensorData`](../src/Domain/MspSensorData.php)) :

`TempAirInt`, `TempAirExt`, `HumidAirInt`, `HumidAirExt`, `LuminositeA`, `LuminositeB`, `LuminositeC`, `LuminositeD`, `LuminositeMoy`, `ServoHB`, `ServoGD`, `HumidSol`, `Pluie`, `TempEau`, `PontDiv`, `WakeUp`, `SeuilSec`, `FreqWakeUp`, `SeuilPontDiv`, `mail`, `mailNotif`, `resetMode`, `bootCount`.

**Réponses :**

| Code | Body texte | Cause |
|------|-----------|-------|
| 200 | `Donnees enregistrees avec succes` | INSERT OK |
| 400 | `Donnees manquantes` | Body vide |
| 400 | `Champs sensor et version requis` | `sensor`/`version` manquants |
| 401 | `Cle API invalide` | `api_key` incorrecte |
| 401 | `Signature incomplete` | `timestamp` xor `signature` |
| 401 | `Signature incorrecte` | HMAC invalide ou hors fenêtre |
| 405 | `POST requis` | méthode ≠ POST |
| 500 | `Configuration serveur manquante` | `API_KEY` / `API_SIG_SECRET` absent du `.env` |
| 500 | `Erreur serveur` | Exception INSERT (loggée) |

### 2.2 GET configuration distante (outputs_state)

```
GET /n3pp/n3ppcontrol/n3pp-outputs-action.php?action=outputs_state&board=3
GET /msp1/msp1control/msp1-outputs-action.php?action=outputs_state&board=2
```

**Auth :** publique (lecture seule des paramètres).

**Réponse JSON :**

```json
{ "100": "alerte@x.fr", "101": "checked", "106": "0", "107": "300", "110": "0", "111": "1" }
```

**Mapping GPIO virtuels → paramètres firmware :**

| GPIO | n3pp | msp |
|------|------|-----|
| 100 | `mail` (string) | `mail` (string) |
| 101 | `mailNotif` (`checked`/`""`) | `mailNotif` |
| 102 | `SeuilSec` (int) | `SeuilSec` (int) |
| 103 | `SeuilPontDiv` (int) | `SeuilPontDiv` |
| 104 | `HeureArrosage` (int 0-23) | `AngleServoHB` (int 40-145) |
| 105 | `tempsArrosage` (int s) | `AngleServoGD` (int 1-179) |
| 106 | `WakeUp` (0/1) | `WakeUp` (0/1) |
| 107 | `FreqWakeUp` (s) | `FreqWakeUp` (s) |
| 110 | `resetMode` (one-shot 0/1) | `resetMode` (one-shot 0/1) |
| 111 | — | `ServoModeAuto` (0/1) |

> Comportement **one-shot** GPIO 110 : si le serveur renvoie `"110": "1"`, le firmware tente OTA puis `ESP.restart()`. Le serveur remet automatiquement à `0` après lecture (cf. [`AbstractOutputRepository`](../src/Repository/AbstractOutputRepository.php)).

> Comportement **one-shot** GPIO 13 (n3pp uniquement) : arrosage manuel déclenché au prochain poll firmware ; le serveur remet à `0` après lecture (comme GPIO 110).

**GPIO actionneurs physiques N3PP** (table `n3ppOutputs`, board 3) :

| GPIO | Rôle | Comportement firmware |
|------|------|----------------------|
| 12 | Pompe irrigation | État persistant (`digitalWrite`) |
| 13 | Arrosage manuel | One-shot (ack serveur → 0) |
| 15, 16 | Lampe / Ventilation (legacy UI) | Non lus par le firmware actuel — commande enregistrée en BDD uniquement |

Le firmware lit la pompe via la clé JSON `"12"` et l'arrosage manuel via `"13"` (cf. `n3pp_config.h`, `n3pp_network.cpp`). Les GPIO 2 (ancien seed) ne sont plus utilisés côté firmware.

**Paramètres virtuels — bornes serveur (pages contrôle)** :

| Paramètre | n3pp | msp |
|-----------|------|-----|
| `SeuilSec` | 0–4095 (ADC brut) | 0–4095 |
| `HeureArrosage` | 0–23 | — |
| `tempsArrosage` | 1–20 s | — |
| `FreqWakeUp` | 1–86400 s | 1–86400 s |
| `WakeUp` | 0 = veille / 1 = éveillé | idem |

**MSP — sorties GPIO &lt; 100** : le firmware MSP ne lit aucun GPIO actionneur depuis le serveur (params 100–111 uniquement). La carte « Pompe arrosage » en UI n'a pas d'effet sur l'ESP32.

### 2.3 POST heartbeat (Phase 4 audit 2026-05)

```
POST /n3pp/heartbeat
POST /msp1/heartbeat
```

**Auth :** HMAC ou API_KEY (mêmes règles que POST données).

**Champs :**

| Champ | Type | Obligatoire | Description |
|-------|------|-------------|-------------|
| `uptime` | int (s) | oui | Secondes depuis dernier boot |
| `free` | int (octets) | oui | Free heap au moment du heartbeat |
| `min` | int (octets) | oui | Min free heap observé depuis boot |
| `reboots` | int | oui | `bootCount` cumulé |
| `rssi` | int (dBm) | non | RSSI WiFi actuel |
| `sensor` | string ≤30 | non | Nom firmware |
| `version` | string ≤30 | non | Version firmware |

**Réponse :** `200 OK` ou erreurs auth/validation comme POST données.

**Tables BDD** : créées par [`migrations/CREATE_LEGACY_HEARTBEAT_TABLES.sql`](../migrations/CREATE_LEGACY_HEARTBEAT_TABLES.sql).

### 2.4 API realtime (front web)

| URL | Méthode | Auth | Description |
|-----|---------|------|-------------|
| `/n3pp/api/realtime/sensors/latest` | GET | publique | Dernière mesure JSON |
| `/n3pp/api/realtime/sensors/since/{timestamp}` | GET | publique | Mesures depuis timestamp |
| `/n3pp/api/realtime/outputs/state` | GET | publique | État GPIO virtuels |
| `/n3pp/api/realtime/system/health` | GET | publique | Santé serveur |
| `/n3pp/api/realtime/alerts/active` | GET | publique | Alertes actives |
| `/n3pp/api/outputs/toggle` | GET\|POST | session/token | Toggle GPIO (UI admin) |
| `/n3pp/api/outputs/parameters` | POST | session/token | Modif paramètres (mail, seuils) |

Mêmes routes pour `/msp1/`. Le toggle MSP1 accepte `gpio` **ou** `name` (`gpio` prioritaire). Les environnements **test** exposent les mêmes routes sous les préfixes `/n3pp-test/` et `/msp1-test/` (plus les pages de données/contrôle `*-data.php` / `*control/`, omises en prod).

---

## 3. Sécurité

| Aspect | Statut actuel | Recommandation |
|--------|---------------|----------------|
| Transport | HTTP (legacy) | Migrer HTTPS quand certificat dispo, MITM mitigé par HMAC body |
| Authentification POST | HMAC ou API_KEY (cf. 2.1) | Activer HMAC en prod (`API_SIG_SECRET` non vide partout) |
| Authentification GET outputs | publique par défaut | `FIRMWARE_STATE_REQUIRE_KEY=true` exige `X-Api-Key` (évite qu'un tiers consomme les one-shot GPIO 110) |
| Rate limiting | aucun | Middleware par IP+api_key prévu Phase 5+ |
| Logs accès | [`LogService`](../src/Service/LogService.php) Monolog | OK |
| Validation `board` GET | `board` est passé en query mais le module est figé par préfixe URL | Restreindre côté `MspOutputController::getState()` à `2` et `N3ppOutputController::getState()` à `3` (Phase 5) |

---

## 4. Variables d'environnement requises

| Variable | Description | Défaut |
|----------|-------------|--------|
| `API_KEY` | Clé API legacy partagée avec `firmwires/credentials.h` (n3pp/msp/CAM) ou `Secrets::API_KEY` (ffp5cs) | — (obligatoire) |
| `API_SIG_SECRET` | Secret HMAC partagé pour POST/heartbeat | vide = HMAC désactivé |
| `SIG_VALID_WINDOW` | Fenêtre temporelle HMAC (s) | 300 |
| `FIRMWARE_STATE_REQUIRE_KEY` | Si `true`, GET `/api/outputs/state` exige `X-Api-Key` valide | `false` |
| `FIRMWARE_RATE_LIMIT_MAX` | Limite requêtes firmware par IP (0 = désactivé) | 0 |
| `ENV` | `prod` / `msp_test` / `n3pp_test` | `prod` |

Voir [`.env.example`](../.env.example).

---

## 5. Tests recommandés

- Smoke HTTP : étendre [`local-smoke-test.ps1`](../tools/local-smoke-test.ps1) avec POST legacy et heartbeat (cas 200, 401 api_key, 401 HMAC périmé, 400 champ manquant).
- PHPUnit : tests `MspPostDataController` et `N3ppPostDataController` (auth, validation, INSERT).
- Tests natifs PlatformIO côté firmware : vérification HMAC (`n3_hmac`), `compareVersions` (`n3_ota`), `readFilteredAnalog` (`n3_analog_sensors`).

---

## 6. Historique du contrat

| Date | Changement |
|------|------------|
| 2026-05 | Phase 4 audit : alias `/post-data`, `/heartbeat`, auth HMAC optionnelle (`HmacAuthTrait`), tables `*Heartbeat`, doc dédiée. |
| 2026-03 | Pages 301 `/serre` et `/meteo` ; routes API realtime. |
| 2026-02 | Slim 4 unifié (`registerIotModuleRoutes`). Auth `api_key` Monolog. |
| Pre-Slim | Scripts PHP procéduraux (ancienne version du serveur, désormais archivée hors dépôt). |
