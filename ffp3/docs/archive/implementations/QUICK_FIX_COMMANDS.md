# ⚡ Commandes Rapides - Diagnostic ESP32

Guide ultra-rapide pour diagnostiquer et réparer le problème de publication ESP32.

---

## 🚀 Diagnostic en 30 secondes

```bash
# 1. Se connecter au serveur
ssh user@iot.olution.info

# 2. Aller dans le répertoire
cd /home4/oliviera/iot.olution.info/ffp3/ffp3datas

# 3. Lancer le diagnostic automatique
bash tools/quick_diagnostic.sh
```

**OU** directement depuis votre machine:

```bash
# Diagnostic rapide depuis votre PC
curl -X POST "https://iot.olution.info/ffp3/public/post-data" \
  -d "api_key=fdGTMoptd5CD2ert3&sensor=TEST&TempAir=22.5"
```

**Résultat attendu**: `Données enregistrées avec succès`

---

## 📊 Vérifier les dernières données (SQL)

```sql
-- Se connecter à MySQL
mysql -u oliviera_iot -p

-- Vérifier dernière donnée
USE oliviera_iot;

SELECT 
    reading_time,
    sensor,
    version,
    TempAir,
    TempEau,
    TIMESTAMPDIFF(MINUTE, reading_time, NOW()) as minutes_ago
FROM ffp3Data 
ORDER BY reading_time DESC 
LIMIT 1;
```

**Si `minutes_ago` > 60** → L'ESP32 ne publie plus ❌

---

## 🔧 Tests Serveur

### Test 1: Serveur accessible?

```bash
curl -I https://iot.olution.info/ffp3/
```

**Attendu**: `HTTP/2 200` ou `HTTP/2 301`

---

### Test 2: Endpoint POST fonctionne?

```bash
curl -X POST https://iot.olution.info/ffp3/public/post-data \
  -d "api_key=fdGTMoptd5CD2ert3&sensor=CURL-TEST&TempAir=20"
```

**Attendu**: `Données enregistrées avec succès`

---

### Test 3: Vérifier la BDD

```sql
-- Après le test curl ci-dessus
SELECT * FROM ffp3Data 
WHERE sensor = 'CURL-TEST' 
ORDER BY reading_time DESC LIMIT 1;

-- Nettoyer
DELETE FROM ffp3Data WHERE sensor = 'CURL-TEST';
```

---

## 📜 Vérifier les logs

```bash
cd /home4/oliviera/iot.olution.info/ffp3/ffp3datas

# Logs erreurs PHP (dernières 50 lignes)
tail -n 50 error_log

# Logs erreurs public (dernières 50 lignes)
tail -n 50 public/error_log

# Logs CRON (dernières 50 lignes)
tail -n 50 cronlog.txt

# Logs POST data (si existent)
tail -n 50 var/logs/post-data.log

# Suivre les logs en temps réel (Ctrl+C pour arrêter)
tail -f error_log
```

---

## 🔑 Vérifier la configuration

```bash
cd /home4/oliviera/iot.olution.info/ffp3/ffp3datas

# Vérifier API Key
grep "API_KEY" .env

# Vérifier config BDD
grep -E "DB_HOST|DB_NAME|DB_USER" .env

# Vérifier environnement (prod/test)
grep "ENV=" .env
```

---

## 🔄 Redémarrages d'urgence

### Redémarrer Apache

```bash
# Méthode 1 (systemd)
sudo systemctl restart httpd

# Méthode 2 (service)
sudo service httpd restart

# Vérifier le statut
sudo systemctl status httpd
```

### Redémarrer MySQL (si nécessaire)

```bash
# Redémarrer
sudo systemctl restart mysql

# Vérifier le statut
sudo systemctl status mysql
```

### Redémarrer l'ESP32

```
Physiquement: Débrancher et rebrancher l'alimentation
Attendre 10 secondes pour que le boot soit complet
```

---

## 🐛 Erreurs courantes et solutions

### Erreur: `401 Clé API incorrecte`

```bash
# Vérifier l'API Key
grep "^API_KEY=" .env

# Tester avec la clé extraite
API_KEY=$(grep "^API_KEY=" .env | cut -d'=' -f2)
curl -X POST https://iot.olution.info/ffp3/public/post-data \
  -d "api_key=${API_KEY}&sensor=TEST&TempAir=20"
```

**Action**: Mettre à jour l'API Key dans le code ESP32 pour qu'elle corresponde à `.env`

---

### Erreur: `500 Erreur serveur`

```bash
# Voir les logs d'erreurs
tail -n 100 error_log | grep -i "error\|fatal"

# Vérifier espace disque
df -h

# Vérifier que MySQL fonctionne
systemctl status mysql

# Tester connexion BDD
mysql -u oliviera_iot -p -e "SELECT 1"
```

---

### Erreur: `405 Méthode non autorisée`

**Cause**: L'ESP32 envoie un GET au lieu d'un POST

