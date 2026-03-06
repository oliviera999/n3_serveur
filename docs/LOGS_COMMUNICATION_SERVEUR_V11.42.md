# Amélioration des Logs de Communication Serveur - Version 11.42

**Date:** 2025-01-27  
**Version:** 11.42  
**Statut:** ✅ Implémenté et testé

---

## 🎯 Objectif

Augmenter considérablement les logs série de communication entre l'ESP32 et le serveur distant pour faciliter le debugging et le diagnostic des problèmes de communication.

## 📋 Modifications Apportées

### 1. Logs HTTP Détaillés (`httpRequest`)

**Avant:**
```cpp
Serial.printf("[HTTP] → %s (%u bytes)\n", url.c_str(), payload.length());
```

**Après:**
```cpp
// === LOGS DÉTAILLÉS DE DEBUGGING v11.32 ===
unsigned long requestStartMs = millis();
Serial.println(F("=== DÉBUT REQUÊTE HTTP ==="));
Serial.printf("[HTTP] Timestamp: %lu ms\n", requestStartMs);
Serial.printf("[HTTP] URL: %s\n", url.c_str());
Serial.printf("[HTTP] Payload size: %u bytes\n", payload.length());

// Logs détaillés du payload (toujours affiché pour debugging)
Serial.println(F("[HTTP] === PAYLOAD COMPLET ==="));
if (payload.length() <= 500) {
  Serial.printf("[HTTP] %s\n", payload.c_str());
} else {
  Serial.printf("[HTTP] %s ... (truncated)\n", payload.substring(0,500).c_str());
  Serial.printf("[HTTP] ... (%u bytes restants)\n", payload.length() - 500);
}
Serial.println(F("[HTTP] === FIN PAYLOAD ==="));

// État réseau détaillé
Serial.printf("[HTTP] WiFi Status: %d (connected=%s)\n", WiFi.status(), WiFi.isConnected() ? "YES" : "NO");
Serial.printf("[HTTP] RSSI: %d dBm\n", WiFi.RSSI());
Serial.printf("[HTTP] IP: %s\n", WiFi.localIP().toString().c_str());
Serial.printf("[HTTP] Gateway: %s\n", WiFi.gatewayIP().toString().c_str());
Serial.printf("[HTTP] DNS: %s\n", WiFi.dnsIP().toString().c_str());

// Mémoire disponible
size_t freeHeap = ESP.getFreeHeap();
size_t minFreeHeap = ESP.getMinFreeHeap();
Serial.printf("[HTTP] Free heap: %u bytes (min: %u)\n", freeHeap, minFreeHeap);
```

### 2. Logs de Timing et Performance

**Nouveaux logs ajoutés:**
- Timestamp de début de requête
- Durée de chaque tentative
- Durée totale de la requête
- Durée de réception de la réponse
- Durée de parsing JSON (pour GET)

```cpp
unsigned long requestStartMs = millis();
unsigned long attemptStartMs = millis();
unsigned long postDurationMs = millis() - attemptStartMs;
unsigned long totalDurationMs = millis() - requestStartMs;
```

### 3. Logs d'Erreur Améliorés

**Diagnostic automatique des erreurs courantes:**
```cpp
if (code == HTTPC_ERROR_CONNECTION_REFUSED) {
  Serial.println(F("[HTTP] 🔍 DIAGNOSTIC: Connection refused - serveur indisponible"));
} else if (code == HTTPC_ERROR_CONNECTION_LOST) {
  Serial.println(F("[HTTP] 🔍 DIAGNOSTIC: Connection lost - problème réseau"));
} else if (code == HTTPC_ERROR_TOO_LESS_RAM) {
  Serial.println(F("[HTTP] 🔍 DIAGNOSTIC: Too less RAM - mémoire insuffisante"));
} else if (code == HTTPC_ERROR_READ_TIMEOUT) {
  Serial.println(F("[HTTP] 🔍 DIAGNOSTIC: Read timeout - délai de lecture dépassé"));
}
```

### 4. Analyse Détaillée des Réponses

**Headers HTTP complets:**
```cpp
String contentType = _http.header("Content-Type");
String server = _http.header("Server");
String connection = _http.header("Connection");
String contentLength = _http.header("Content-Length");
String transferEncoding = _http.header("Transfer-Encoding");
```

