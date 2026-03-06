# 📡 Endpoints Finaux FFP3 v4.0.0 - Configuration Actuelle

**Date** : 11 octobre 2025  
**Version** : 4.0.0  
**Structure** : Code à la racine `/ffp3/` (plus de sous-dossier ffp3datas/)

---

## 🎯 URLs Principales

| Type | URL |
|------|-----|
| **Site Web** | https://iot.olution.info/ffp3/ |
| **Dashboard** | https://iot.olution.info/ffp3/dashboard |
| **Aquaponie** | https://iot.olution.info/ffp3/aquaponie |
| **Contrôle** | https://iot.olution.info/ffp3/control |

---

## 📡 Endpoints ESP32 Actuels

### **1️⃣ Envoi Données Capteurs** (ESP32 → Serveur)

```
POST https://iot.olution.info/ffp3/public/post-data
```

**Paramètres** : `api_key` + données capteurs  
**Fréquence** : Toutes les 2-3 minutes  
**Réponse** : `"Données enregistrées avec succès"`

---

### **2️⃣ Récupération État GPIO** (ESP32 ← Serveur)

```
GET https://iot.olution.info/ffp3/api/outputs/state
```

**Aucun paramètre requis**  
**Fréquence** : Toutes les 2-3 minutes  
**Réponse JSON** :
```json
{
  "16": 1,    // Pompe Aquarium ON
  "18": 0,    // Pompe Réserve OFF
  "100": 1,   // Chauffage ON
  "101": 0,   // UV OFF
  "104": 0,   // LEDs OFF
  "108": 0,   // Nourriture petits OFF
  "109": 1,   // Nourriture gros ON
  "110": 0    // Reset OFF
}
```

---

### **3️⃣ Heartbeat** (ESP32 → Serveur)

```
POST https://iot.olution.info/ffp3/heartbeat.php
```

**Paramètres** : `uptime`, `free`, `min`, `reboots`, `crc`  
**Fréquence** : Toutes les 5-10 minutes  
**Réponse** : `"OK"`

---

### **4️⃣ OTA Firmware** (ESP32 ← Serveur)

```
GET https://iot.olution.info/ffp3/ota/metadata.json
GET https://iot.olution.info/ffp3/ota/esp32-wroom/firmware.bin
```

**Fréquence** : Toutes les 24 heures  
**Réponse** : JSON metadata + fichier binaire

---

## 🆕 Nouveaux Endpoints v4.0.0 (Frontend Temps Réel)

### **5️⃣ État Système** (Frontend JavaScript → Serveur)

```
GET https://iot.olution.info/ffp3/api/realtime/system/health
```

**Usage** : Polling frontend toutes les 15 secondes  
**Réponse JSON** :
```json
{
  "online": true,
  "last_reading": "2025-10-11 14:30:00",
  "last_reading_ago_seconds": 125,
  "uptime_percentage": 98.7,
  "readings_today": 480
}
```

---

### **6️⃣ Dernières Lectures Capteurs**

```
GET https://iot.olution.info/ffp3/api/realtime/sensors/latest
```

**Usage** : Polling frontend  
**Réponse JSON** :
```json
{
  "timestamp": 1697123456,
  "reading_time": "2025-10-11 14:30:00",
  "sensors": {
    "EauAquarium": 32.0,
    "EauReserve": 78.0,
    "TempEau": 24.0,
    "TempAir": 22.5,
    "Humidite": 65.0,
    "Luminosite": 850
  }
}
```

---

## 📋 Récapitulatif Complet

| Endpoint | Méthode | Appelé Par | Fréquence | Usage |
|----------|---------|------------|-----------|-------|
| `/public/post-data` | POST | **ESP32** | 2-3 min | Envoi données capteurs |
| `/api/outputs/state` | GET | **ESP32** | 2-3 min | Récupération GPIO |
| `/heartbeat.php` | POST | **ESP32** | 5-10 min | Keep-alive |
| `/ota/metadata.json` | GET | **ESP32** | 24h | Check firmware |
| `/ota/esp32-xxx/firmware.bin` | GET | **ESP32** | Si update | Download OTA |
| `/api/realtime/system/health` | GET | **Frontend** | 15s | État système |
| `/api/realtime/sensors/latest` | GET | **Frontend** | 15s | Dernières lectures |
| `/api/realtime/outputs/state` | GET | **Frontend** | 15s | État GPIO temps réel |

