# 📊 ANALYSE DÉTAILLÉE - Envoi des Données vers le Serveur
**Date:** 2025-10-11  
**Durée de monitoring:** ~7 minutes  
**Version ESP32:** 11.03

---

## 🎯 RÉSUMÉ EXÉCUTIF

### Findings Critiques
1. ✅ **Envoi POST vers serveur distant fonctionne correctement**
2. ⚠️ **Fréquence d'envoi trop basse** (1 seul POST observé en 7 minutes)
3. ✅ **GET remote state très fréquent** (~toutes les 30 secondes)
4. ⚠️ **Capteur DHT22 instable** (requiert récupération constante)
5. ✅ **Capteurs ultrason HC-SR04 très stables**
6. ✅ **Température eau DS18B20 stable et fiable**

---

## 📡 ANALYSE DES COMMUNICATIONS HTTP

### 1️⃣ Requêtes GET Remote State (Lecture serveur)

**Fréquence observée:** ~Toutes les 30 secondes

**Pattern typique:**
```
[HTTP] → GET remote state
[Web] GET remote state -> HTTP 200
[HTTP] ← received 237 bytes
[Web] ✓ Remote JSON parsed successfully
[Config] Variables distantes inchangées - pas de sauvegarde NVS
```

**Analyse:**
- ✅ Connexion stable et fiable
- ✅ Parsing JSON réussi à chaque fois
- ✅ Réponse serveur cohérente (237 bytes)
- ✅ Pas de timeout observé
- ✅ Latence faible (réponse immédiate)

**Données reçues du serveur:**
```
[DEBUG] Commande GPIO reçue: 16 = 1 (id=16, valBool=true)
[Auto] Pompe aqua GPIO commande IGNORÉE - état déjà ON (commande redondante)
[DEBUG] Commande GPIO reçue: 18 = 0 (id=18, valBool=false)
[Auto] Arrêt manuel pompe réservoir IGNORÉ - pompe verrouillée par sécurité
[DEBUG] Commande GPIO reçue: 2 = 0 (id=2, valBool=false)
[DEBUG] Commande chauffage: GPIO 2 = OFF
[Auto] Chauffage GPIO commande IGNORÉE - état déjà OFF (commande redondante)
[DEBUG] Commande GPIO reçue: 15 = 1 (id=15, valBool=true)
[Auto] Lumière GPIO commande IGNORÉE - état déjà ON (commande redondante)
```

**Observations:**
- Le serveur envoie des commandes GPIO à chaque GET
- Beaucoup de commandes sont redondantes (état déjà correct)
- La pompe réservoir est verrouillée par sécurité (comportement normal)

---

### 2️⃣ Requête POST Data (Envoi capteurs vers serveur)

**Fréquence observée:** 1 seul POST en ~7 minutes ⚠️

**Timestamp:** Environ 3-4 minutes après le début du monitoring

**Détails du POST:**
```
[HTTP] → http://iot.olution.info/ffp3/public/post-data-test (487 bytes)
[HTTP] payload: api_key=<API_KEY .env>&sensor=esp32-wroom&version=11.03&TempAir=26.7&Humidite=63.0&TempEau=28.2&EauPotager=209&EauAquarium=209&EauReserve=208&diffMaree=0&Luminosite=1204&etatPompeAqua=1&etatPompeTank=0&etatHeat=0&etatUV=1&bouffeMatin=8&bouffeMidi=12&bouffeSoir=19&tempsGros=10&tempsPetits=10&aqThr ... (truncated)
[HTTP] ← code 200, 4079 bytes
```

**Données envoyées au serveur:**
- `api_key`: `<API_KEY .env>` (jamais en clair dans la doc)
- `sensor`: esp32-wroom
- `version`: 11.03
- `TempAir`: 26.7°C
- `Humidite`: 63.0%
- `TempEau`: 28.2°C
- `EauPotager`: 209 cm
- `EauAquarium`: 209 cm
- `EauReserve`: 208 cm
- `diffMaree`: 0 cm
- `Luminosite`: 1204
- `etatPompeAqua`: 1 (ON)
- `etatPompeTank`: 0 (OFF)
- `etatHeat`: 0 (OFF)
- `etatUV`: 1 (ON)
- `bouffeMatin`: 8h
- `bouffeMidi`: 12h
- `bouffeSoir`: 19h
- `tempsGros`: 10s
- `tempsPetits`: 10s
- `aqThr`: ... (tronqué)

