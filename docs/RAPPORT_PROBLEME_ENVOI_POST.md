# 🔴 RAPPORT - Problème Envoi POST Serveur Distant

**Date:** 2025-10-11  
**Version:** 11.03  
**Problème:** POST données envoyé seulement 1 fois en 7 minutes au lieu de toutes les 2 minutes

---

## 🎯 PROBLÈME IDENTIFIÉ

### Configuration conflictuelle

**Fichier:** `include/automatism.h`

```cpp
// Ligne 145 - Intervalle d'envoi POST
const unsigned long sendInterval = 120000; // 120 secondes = 2 minutes ✅

// Ligne 100 - Fréquence de réveil après sleep
uint16_t freqWakeSec = 600; // 600 secondes = 10 minutes ❌
```

### Séquence du problème

```
T+0s    : Réveil du système
T+5s    : Lecture capteurs
T+10s   : Vérification shouldEnterSleepEarly() → TRUE
T+10s   : return; (sortie de update()) ⚠️ POST jamais atteint
T+10s   : Entrée en light sleep pour 600 secondes
...
T+610s  : Réveil après sleep
T+615s  : Lecture capteurs
T+620s  : currentMillis - lastSend > 120000 → TRUE (plus de 10 min)
T+620s  : ✅ POST ENVOYÉ
T+625s  : Vérification shouldEnterSleepEarly() → TRUE
T+625s  : Entrée en light sleep pour 600 secondes
...
[CYCLE SE RÉPÈTE]
```

**Résultat:** POST envoyé **~1 fois par cycle de sleep** (toutes les 10+ minutes) au lieu de toutes les 2 minutes.

---

## 📊 OBSERVATIONS DU MONITORING (7 minutes)

### Timeline observée

| Temps | Événement | Intervalle |
|-------|-----------|------------|
| T+0s | Démarrage monitoring | - |
| T+0-210s | Pas de POST | - |
| T+~210s | **POST envoyé** | - |
| T+210-420s | Pas de POST | 210s+ |

**Analyse:**
- 1 seul POST observé en 7 minutes ❌
- Attendu: 3-4 POST (à T+120s, T+240s, T+360s) ✅

### Requêtes GET Remote State

- ✅ Fréquence: ~30 secondes
- ✅ Très régulier
- ✅ Pas d'interruption

**Conclusion:** GET fonctionne car exécuté dans `handleRemoteState()` ligne 1246, appelé **AVANT** le check de sleep.

---

## 🔍 ANALYSE DU CODE

### Fonction `Automatism::update()` - src/automatism.cpp

```cpp
void Automatism::update(const SensorReadings& readings) {
  // ... [lignes 552-569] ...
  
  // ========================================
  // PRIORITÉ HAUTE : ENTRÉE EN LIGHTSLEEP
  // ========================================
  if (shouldEnterSleepEarly(readings)) {
    handleAutoSleep(readings);
    return; // ⚠️ SORTIE IMMÉDIATE - POST jamais atteint !
  }
  
  // ... [lignes 577-591] ...
  
  handleRemoteState(); // ✅ GET remote state exécuté ici
  
  handleAutoSleep(readings); // ⚠️ Deuxième point de sortie potentiel
  
  // ... [lignes 597-609] ...
  
  // 9. Envoi périodique des mesures distantes (DERNIÈRE PRIORITÉ)
  if (currentMillis - lastSend > sendInterval) { // ❌ Jamais atteint si sleep avant
    bool okSend = sendFullUpdate(readings, "resetMode=0");
    // ...
    lastSend = currentMillis;
  }
}
```

### Points de sortie prématurée

1. **Ligne 575:** `return;` après `handleAutoSleep(readings)`
2. **Ligne 595:** Deuxième appel à `handleAutoSleep(readings)` (peut aussi bloquer)

**Problème:** Le code d'envoi POST (ligne 612) est en **DERNIÈRE PRIORITÉ** et n'est jamais atteint si le système entre en veille avant.

---

## 🔢 CALCUL DES TIMINGS

### Configuration actuelle

- **sendInterval:** 120 secondes (2 minutes)
- **freqWakeSec:** 600 secondes (10 minutes) ← Valeur serveur ou défaut
- **remoteFetchInterval:** ~30 secondes (estimé)

### Durée d'éveil avant sleep

D'après les logs, le système semble rester éveillé environ **30-60 secondes** avant d'entrer en veille.

**Raisons possibles:**
1. Pas d'activité bloquante (pompe réservoir OFF, nourrissage terminé)
2. Pas de marée détectée (diff10s < 2cm)
3. Conditions de `shouldEnterSleepEarly()` remplies rapidement

### Fréquence réelle d'envoi POST

```
Temps éveil avant sleep: ~30-60s
Temps en sleep: ~600s (10 min)
Cycle complet: ~630-660s (~10-11 minutes)
```