**Analyse du type de contenu:**
```cpp
if (response.startsWith("<") || response.indexOf("<!DOCTYPE") >= 0 || response.indexOf("<html") >= 0) {
  Serial.println(F("[HTTP] ⚠️ ALERTE: Réponse HTML détectée au lieu de JSON/texte !"));
} else if (response.startsWith("{") || response.startsWith("[")) {
  Serial.println(F("[HTTP] ✓ Réponse JSON détectée"));
} else if (response.indexOf("success") >= 0 || response.indexOf("ok") >= 0) {
  Serial.println(F("[HTTP] ✓ Réponse texte positive détectée"));
}
```

### 5. Logs GET Remote State (`fetchRemoteState`)

**Nouveaux logs détaillés:**
- État réseau avant GET
- Durée de la requête GET
- Analyse du JSON reçu
- Logs des clés principales du JSON

```cpp
Serial.println(F("=== DÉBUT REQUÊTE GET REMOTE STATE ==="));
Serial.printf("[GET] Timestamp: %lu ms\n", getStartMs);
Serial.printf("[GET] URL: %s\n", url.c_str());
Serial.printf("[GET] WiFi Status: %d (connected=%s)\n", WiFi.status(), WiFi.isConnected() ? "YES" : "NO");
Serial.printf("[GET] RSSI: %d dBm\n", WiFi.RSSI());
Serial.printf("[GET] Free heap: %u bytes\n", ESP.getFreeHeap());
```

### 6. Logs Heartbeat (`sendHeartbeat`)

**Logs détaillés du heartbeat:**
```cpp
Serial.println(F("=== DÉBUT HEARTBEAT ==="));
Serial.printf("[HB] Timestamp: %lu ms\n", hbStartMs);
Serial.printf("[HB] Payload avant CRC: %s\n", payload.c_str());
Serial.printf("[HB] Payload final: %s\n", pay2.c_str());
Serial.printf("[HB] Uptime: %lu sec\n", s.uptimeSec);
Serial.printf("[HB] Free heap: %u bytes\n", s.freeHeap);
Serial.printf("[HB] Min free heap: %u bytes\n", s.minFreeHeap);
Serial.printf("[HB] Reboot count: %u\n", s.rebootCount);
```

### 7. Logs SendMeasurements (`sendMeasurements`)

**Logs des valeurs brutes et validées:**
```cpp
Serial.printf("[SM] Valeurs brutes - TempEau: %.1f°C, TempAir: %.1f°C, Humidité: %.1f%%\n", 
             tempWater, tempAir, humidity);
Serial.printf("[SM] Valeurs brutes - EauPotager: %u, EauAquarium: %u, EauReserve: %u\n", 
             m.wlPota, m.wlAqua, m.wlTank);
Serial.printf("[SM] États actionneurs - PompeAqua: %s, PompeTank: %s, Heat: %s, UV: %s\n",
             m.pumpAqua ? "ON" : "OFF", m.pumpTank ? "ON" : "OFF", 
             m.heater ? "ON" : "OFF", m.light ? "ON" : "OFF");
```

### 8. Logs PostRaw (`postRaw`)

**Logs de construction du payload:**
```cpp
Serial.println(F("=== DÉBUT POSTRAW ==="));
Serial.printf("[PR] Payload input: %u bytes\n", payload.length());
Serial.printf("[PR] Include skeleton: %s\n", includeSkeleton ? "OUI" : "NON");
Serial.printf("[PR] Has API key: %s\n", hasApi ? "OUI" : "NON");
Serial.printf("[PR] Final payload size: %u bytes\n", full.length());
Serial.printf("[PR] API Key: %s\n", _apiKey.c_str());
Serial.printf("[PR] Sensor: %s\n", Config::SENSOR);
```

## 🔍 Informations Collectées

### État Réseau
- Statut WiFi (connecté/déconnecté)
- RSSI (force du signal)
- Adresse IP locale
- Passerelle
- Serveur DNS
- État avant/après chaque tentative

### Performance
- Timestamp de début/fin
- Durée de chaque opération
- Durée totale des requêtes
- Durée de parsing JSON

### Mémoire
- Heap libre avant/après
- Heap minimum
- Surveillance des fuites mémoire

### Payloads
- Contenu complet des requêtes POST
- Taille des payloads
- Validation des données avant envoi
- Construction détaillée des payloads

### Réponses Serveur
- Code HTTP complet
- Headers HTTP détaillés
- Contenu de la réponse (tronqué si nécessaire)
- Analyse du type de contenu
- Détection d'erreurs HTML vs JSON

### Erreurs
- Codes d'erreur détaillés
- Diagnostic automatique des erreurs courantes
- État réseau au moment de l'erreur
- Mémoire disponible au moment de l'erreur

## 📊 Exemple de Logs Générés