**Réponse serveur:**
- Code: 200 OK ✅
- Taille: 4079 bytes (page HTML complète)
- Latence: Normale

**État des flags de nourrissage après POST:**
```
=== ÉTAT DES FLAGS DE BOUFFE ===
Bouffe Matin: ✗ À FAIRE
Bouffe Midi:  ✓ FAIT
Bouffe Soir:  ✗ À FAIRE
Dernier jour: 283
Pompe lock:   LIBRE
===============================
```

---

## 📈 FRÉQUENCE D'ENVOI DES DONNÉES

### Timeline observée (7 minutes)

| Temps | Événement | Type |
|-------|-----------|------|
| T+0s | GET remote state | Lecture |
| T+30s | GET remote state | Lecture |
| T+60s | GET remote state | Lecture |
| T+90s | GET remote state | Lecture |
| T+120s | GET remote state | Lecture |
| T+150s | GET remote state | Lecture |
| T+180s | GET remote state | Lecture |
| **T+~210s** | **POST data-test** | **Envoi** |
| T+240s | GET remote state | Lecture |
| T+270s | GET remote state | Lecture |
| T+300s | GET remote state | Lecture |
| T+330s | GET remote state | Lecture |
| T+360s | GET remote state | Lecture |
| T+390s | GET remote state | Lecture |
| T+420s | GET remote state | Lecture |

### Analyse de fréquence

**GET remote state:**
- ✅ Fréquence: ~30 secondes
- ✅ Très régulier
- ✅ Pas de manquement observé

**POST data:**
- ⚠️ Fréquence: **1 seul POST en 7 minutes**
- ⚠️ **Attendu:** POST toutes les 3-5 minutes (selon config)
- 🔍 **À vérifier:** Configuration du timer d'envoi POST

---

## 🔬 ANALYSE DES CAPTEURS

### Capteur DHT22 (Température/Humidité Air)

**Performances:**
- ⚠️ **Instable** - Requiert récupération fréquente
- ⏱️ Temps de lecture: 400-437ms (normal) ou 3439ms avec reset
- 🔄 Pattern observé:

```
[AirSensor] Filtrage avancé échoué, tentative de récupération...
[AirSensor] Tentative de récupération 1/2...
[AirSensor] Récupération réussie: 63.0%
[SystemSensors] ⏱️ Humidité: 437 ms
```

**Événement critique observé:**
```
[AirSensor] Capteur DHT non détecté ou déconnecté
[AirSensor] Capteur non connecté, reset matériel...
[AirSensor] Reset matériel du capteur...
[AirSensor] Historique réinitialisé
[AirSensor] Reset matériel terminé
[AirSensor] Tentative de récupération 1/2...
[AirSensor] Récupération réussie: 63.0%
[SystemSensors] ⏱️ Humidité: 3439 ms  ⚠️ (délai important)
```

**Valeurs obtenues:**
- Température: 26.7°C (stable)
- Humidité: 63.0-64.0% (stable)

**Recommandations DHT22:**
1. ⚠️ Vérifier câblage/alimentation
2. ⚠️ Ajouter condensateur 100nF sur VCC
3. ⚠️ Résistance pull-up 10kΩ sur DATA
4. ⚠️ Envisager remplacement capteur (peut-être défectueux)

---

### Capteur DS18B20 (Température Eau)

**Performances:**
- ✅ **Très stable** - Aucune erreur
- ⏱️ Temps de lecture: 773-774ms (constant)
- ✅ Filtrage avancé fonctionne parfaitement

```
[WaterTemp] Température lissée: 28.2°C -> 28.2°C
[WaterTemp] Dernière température valide sauvegardée en NVS: 28.2°C
[WaterTemp] Température filtrée: 28.2°C (médiane: 28.2°C, lissée: 28.2°C, 2 lectures, résolution: 10 bits)
[SystemSensors] ⏱️ Température eau: 773 ms
```

**Valeurs obtenues:**
- Température: 28.2°C (très stable)
- Une lecture à 28.4-28.5°C en fin de monitoring (variation normale)

**Conclusion DS18B20:** ✅ Capteur fiable et performant

---

### Capteurs HC-SR04 (Niveaux Eau - Ultrason)

**Performances:**
- ✅ **Très stables** - Aucune erreur
- ⏱️ Temps de lecture: 218-222ms par capteur
- ✅ Médiane sur 3 lectures fonctionne parfaitement