**POST envoyé:** 1 fois par cycle = toutes les **10-11 minutes** ❌

**POST attendu:** Toutes les **2 minutes** ✅

---

## 💡 SOLUTIONS PROPOSÉES

### Solution 1: POST AVANT sleep (RECOMMANDÉE) ⭐

**Déplacer l'envoi POST AVANT les vérifications de sleep**

```cpp
void Automatism::update(const SensorReadings& readings) {
  // ... [lignes 552-580] ...
  
  handleRemoteState(); // GET remote state
  
  // ========================================
  // ENVOI POST AVANT SLEEP (PRIORITÉ HAUTE)
  // ========================================
  unsigned long currentMillis = millis();
  if (currentMillis - lastSend > sendInterval) {
    sendState = 0;
    bool okSend = sendFullUpdate(readings, "resetMode=0");
    // ... mise à jour icônes OLED ...
    sendState = okSend ? 1 : -1;
    serverOk = okSend;
    lastSend = currentMillis;
  }
  
  // ========================================
  // PRIORITÉ HAUTE : ENTRÉE EN LIGHTSLEEP
  // ========================================
  if (shouldEnterSleepEarly(readings)) {
    handleAutoSleep(readings);
    return;
  }
  
  // ... reste du code ...
  
  handleAutoSleep(readings); // Fallback
  
  // ... reste du code (sauvegarde, etc.) ...
}
```

**Avantages:**
- ✅ POST garanti toutes les 2 minutes même si sleep juste après
- ✅ Pas de changement de logique de sleep
- ✅ Minimal impact sur le code existant

**Inconvénients:**
- ⚠️ Retarde légèrement l'entrée en sleep (~500ms pour HTTP POST)

---

### Solution 2: Forcer envoi POST au réveil

**Ajouter un flag pour forcer POST immédiatement au réveil**

```cpp
bool _justWokenUp = false; // Flag réveil

// Au réveil (dans wakeupFromSleep ou équivalent)
_justWokenUp = true;

// Dans update()
if (_justWokenUp || (currentMillis - lastSend > sendInterval)) {
  _justWokenUp = false;
  // ... envoi POST ...
}
```

**Avantages:**
- ✅ POST envoyé dès le réveil
- ✅ Pas de modification de l'ordre des priorités

**Inconvénients:**
- ❌ POST toujours lié au cycle de sleep (toutes les 10min)
- ❌ Ne résout pas le problème d'intervalle de 2 minutes

---

### Solution 3: Réduire durée de sleep

**Modifier `freqWakeSec` pour 120 secondes (2 minutes)**

```cpp
// Dans automatism.h
uint16_t freqWakeSec = 120; // 2 minutes au lieu de 10
```

**Avantages:**
- ✅ POST envoyé toutes les 2 minutes
- ✅ Cohérence entre sendInterval et freqWakeSec

**Inconvénients:**
- ❌ Réveils beaucoup plus fréquents → consommation énergétique accrue
- ❌ Moins de temps en sleep → batterie se décharge plus vite
- ❌ Plus de cycles WiFi reconnect → instabilité potentielle

---

### Solution 4: POST séparé en tâche

**Créer une tâche FreeRTOS dédiée pour POST périodique**

```cpp
void postTask(void* parameter) {
  while (true) {
    vTaskDelay(pdMS_TO_TICKS(120000)); // 2 minutes
    if (WiFi.status() == WL_CONNECTED) {
      sendFullUpdate(_lastReadings, "resetMode=0");
    }
  }
}

// Dans setup()
xTaskCreate(postTask, "POST", 4096, NULL, 1, NULL);
```

**Avantages:**
- ✅ POST indépendant du cycle principal
- ✅ Fréquence précise garantie

**Inconvénients:**
- ❌ Complexité accrue (gestion multi-tâches)
- ❌ Consommation mémoire supplémentaire (stack tâche)
- ❌ Problèmes potentiels avec light sleep (tâche peut bloquer sleep)

---

## 🎯 RECOMMANDATION FINALE

### **Solution 1: POST AVANT Sleep** ⭐⭐⭐⭐⭐

**Justification:**
1. ✅ **Simple et efficace** - Déplacement de code minimal
2. ✅ **Garantit l'intervalle de 2 minutes** - POST envoyé avant sleep
3. ✅ **Pas d'impact énergétique** - Sleep conservé tel quel
4. ✅ **Cohérent avec GET** - GET déjà exécuté avant sleep
5. ✅ **Testable immédiatement** - Modification isolée

### Ordre d'exécution recommandé

```
1. Lecture capteurs
2. Nourrissage (priorité absolue)
3. Remplissage (priorité absolue)
4. GET remote state (récupération config)
5. ⭐ POST data (envoi mesures) ← NOUVEAU
6. Check sleep early (entrée en veille si conditions)
7. Alertes et automatis secondaires
8. Sleep fallback
9. Sauvegarde périodique
```