---

## 🔧 Configuration ESP32 (Code C++)

```cpp
// Configuration serveur
const char* serverHost = "iot.olution.info";
const char* apiKey = "fdGTMoptd5CD2ert3";

// Endpoints
const char* POST_DATA_URL = "https://iot.olution.info/ffp3/public/post-data";
const char* GET_GPIO_URL = "https://iot.olution.info/ffp3/api/outputs/state";
const char* HEARTBEAT_URL = "https://iot.olution.info/ffp3/heartbeat.php";
const char* OTA_URL = "https://iot.olution.info/ffp3/ota/metadata.json";

// Loop principal
void loop() {
    if (millis() - lastSend >= 180000) {  // 3 minutes
        // 1. Envoyer données
        sendDataToServer();
        
        // 2. Récupérer état GPIO
        getGPIOStates();
        
        lastSend = millis();
    }
}

void getGPIOStates() {
    HTTPClient http;
    http.begin(GET_GPIO_URL);
    int httpCode = http.GET();
    
    if (httpCode == 200) {
        DynamicJsonDocument doc(1024);
        deserializeJson(doc, http.getString());
        
        digitalWrite(16, doc["16"]);   // Pompe Aqua
        digitalWrite(18, doc["18"]);   // Pompe Tank
        digitalWrite(100, doc["100"]); // Chauffage
        digitalWrite(101, doc["101"]); // UV
        digitalWrite(104, doc["104"]); // LEDs
        digitalWrite(108, doc["108"]); // Nourriture petits
        digitalWrite(109, doc["109"]); // Nourriture gros
    }
    http.end();
}
```

---

## ⚠️ Points Importants

### **Structure Simplifiée**
- ✅ Plus de sous-dossier `/ffp3datas/` dans les URLs
- ✅ Code à la racine `/ffp3/`
- ✅ Endpoints directs : `/ffp3/api/*`

### **Compatibilité**
- ✅ ESP32 utilise déjà les nouveaux endpoints (`/ffp3/api/outputs/state`)
- ✅ Pas de changement nécessaire dans le firmware ESP32
- ✅ Tout fonctionne directement

### **Ancien Système**
- ⚠️ `/ffp3control/ffp3-outputs-action.php` encore disponible mais deprecated
- ✅ Migration vers `/ffp3/api/outputs/state` déjà effectuée

---

## 🧪 Tests Rapides

### **Test Endpoint ESP32 (GPIO)** :
```bash
curl https://iot.olution.info/ffp3/api/outputs/state
```

**Attendu** : `{"16":1,"18":0,"100":1,...}`

### **Test Endpoint Frontend (Santé)** :
```bash
curl https://iot.olution.info/ffp3/api/realtime/system/health
```

**Attendu** : `{"online":true,"uptime_percentage":98.7,...}`

### **Test Site Web** :
```
https://iot.olution.info/ffp3/
```

**Attendu** : 
- ✅ Badge LIVE visible
- ✅ Dashboard système affiche métriques
- ✅ Pas d'erreur 500

---

## 📚 Documentation Détaillée

| Fichier | Description |
|---------|-------------|
| `ESP32_API_REFERENCE.md` | Référence API ESP32 complète (894 lignes) |
| `COMMANDES_SERVEUR.txt` | Commandes de déploiement serveur |
| `DEPLOY_NOW.sh` | Script automatique de déploiement |
| `QUICKSTART_V4.md` | Guide démarrage rapide |
| `IMPLEMENTATION_REALTIME_PWA.md` | Guide technique v4.0.0 |

---

**Fin du document - Version 4.0.0**