**Niveau Potager:**
```
[Ultrasonic] Lecture 1: 209 cm
[Ultrasonic] Lecture 2: 209 cm
[Ultrasonic] Lecture 3: 209 cm
[Ultrasonic] Distance médiane: 209 cm (3 lectures valides)
[SystemSensors] ⏱️ Niveau potager: 219 ms
```

**Niveau Aquarium:**
```
[Ultrasonic] Lecture 1: 209 cm
[Ultrasonic] Lecture 2: 209 cm
[Ultrasonic] Lecture 3: 209 cm
[Ultrasonic] Distance médiane: 209 cm (3 lectures valides)
[SystemSensors] ⏱️ Niveau aquarium: 219 ms
```

**Niveau Réservoir:**
```
[Ultrasonic] Lecture 1: 208 cm
[Ultrasonic] Lecture 2: 208 cm
[Ultrasonic] Lecture 3: 208 cm
[Ultrasonic] Distance médiane: 208 cm (3 lectures valides)
[SystemSensors] ⏱️ Niveau réservoir: 219 ms
```

**Observations:**
- Une seule lecture aberrante observée: 170 cm (rejetée par médiane) ✅
- Stabilité exceptionnelle sur toute la durée
- Filtrage médiane efficace contre les outliers

**Conclusion HC-SR04:** ✅ Capteurs très fiables

---

### Capteur de Luminosité

**Performances:**
- ✅ **Très rapide** - 13ms par lecture
- ✅ Aucune erreur

**Valeur obtenue:**
- Luminosité: 1204 (unité arbitraire)

---

## 🌊 ANALYSE DÉTECTION MARÉE

**Calculs toutes les 15 secondes:**
```
[Maree] Calcul15s: actuel=208, diff15s=1 cm
[Maree] Calcul15s: actuel=209, diff15s=0 cm
[Maree] Calcul15s: actuel=209, diff15s=-1 cm
```

**Analyse toutes les 10 secondes:**
```
[Auto] Marée (10s): wlAqua=208 cm, diff10s=1 cm, dir=0
[Auto] Marée (10s): wlAqua=209 cm, diff10s=0 cm, dir=0
[Auto] Marée (10s): wlAqua=209 cm, diff10s=-1 cm, dir=0
```

**Observations:**
- ✅ Variations très faibles: -1 à +1 cm
- ✅ Direction: 0 (stable, pas de marée détectée)
- ✅ Système de détection fonctionne correctement

**Rappel logique:**
- Valeur élevée = niveau d'eau **faible** (capteur loin de l'eau)
- Valeur faible = niveau d'eau **important** (capteur proche de l'eau)

---

## ⏱️ PERFORMANCES SYSTÈME

### Temps de lecture capteurs

**Cycle normal (sans reset DHT):**
```
[SystemSensors] ✓ Lecture capteurs terminée en 1881-1894 ms
```

**Détail:**
- Température eau: 773ms (42%)
- Humidité: 400-437ms (23%)
- Niveau potager: 219ms (12%)
- Niveau aquarium: 219ms (12%)
- Niveau réservoir: 219ms (12%)
- Température air: 0-27ms (<1%)
- Luminosité: 13ms (<1%)

**Cycle avec reset DHT (1 fois observé):**
```
[SystemSensors] ✓ Lecture capteurs terminée en 4919 ms
```

**Impact reset DHT:**
- Temps normal: ~1890ms
- Avec reset: ~4920ms
- **Surcoût: +3030ms (2.6x plus lent)**

---

## 📊 RÉCAPITULATIF DES TIMINGS

| Capteur | Temps moyen | % du total | Stabilité |
|---------|-------------|------------|-----------|
| DS18B20 (eau) | 773ms | 42% | ✅ Excellent |
| DHT22 (air) | 400-437ms | 23% | ⚠️ Instable |
| HC-SR04 (potager) | 219ms | 12% | ✅ Excellent |
| HC-SR04 (aquarium) | 219ms | 12% | ✅ Excellent |
| HC-SR04 (réservoir) | 219ms | 12% | ✅ Excellent |
| Luminosité | 13ms | <1% | ✅ Excellent |
| **TOTAL** | **~1890ms** | **100%** | ✅ Bon |

---

## 🔍 POINTS D'ATTENTION IDENTIFIÉS

### 🔴 Critique - Fréquence POST trop basse

**Problème:**
- Seulement 1 POST observé en 7 minutes
- Attendu: POST toutes les 3-5 minutes

**Impact:**
- Données pas assez fréquentes sur le serveur distant
- Graphiques peu réactifs