---

## 📝 MODIFICATIONS À APPORTER

### Fichier: `src/automatism.cpp`

**Déplacer bloc ligne 611-641 AVANT ligne 572 (check sleep early)**

**Code actuel:**
```cpp
// Ligne 572
if (shouldEnterSleepEarly(readings)) {
  handleAutoSleep(readings);
  return;
}

// ...

// Ligne 591
handleRemoteState();

// Ligne 595
handleAutoSleep(readings);

// ...

// Ligne 611
if (currentMillis - lastSend > sendInterval) {
  // POST data
}
```

**Code modifié:**
```cpp
// GET remote state (conservé en priorité)
handleRemoteState();

// NOUVEAU: POST AVANT sleep (priorité haute)
unsigned long currentMillis = millis();
if (currentMillis - lastSend > sendInterval) {
  sendState = 0;
  bool okSend = sendFullUpdate(readings, "resetMode=0");
  if (_disp.isPresent()) {
    int diffNow = _sensors.diffMaree(readings.wlAqua);
    int8_t tideDir = 0;
    if (diffNow > tideTriggerCm) tideDir = 1;
    else if (diffNow < -tideTriggerCm) tideDir = -1;
    _lastDiffMaree = diffNow;
    
    _disp.beginUpdate();
    bool blinkNow2 = (mailBlinkUntil && isStillPending(mailBlinkUntil, currentMillis) && ((currentMillis/200)%2));
    _disp.drawStatus(sendState, recvState, WiFi.isConnected()?WiFi.RSSI():-127,
                     blinkNow2, tideDir, diffNow);
    _disp.endUpdate();
  }
  sendState = okSend ? 1 : -1;
  serverOk = okSend;
  lastSend = currentMillis;
}

// Check sleep early (peut sortir de update)
if (shouldEnterSleepEarly(readings)) {
  handleAutoSleep(readings);
  return;
}

// ... reste du code ...

handleAutoSleep(readings); // Fallback

// Sauvegarde périodique (déplacer currentMillis plus haut)
static unsigned long lastSave = 0;
if (currentMillis - lastSave > 60000) {
  lastSave = currentMillis;
  saveFeedingState();
}
```

---

## 🧪 TESTS ATTENDUS

### Avant modification (comportement actuel)
- POST envoyé: ~1 fois toutes les 10+ minutes
- GET remote: toutes les 30 secondes ✅
- Sleep: régulier tous les ~30-60s d'éveil

### Après modification (comportement attendu)
- POST envoyé: toutes les **2 minutes** ✅
- GET remote: toutes les 30 secondes ✅ (inchangé)
- Sleep: régulier (peut avoir ~500ms de délai si POST en cours)

### Procédure de test

1. Appliquer la modification
2. Compiler et flasher
3. **Monitoring de 10 minutes minimum**
4. Vérifier nombre de POST envoyés:
   - Attendu: **4-5 POST en 10 minutes**
   - Tolérance: ±1 POST (selon timing exact)

---

## 📊 IMPACT ESTIMÉ

### Mémoire
- **Aucun impact** - Pas de nouvelle allocation
- Juste déplacement de code existant

### Performance
- **Retard entrée en sleep:** ~300-800ms (durée HTTP POST)
- **Impact négligeable** - Sleep toujours activé après POST

### Consommation énergétique
- **Aucun impact significatif** - Même nombre de POST/cycle
- POST déplacé mais pas ajouté

### Stabilité
- **Amélioration** - Données envoyées plus régulièrement
- **Risque faible** - Code déjà testé et fonctionnel, juste réordonné

---

## ✅ VALIDATION

### Critères de succès

1. ✅ POST envoyé toutes les 2 minutes (±10s tolérance)
2. ✅ GET remote toujours fonctionnel (30s)
3. ✅ Sleep conserve sa durée de 10 minutes
4. ✅ Pas de crash/reboot
5. ✅ Mémoire stable
6. ✅ WiFi stable

### Monitoring post-déploiement

**Durée:** 15 minutes minimum  
**Métriques à surveiller:**
- Nombre de POST envoyés (attendu: 7-8)
- Intervalle entre POST (attendu: ~120s)
- Stabilité WiFi (RSSI, reconnexions)
- Mémoire heap (pas de fuite)
- Pas d'erreur watchdog

---

## 📚 DOCUMENTATION ASSOCIÉE

- `ANALYSE_ENVOI_DONNEES_SERVEUR.md` - Analyse complète du monitoring 7min
- `include/automatism.h` - Configuration sendInterval et freqWakeSec
- `src/automatism.cpp` - Fonction update() à modifier

---

**Prochaine étape:** Implémenter Solution 1 (POST avant sleep) et tester sur 15 minutes.

**Fin du rapport - 2025-10-11**

