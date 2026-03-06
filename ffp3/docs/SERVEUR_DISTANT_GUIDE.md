# Guide Complet - Échanges avec le Serveur Distant

**Version:** 11.31  
**Date:** 2025-10-13  
**Statut:** ✅ Système robuste et résilient avec persistance

---

## 🎯 Vue d'Ensemble

Le système d'échanges avec le serveur distant garantit une communication fiable bidirectionnelle entre l'ESP32 et le serveur web, avec gestion des pannes réseau et persistance des données.

### Architecture Globale

```
ESP32 ←--→ Serveur Distant (iot.olution.info)
  │
  ├── Envoi données capteurs (POST toutes les 2 min)
  ├── Réception commandes (GET toutes les 4 sec)
  ├── ACK immédiat après exécution commande
  ├── Queue persistante en cas d'échec réseau
  └── Logs statistiques d'exécution
```

---

## 📡 Envoi des Données vers le Serveur

### Fréquence et Timing

- **Intervalle normal:** 2 minutes (120 secondes)
- **Endpoint:** `/ffp3/post-data` (PROD) ou `/ffp3/post-data-test` (TEST)
- **Méthode:** HTTP POST
- **Content-Type:** `application/x-www-form-urlencoded`
- **Timeout:** Configurable via `NetworkConfig::REQUEST_TIMEOUT_MS`

### Données Envoyées

**Capteurs:**
- Température air, humidité
- Température eau
- Niveaux d'eau (potager, aquarium, réserve)
- Différence marée
- Luminosité

**Actionneurs:**
- États pompes (aquarium, réserve)
- État chauffage
- État lumière UV

**Configuration:**
- Horaires nourrissage
- Durées nourrissage
- Seuils (aquarium, réserve, chauffage)
- Fréquence réveil
- Email et notifications

### Gestion des Échecs (v11.31)

#### Système de Retry Intelligent

```cpp
// web_client.cpp - Amélioration v11.31
- Max 3 tentatives
- Backoff exponentiel: 0ms, 1s, 3s
- Pas de retry sur erreur 4xx (client)
- Vérification WiFi avant retry
- Reset watchdog pendant attente
```

#### File d'Attente Persistante (DataQueue)

**Concept:**
Si l'envoi POST échoue (serveur indisponible, WiFi coupé, timeout), les données sont enregistrées dans une queue persistante en LittleFS.

**Caractéristiques:**
- **Localisation:** `/queue/pending_data.txt`
- **Format:** JSON Lines (1 ligne = 1 payload)
- **Capacité:** 50 entrées maximum
- **FIFO:** Première entrée = première rejouée
- **Rotation automatique:** Suppression des plus anciennes si dépassement

**Exemple d'utilisation:**

```cpp
// Automatiquement géré dans sendFullUpdate()
bool success = _web.postRaw(payload, false);

if (!success) {
    // Échec → Enqueue pour rejeu ultérieur
    _dataQueue.push(payload);
    Serial.printf("[Network] ✓ Payload queued for later (%u pending)\n", _dataQueue.size());
}

if (success && _dataQueue.size() > 0) {
    // Succès → Rejouer la queue
    uint16_t replayed = replayQueuedData();
    Serial.printf("[Network] ✓ Replayed %u queued payloads\n", replayed);
}
```

**Rejeu:**
- Max 5 payloads par cycle (évite surcharge)
- Arrêt immédiat si échec (économie batterie)
- Reset watchdog entre chaque envoi

---

## 📥 Réception des Commandes du Serveur

### Polling et Fréquence

- **Intervalle:** 4 secondes
- **Endpoint:** Configuration via `ServerConfig::getOutputUrl()`
- **Méthode:** HTTP GET
- **Réponse:** JSON avec état complet et commandes

### Types de Commandes

#### 1. Nourrissage Manuel Distant

**Clés JSON (GET state):**
- `108` ou `bouffePetits`: 1 → Nourrissage petits poissons (trigger sur front montant)
- `109` ou `bouffeGros`: 1 → Nourrissage gros poissons (trigger sur front montant)