**Actions recommandées:**
1. Vérifier la configuration du timer POST dans le code
2. Vérifier si condition d'envoi bloque (ex: changement de données requis)
3. Vérifier les logs pour identifier pourquoi POST pas déclenché

**Fichiers à examiner:**
- `src/web_server.cpp` - Fonction d'envoi POST
- `src/app.cpp` - Timer de déclenchement POST
- Configuration du délai entre POST

---

### 🟡 Important - DHT22 instable

**Problème:**
- Filtrage avancé échoue régulièrement
- Nécessite récupération à chaque lecture
- 1 reset matériel observé en 7 minutes

**Impact:**
- Ralentit cycle de lecture (jusqu'à 3.4s au lieu de 0.4s)
- Peut causer timeouts si trop fréquent

**Actions recommandées:**
1. ⚠️ Vérifier câblage et alimentation
2. ⚠️ Ajouter condensateur de découplage
3. ⚠️ Vérifier résistance pull-up
4. 🔧 Envisager remplacement si problème persiste

---

### 🟢 Bon - Commandes GPIO redondantes

**Observation:**
- Beaucoup de commandes GPIO reçues du serveur sont ignorées
- État déjà correct

**Impact:**
- Aucun (comportement normal)
- Logs un peu verbeux

**Note:**
- C'est normal si l'interface web ne change pas l'état
- Le serveur renvoie l'état complet à chaque GET

---

## ✅ POINTS POSITIFS

1. ✅ **WiFi très stable** - RSSI: -67 dBm (Acceptable)
2. ✅ **Serveur HTTP distant répond bien** - Code 200, pas de timeout
3. ✅ **Parsing JSON fiable** - Aucune erreur
4. ✅ **DS18B20 excellent** - Température eau très stable
5. ✅ **HC-SR04 excellents** - 3 capteurs ultrason très fiables
6. ✅ **Détection marée fonctionne** - Calculs corrects
7. ✅ **GET remote state très régulier** - Toutes les 30s
8. ✅ **Pas de crash/reboot** - Système stable
9. ✅ **Watchdog OK** - Pas de reset watchdog
10. ✅ **Mémoire OK** - Pas de warning mémoire

---

## 🎯 RECOMMANDATIONS PRIORITAIRES

### 1️⃣ URGENT - Augmenter fréquence POST

**Action:**
```cpp
// Vérifier dans src/app.cpp ou web_server.cpp
// Timer POST data devrait être ~3-5 minutes
// Actuellement semble être trop long ou condition bloquée
```

**Vérifier:**
- Configuration `POST_INTERVAL` ou équivalent
- Condition d'envoi (ex: données changées?)
- Logs pour identifier cause

---

### 2️⃣ IMPORTANT - Stabiliser DHT22

**Actions:**
1. Hardware:
   - Vérifier câblage
   - Ajouter condensateur 100nF sur VCC/GND
   - Vérifier résistance pull-up 10kΩ
   - Alimentation stable 3.3V ou 5V

2. Software:
   - Déjà bien géré (récupération automatique)
   - Peut-être augmenter délai entre lectures

3. Si problème persiste:
   - Remplacer capteur DHT22
   - Ou utiliser AM2302 (équivalent plus fiable)

---

### 3️⃣ OPTIONNEL - Réduire verbosité logs

**Actions:**
- Réduire logs pour commandes GPIO redondantes
- Peut-être passer en mode DEBUG uniquement

---

## 📝 CONCLUSION

### Système global: ✅ **STABLE ET FONCTIONNEL**

**Forces:**
- Communication HTTP fiable
- Capteurs ultrason excellents
- Température eau très stable
- Détection marée précise
- Pas de crash/problème majeur

**Faiblesses:**
- ⚠️ **Fréquence POST trop basse** (1/7min au lieu de 1/3-5min)
- ⚠️ DHT22 instable (mais géré par récupération auto)

**Priorité absolue:**
🔴 **Identifier et corriger pourquoi POST data n'est envoyé qu'une fois en 7 minutes**

---

## 🔍 PROCHAINES ÉTAPES

1. ✅ **Analyser code source** - Timer et conditions d'envoi POST
2. ✅ **Identifier configuration** - Intervalle POST attendu vs réel
3. ⚠️ **Corriger fréquence POST** si nécessaire
4. ⚠️ **Tester DHT22** - Hardware (câblage, condensateur)
5. ✅ **Continuer monitoring** - Vérifier stabilité long terme

---

**Fin de l'analyse - 2025-10-11**