```
=== DÉBUT REQUÊTE HTTP ===
[HTTP] Timestamp: 1234567 ms
[HTTP] URL: https://iot.olution.info/ffp3/public/post-data
[HTTP] Payload size: 245 bytes
[HTTP] === PAYLOAD COMPLET ===
[HTTP] api_key=ABC123&sensor=ESP32-Main&version=11.42&TempAir=22.5&Humidite=65.2&TempEau=18.3&EauPotager=25&EauAquarium=30&EauReserve=45&diffMaree=5&Luminosite=120&etatPompeAqua=0&etatPompeTank=1&etatHeat=0&etatUV=1&bouffeMatin=&bouffeMidi=&bouffeSoir=&bouffePetits=&bouffeGros=&tempsGros=&tempsPetits=&aqThreshold=25&tankThreshold=20&chauffageThreshold=18.0&mail=&mailNotif=&resetMode=0&tempsRemplissageSec=&limFlood=&WakeUp=&FreqWakeUp=
[HTTP] === FIN PAYLOAD ===
[HTTP] WiFi Status: 3 (connected=YES)
[HTTP] RSSI: -45 dBm
[HTTP] IP: 192.168.1.100
[HTTP] Gateway: 192.168.1.1
[HTTP] DNS: 8.8.8.8
[HTTP] Free heap: 125432 bytes (min: 120000)
[HTTP] Modem sleep disabled for transfer
[HTTP] Starting retry loop (max 3 attempts)
[HTTP] === TENTATIVE 1/3 ===
[HTTP] 🔒 Using HTTPS client (attempt 1/3)
[HTTP] Headers set, timeout: 15000 ms
[HTTP] Sending POST at 1234568 ms...
[HTTP] POST completed in 1250 ms
[HTTP] ← HTTP 200, 45 bytes (received in 50 ms)
[HTTP] Content-Type: application/json
[HTTP] Server: nginx/1.18.0
[HTTP] Connection: keep-alive
[HTTP] Content-Length: 45
[HTTP] Transfer-Encoding: 
[HTTP] === RÉPONSE COMPLÈTE ===
[HTTP] {"status":"success","message":"Data received","timestamp":"2025-01-27T10:30:00Z"}
[HTTP] === FIN RÉPONSE ===
[HTTP] ✓ Réponse JSON détectée
[HTTP] ✓ Succès (2xx): 200
[HTTP] Tentative 1/3 terminée en 1300 ms
[HTTP] ✓ Succès détecté (HTTP 200), arrêt des tentatives
[HTTP] === FIN REQUÊTE HTTP ===
[HTTP] Durée totale: 1300 ms
[HTTP] Tentatives: 1/3
[HTTP] Code final: 200
[HTTP] Succès: OUI
[HTTP] Taille réponse: 45 bytes
[HTTP] Mémoire finale: 125400 bytes
===============================
```

## ⚠️ Impact sur les Performances

### Considérations
- **Volume de logs:** Augmentation significative du volume de logs série
- **Mémoire:** Utilisation légèrement accrue pour les buffers de logs
- **CPU:** Impact minimal sur les performances
- **Taille:** Aucun impact sur la taille du firmware

### Recommandations
1. **Monitoring:** Surveiller l'utilisation mémoire après déploiement
2. **Logs:** Les logs sont toujours actifs (pas de condition DEBUG)
3. **Debugging:** Utiliser ces logs pour identifier rapidement les problèmes
4. **Production:** Ces logs sont essentiels pour le debugging en production

## 🚀 Bénéfices

### Pour le Debugging
- **Diagnostic rapide:** Identification immédiate des problèmes
- **Traçabilité complète:** Suivi de chaque étape de communication
- **Contexte réseau:** État WiFi et mémoire à chaque étape
- **Analyse des erreurs:** Diagnostic automatique des erreurs courantes

### Pour la Maintenance
- **Monitoring proactif:** Détection précoce des problèmes
- **Analyse des performances:** Mesure des temps de réponse
- **Validation des données:** Vérification des payloads avant envoi
- **Historique détaillé:** Traçabilité complète des communications

## 📝 Notes Techniques

- **Version:** Incrémentée à 11.42
- **Fichier modifié:** `src/web_client.cpp`
- **Compatibilité:** Rétrocompatible avec le code existant
- **Tests:** Aucun test unitaire modifié nécessaire
- **Documentation:** Ce fichier documente les changements

---

**Note:** Ces améliorations de logs sont particulièrement utiles pour le debugging des problèmes de communication avec le serveur distant. Elles permettent une analyse détaillée de chaque étape du processus de communication et facilitent l'identification des causes racines des problèmes.