Le serveur renvoie les deux formats (clé numérique et symbolique) pour rétrocompatibilité (voir `OutputCacheService`).

**Exécution (flux actuel – gpio_parser + automatism_sync):**

1. **Poll GET** : l’ESP32 reçoit le JSON (ex. `"108": 1`). `GPIOParser` détecte un **front montant** (0→1) sur GPIO 108 ou 109 pour éviter les re-déclenchements tant que le serveur garde le flag à 1.
2. **Seed initial** : au premier poll après boot, `AutomatismSync::seedInitialStateIfFirstPoll` initialise l’état edge depuis le doc (`GPIOParser::seedFeedStateFromDoc`) pour éviter un faux front si le serveur envoie déjà 108/109 à 1.
3. **Déclenchement** : sur front montant, `GPIOParser` appelle `manualFeedSmall()` / `manualFeedBig()` puis `notifyRemoteFeedExecuted(isSmall)`.
4. **Effets de bord** (`AutomatismSync::onRemoteFeedExecuted`) :
   - ACK optionnel : `sendCommandAck("bouffePetits"|"bouffeGros", "executed")`
   - Reset des flags côté serveur : `sendFullUpdate(readings, "bouffePetits=0&108=0")` ou `"bouffeGros=0&109=0"` (avec cooldown `REMOTE_FEED_RESET_COOLDOWN_MS`)
   - Email si activé (nourrissage manuel petits/gros)

**Reset des flags (POST) :**
- Après exécution nourrissage distant : l’ESP32 envoie un POST complet avec `bouffePetits=0&108=0` (ou `bouffeGros=0&109=0`) en `extraPairs`.
- En fin de cycle nourrissage (tous types) : `finalizeFeedingIfNeeded` envoie `bouffePetits=0&108=0&bouffeGros=0&109=0`.
- Le serveur (`PostDataController`) lit `bouffePetits` et `bouffeGros` ; `OutputRepository::syncStatesFromSensorData` met à jour les GPIO 108/109 (si `configSynced=1`).

**Fichiers concernés (firmware) :**
- `src/gpio_parser.cpp` : edge detection 108/109, seed, appel `manualFeedSmall/Big` + `notifyRemoteFeedExecuted`
- `src/automatism/automatism_sync.cpp` : `onRemoteFeedExecuted`, `sendFullUpdate` avec reset, email
- `include/gpio_mapping.h` : `FEED_SMALL` (108, serverPostName `bouffePetits`), `FEED_BIG` (109, serverPostName `bouffeGros`)

#### 2. Contrôle Pompe Réservoir

**Clés JSON:**
- `pump_tankCmd`: "1"/"0" → Commande explicite (prioritaire)
- `pump_tank`: "1"/"0" → État distant

**Exécution (v11.31):**

```cpp
// automatism_network.cpp - handleRemoteActuators()
if (isTrue(doc["pump_tankCmd"])) {
    Serial.println("[Network] 💧 Commande pompe TANK ON");
    
    // 1. Exécution
    autoCtrl.startTankPumpManual();
    
    // 2. ACK immédiat
    sendCommandAck("pump_tank", "on");
    
    // 3. Log
    logRemoteCommandExecution("pump_tank_on", true);
}
```

**⚠️ Priorité Locale (v11.30):**
Les commandes distantes sont bloquées pendant 5 secondes après une action locale (interface web ESP32).

```cpp
// Vérification automatique dans handleRemoteActuators()
if (AutomatismPersistence::hasRecentLocalAction(5000)) {
    Serial.println("[Network] ⚠️ PRIORITÉ LOCALE - Serveur distant bloqué");
    return; // Ignorer commandes distantes
}
```

#### 3. Autres Actionneurs

**Clés JSON:**
- `light` / `lightCmd`: Lumière UV
- `heat` / `heatCmd`: Chauffage
- `pump_aqua` / `pump_aquaCmd`: Pompe aquarium