**Action**: Vérifier le code ESP32, s'assurer d'utiliser `http.POST()`

---

### Timeout / Pas de réponse

```bash
# Vérifier qu'Apache écoute sur le port 443
netstat -tlnp | grep :443

# Tester en local (sur le serveur)
curl -I http://localhost/ffp3/

# Vérifier le certificat SSL
openssl s_client -connect iot.olution.info:443 -servername iot.olution.info
```

---

## 📡 Diagnostics ESP32

### Via Logs Série (USB)

```cpp
// Dans le code ESP32, ajouter:
Serial.begin(115200);

// Dans loop(), avant POST:
Serial.println("[WiFi] Status: " + String(WiFi.status()));
Serial.println("[WiFi] RSSI: " + String(WiFi.RSSI()) + " dBm");
Serial.println("[HTTP] URL: " + String(serverUrl));
Serial.println("[HTTP] POSTing...");

int httpCode = http.POST(postData);

Serial.println("[HTTP] Response: " + String(httpCode));
Serial.println("[HTTP] Body: " + http.getString());
```

**Ouvrir le moniteur série (115200 baud)** et observer les messages

---

### Vérifier URL et API Key

```cpp
// Dans le code ESP32, vérifier:
const char* serverUrl = "https://iot.olution.info/ffp3/public/post-data";
const char* apiKey = "fdGTMoptd5CD2ert3";

// PAS:
// ❌ "http://..." (sans HTTPS)
// ❌ ".../ffp3datas/..." (ancien chemin)
// ❌ API Key différente
```

---

### Test Minimal ESP32

Code de test minimal pour vérifier la connectivité:

```cpp
#include <WiFi.h>
#include <HTTPClient.h>

const char* ssid = "VOTRE_SSID";
const char* password = "VOTRE_PASSWORD";
const char* serverUrl = "https://iot.olution.info/ffp3/public/post-data";
const char* apiKey = "fdGTMoptd5CD2ert3";

void setup() {
    Serial.begin(115200);
    WiFi.begin(ssid, password);
    
    while (WiFi.status() != WL_CONNECTED) {
        delay(500);
        Serial.print(".");
    }
    Serial.println("\n✓ WiFi OK");
}

void loop() {
    HTTPClient http;
    http.begin(serverUrl);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    http.setTimeout(10000);
    
    String data = "api_key=" + String(apiKey) + "&sensor=TEST&TempAir=22.5";
    
    Serial.println("[POST] " + serverUrl);
    int code = http.POST(data);
    
    Serial.println("[" + String(code) + "] " + http.getString());
    http.end();
    
    delay(180000); // 3 min
}
```

---

## 🔍 Historique des données

```sql
-- Voir le nombre de lectures par heure (dernières 24h)
SELECT 
    DATE_FORMAT(reading_time, '%Y-%m-%d %H:00') as hour,
    COUNT(*) as count
FROM ffp3Data 
WHERE reading_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY DATE_FORMAT(reading_time, '%Y-%m-%d %H:00')
ORDER BY hour DESC;

-- Attendu: ~20-30 lectures par heure (toutes les 2-3 min)
-- Si 0 lectures sur une heure = L'ESP32 n'a pas publié pendant cette heure

-- Voir les capteurs actifs
SELECT sensor, COUNT(*) as count, MAX(reading_time) as last_seen
FROM ffp3Data
WHERE reading_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY sensor
ORDER BY last_seen DESC;
```

---

## 🩺 Diagnostic Complet

Si les commandes rapides ne suffisent pas:

```bash
# Sur le serveur
cd /home4/oliviera/iot.olution.info/ffp3/ffp3datas

# Exécuter le diagnostic complet
php tools/diagnostic_esp32.php
```

**OU**

```bash
# Script shell (plus rapide, moins détaillé)
bash tools/quick_diagnostic.sh
```

---

## 📚 Documentation Complète

- **Guide détaillé**: [DIAGNOSTIC_ESP32_TROUBLESHOOTING.md](./DIAGNOSTIC_ESP32_TROUBLESHOOTING.md)
- **API ESP32**: [ESP32_API_REFERENCE.md](./ESP32_API_REFERENCE.md)
- **Architecture**: [README.md](./README.md)

---

## ✅ Checklist Finale

Avant de clôturer le diagnostic:

- [ ] Serveur répond avec `200 OK` au curl
- [ ] Dernières données dans la BDD < 5 minutes
- [ ] Logs ne montrent pas d'erreurs
- [ ] ESP32 alimenté et WiFi connecté
- [ ] URL correcte dans le code ESP32
- [ ] API Key correcte dans le code ESP32
- [ ] Logs série ESP32 montrent des POST réussis

---

**Si tout est ✅ mais le problème persiste**, consulter [DIAGNOSTIC_ESP32_TROUBLESHOOTING.md](./DIAGNOSTIC_ESP32_TROUBLESHOOTING.md) pour un diagnostic approfondi.