**Format:**
- Commande: `*Cmd` (prioritaire)
- État: `*` (fallback)

#### 4. Configuration Distante

**Paramètres mis à jour automatiquement:**
- `aqThreshold`: Seuil niveau aquarium (cm)
- `tankThreshold`: Seuil niveau réserve (cm)
- `chauffageThreshold`: Seuil température chauffage (°C)
- `limFlood`: Limite inondation
- `tempsRemplissageSec`: Durée remplissage
- `mail`: Adresse email
- `mailNotif`: Activation notifications
- `FreqWakeUp`: Fréquence réveil (secondes)

#### 5. Commande Reset

**Clé JSON:**
- `resetMode`: "1" → Reset ESP32

**Séquence:**
1. Acquittement serveur (envoi `resetMode=0`)
2. Délai 200ms pour terminer requête
3. Sauvegarde paramètres critiques NVS
4. `ESP.restart()`

---

## ⚡ API Temps Réel (UI Web)

Cette API alimente le polling côté pages web (dashboard, supervision) via `realtime-updater.js`.

### Endpoints PROD
- `GET /ffp3/api/realtime/sensors/latest`
- `GET /ffp3/api/realtime/sensors/since/{timestamp}`
- `GET /ffp3/api/realtime/outputs/state`
- `GET /ffp3/api/realtime/system/health`
- `GET /ffp3/api/realtime/alerts/active`

### Endpoints TEST
- `GET /ffp3/api/realtime-test/sensors/latest`
- `GET /ffp3/api/realtime-test/sensors/since/{timestamp}`
- `GET /ffp3/api/realtime-test/outputs/state`
- `GET /ffp3/api/realtime-test/system/health`
- `GET /ffp3/api/realtime-test/alerts/active`

### Alias de compatibilité
- `GET /ffp3/api/health` (PROD)
- `GET /ffp3/api/health-test` (TEST)

---

## ✅ Système d'ACK Immédiat (v11.31)

### Principe

Après exécution d'une commande distante, l'ESP32 envoie un **acquittement immédiat** au serveur sans attendre le prochain cycle de 2 minutes.

### Implémentation

```cpp
// automatism_sync.cpp
bool AutomatismSync::sendCommandAck(const char* command, const char* status) {
    char ackPayload[256];
    snprintf(ackPayload, sizeof(ackPayload),
             "api_key=%s&sensor=%s&version=%s&ack_command=%s&ack_status=%s&ack_timestamp=%lu",
             ApiConfig::API_KEY, ProjectConfig::BOARD_TYPE, ProjectConfig::VERSION, command, status, millis());
    return AppTasks::netPostRaw(ackPayload, NetworkConfig::HTTP_POST_RPC_TIMEOUT_MS);
}
```

**Note :** Le payload ACK ne contient pas le champ `version` requis par l’endpoint POST data ; le serveur peut donc recevoir une réponse 200 (le payload inclut désormais sensor et version). L’acquittement fonctionnel du nourrissage repose sur le reset des flags (POST complet avec `bouffePetits=0&108=0`).

### Champs ACK

| Champ | Description | Exemple |
|-------|-------------|---------|
| `api_key` | Clé API | `fdGTMoptd5CD2ert3` |
| `sensor` | Identifiant ESP32 | `esp32-wroom` |
| `version` | Version firmware (requis par POST data) | `11.172` |
| `ack_command` | Commande exécutée | `bouffePetits` |
| `ack_status` | Statut | `executed`, `on`, `off` |
| `ack_timestamp` | Timestamp millis() | `123456789` |

**Note:** Le serveur peut ignorer ces champs si non implémenté. L'envoi périodique contient l'état complet de toute façon.

### Vérification après correctifs (nourrissage / ACK)

Après mise à jour du firmware (payload ACK avec `sensor` et `version`) ou du serveur (noms bouffeMatin/bouffeMidi harmonisés), il est recommandé de lancer une session de validation : erase flash, flash firmware + LittleFS, monitor 5 min (ex. script `erase_flash_fs_monitor_5min_analyze.ps1`). Vérifier dans les logs qu’aucun POST ne reçoit de réponse 400 pour les requêtes de type ACK ou reset flags nourrissage (payload désormais complet).

---

## 📊 Logs et Statistiques (v11.31)

### Logging des Commandes

Chaque commande distante est loggée avec statistiques dans NVS (namespace `cmdLog`):

```cpp
void AutomatismNetwork::logRemoteCommandExecution(const char* command, bool success) {
    Preferences prefs;
    prefs.begin("cmdLog", false);
    
    // Compteurs globaux
    uint32_t totalCmds = prefs.getUInt("total", 0) + 1;
    uint32_t successCmds = prefs.getUInt("success", 0) + (success ? 1 : 0);
    
    // Compteurs par commande
    String key = String("cmd_") + command;
    uint32_t cmdTotal = prefs.getUInt(key.c_str(), 0) + 1;
    
    prefs.putUInt("total", totalCmds);
    prefs.putUInt("success", successCmds);
    prefs.putUInt(key.c_str(), cmdTotal);
    prefs.end();
    
    // Log série
    float successRate = (float)successCmds / totalCmds * 100.0f;
    Serial.printf("[Network] Command '%s': %s (Success rate: %.1f%%)\n", 
                  command, success ? "✓ OK" : "✗ FAILED", successRate);
}
```

### Exemple de Logs

```
[Network] Command 'bouffePetits': ✓ OK (Success rate: 98.5%, Total: 134, This cmd: 67 times)
[Network] Command 'pump_tank_on': ✓ OK (Success rate: 96.2%, Total: 52, This cmd: 26 times)
```

### Récupération des Statistiques

```cpp
// Lecture NVS pour récupérer stats
Preferences prefs;
prefs.begin("cmdLog", true);

uint32_t total = prefs.getUInt("total", 0);
uint32_t success = prefs.getUInt("success", 0);
uint32_t bouffePetits = prefs.getUInt("cmd_bouffePetits", 0);

prefs.end();

float successRate = (total > 0) ? ((float)success / total * 100.0f) : 0.0f;
```

---

## 🔍 Diagnostic et Tests

### Script de Test des Endpoints

**Fichier:** `test_server_endpoints.ps1`

```powershell
# Test manuel des endpoints
.\test_server_endpoints.ps1

# Résultat attendu:
# PROD: HTTP 200 - Réponse texte "Données enregistrées avec succès"
# TEST: HTTP 200 - Réponse texte similaire
```

**⚠️ Alerte HTML:** Si la réponse contient `<!DOCTYPE>` ou `<html>`, l'endpoint renvoie une page web au lieu de traiter le POST → Configuration serveur à vérifier.

### Test Persistance Queue

**Procédure:**

1. Déconnecter WiFi ou bloquer serveur
2. Attendre 6 minutes (3 POST échoués)
3. Vérifier queue:

```cpp
Serial.printf("Queue size: %u\n", _dataQueue.size());
Serial.printf("Memory usage: %u bytes\n", _dataQueue.getMemoryUsage());
```

4. Reconnecter WiFi/serveur
5. Observer rejeu automatique:

```
[Network] Replaying queued data (3 pending)...
[Network] ✓ Replayed payload 1/5
[Network] ✓ Replayed payload 2/5
[Network] ✓ Replayed payload 3/5
[Network] Replay summary: 3 sent, 0 remaining
```

### Test Commande Distante

**Procédure:**

1. Modifier manuellement la BDD:

```sql
UPDATE iot_data 
SET bouffePetits = '1' 
WHERE sensor = 'esp32-wroom' 
ORDER BY created_at DESC 
LIMIT 1;
```

2. Attendre max 4 secondes (polling)
3. Observer logs série:

```
[Network] === JSON REÇU DU SERVEUR ===
{
  "bouffePetits": "1",
  ...
}
[Network] === FIN JSON ===
[Network] 🐟 Commande nourrissage PETITS reçue du serveur distant
[CRITIQUE] === DÉBUT NOURRISSAGE MANUEL PETITS ===
[Network] ✓ ACK sent: bouffePetits=executed
[Network] Command 'bouffePetits': ✓ OK (Success rate: 100.0%)
[CRITIQUE] === FIN NOURRISSAGE MANUEL PETITS ===
```

4. Vérifier exécution physique (servo tourne)
5. Vérifier BDD mise à jour (flag reset à 0)

### Monitoring Long Terme

**Logs à surveiller:**

```
# Taux de succès HTTP
[HTTP] ✓ Request successful

# Échecs réseau
[HTTP] ✗ WiFi disconnected, aborting retry

# Queue utilisation
[Network] ✓ Payload queued for later (3 pending)

# Statistiques commandes
[Network] Command 'X': ✓ OK (Success rate: 98.5%)
```

---

## ⚙️ Configuration

### Endpoints Serveur

**Fichier:** `src/project_config.h`

```cpp
namespace ServerConfig {
    // Base URL
    constexpr const char* BASE_URL = "http://iot.olution.info";
    
    // Endpoints
    constexpr const char* POST_DATA_ENDPOINT = "/ffp3/post-data";
    constexpr const char* OUTPUT_ENDPOINT = "/ffp3/api/outputs/state";
    constexpr const char* HEARTBEAT_ENDPOINT = "/ffp3/heartbeat";
    
    // Timeouts
    constexpr uint32_t REQUEST_TIMEOUT_MS = 10000;  // 10 secondes
    constexpr uint32_t BACKOFF_BASE_MS = 200;       // Backoff de base
}
```

### Profils de Compilation

**PRODUCTION:**
```ini
[env:wroom-prod]
build_flags = -DPROFILE_PROD
# Utilise /post-data (production)
```

**TEST:**
```ini
[env:wroom-test]
build_flags = -DPROFILE_TEST
# Utilise /post-data-test (test)
```

### Intervales de Communication

**Fichier:** `src/automatism/automatism_network.h`

```cpp
class AutomatismNetwork {
private:
    static constexpr unsigned long SEND_INTERVAL_MS = 120000;        // 2 minutes
    static constexpr unsigned long REMOTE_FETCH_INTERVAL_MS = 4000;  // 4 secondes
};
```

**Modification:**
- **SEND_INTERVAL_MS:** Fréquence envoi données capteurs
- **REMOTE_FETCH_INTERVAL_MS:** Fréquence polling commandes

⚠️ **Attention:** Ne pas descendre sous 2 secondes pour éviter surcharge serveur et watchdog timeout.

---

## 🛠️ Dépannage

### Problème: POST renvoie HTML au lieu de texte

**Symptôme:**
```
[HTTP] ⚠️ ALERTE: Réponse HTML détectée au lieu de JSON/texte !
```

**Causes possibles:**
1. Endpoint n'existe pas → 404 avec page d'erreur
2. Méthode POST non acceptée → 405 avec page
3. Erreur PHP silencieuse → Page d'erreur HTML

**Solutions:**
1. Tester manuellement avec `test_server_endpoints.ps1`
2. Vérifier logs PHP: `/var/www/html/ffp3/var/logs/post-data.log`
3. Vérifier que le fichier `post-data.php` existe et est exécutable

### Problème: Commandes distantes ignorées

**Symptôme:**
```
[Network] ⚠️ PRIORITÉ LOCALE ACTIVE - Serveur distant bloqué
```

**Cause:** Action locale récente (< 5 secondes)

**Solution:** Attendre 5 secondes après action locale avant commande distante

### Problème: Queue qui se remplit

**Symptôme:**
```
[Network] ✓ Payload queued for later (48 pending)
[Network] ⚠️ Queue pleine, rotation...
```

**Causes:**
1. Serveur distant indisponible longtemps
2. WiFi instable
3. Problème DNS/routage

**Solutions:**
1. Vérifier connectivité: `ping iot.olution.info`
2. Vérifier logs serveur
3. Réduire `SEND_INTERVAL_MS` temporairement
4. Vider manuellement: `_dataQueue.clear()`

### Problème: Watchdog timeout pendant nourrissage

**Symptôme:**
```
Guru Meditation Error: Core 1 panic'ed (Watchdog triggered on CPU 1)
```

**Cause:** Opération trop longue sans reset watchdog

**Solution:** Les resets sont ajoutés automatiquement en v11.31, mais si problème persiste:

```cpp
// Ajouter reset watchdog supplémentaire
esp_task_wdt_reset();
```

---

## 📈 Performances

### Métriques Normales

| Métrique | Valeur Normale | Action si Dépassé |
|----------|----------------|-------------------|
| Taux succès HTTP | > 95% | Vérifier réseau/serveur |
| Queue size | < 5 entrées | Vérifier connexion serveur |
| Latence POST | < 2 secondes | Vérifier serveur/timeout |
| Latence GET | < 1 seconde | Vérifier serveur |
| Memory usage queue | < 10 KB | Normal (max 25 KB) |

### Optimisations Appliquées (v11.31)

1. **Retry intelligent:** Backoff exponentiel
2. **Queue persistante:** Aucune perte de données
3. **ACK immédiat:** Réactivité maximale
4. **Watchdog protection:** Stabilité garantie
5. **Logs statistiques:** Monitoring temps réel

---

## 🔐 Sécurité

### Authentification

**API Key:**
- Envoyée dans chaque requête POST
- Définie dans `Config::API_KEY`
- Vérifiée par le serveur PHP

**Recommandations:**
- Ne pas commiter l'API key dans Git
- Utiliser un fichier `secrets.h` (ignoré par Git)
- Changer régulièrement la clé

### Validation des Données

**Serveur PHP (`post-data.php`):**
- Sanitization des entrées POST
- Validation types de données
- Protection injection SQL (PDO)

**ESP32:**
- Validation des valeurs capteurs (ranges)
- Timeout sur requêtes HTTP
- Vérification codes réponse

---

## 📝 Changelog v11.31

### Nouvelles Fonctionnalités

✅ **DataQueue:** File d'attente persistante en LittleFS  
✅ **ACK immédiat:** Acquittement après commande distante  
✅ **Logs statistiques:** Taux de succès par commande en NVS  
✅ **Watchdog protection:** Reset avant/après opérations longues  
✅ **Retry intelligent:** Backoff exponentiel + vérif WiFi

### Améliorations

🔧 **Retry HTTP:** Pas de retry sur erreur 4xx  
🔧 **Logs détaillés:** ACK + statistiques commandes  
🔧 **Robustesse:** Gestion complète des échecs réseau  
🔧 **Performance:** Max 5 replays par cycle

### Fichiers Modifiés

- `src/data_queue.h/cpp` (nouveau)
- `src/automatism/automatism_network.h/cpp`
- `src/web_client.cpp`
- `src/automatism/automatism_feeding.cpp`
- `src/automatism/automatism_actuators.cpp`
- `test_server_endpoints.ps1` (nouveau)

---

## 🚀 Prochaines Améliorations Possibles

### Court Terme

- [ ] Endpoint dédié `/ack-command` côté serveur
- [ ] Compression gzip des payloads > 1 KB
- [ ] Circuit breaker après N échecs consécutifs
- [ ] Dashboard web statistiques commandes distantes

### Moyen Terme

- [ ] Authentification HMAC au lieu d'API key
- [ ] WebSocket bidirectionnel pour commandes temps réel
- [ ] Queue multi-niveau (priorité haute/basse)
- [ ] Synchronisation horlo NTP pour timestamps précis

### Long Terme

- [ ] MQTT au lieu de HTTP (plus efficace)
- [ ] Certificats SSL/TLS pour HTTPS
- [ ] OTA déclenché depuis serveur distant
- [ ] Telemetry complète (Prometheus/Grafana)

---

**Fin du guide - Version 11.31**

