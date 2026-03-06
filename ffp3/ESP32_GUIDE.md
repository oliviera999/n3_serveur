# 📡 ESP32 Complete Guide - FFP3 Aquaponie IoT

**Project**: FFP3 Aquaponie IoT  
**Server Version**: 4.4.0  
**Date**: January 16, 2025  
**Server**: https://iot.olution.info/ffp3/  
**ESP32 Version**: v11.70 (Clés JSON standardisées)

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Quick Start](#quick-start)
3. [Endpoints](#endpoints)
4. [Authentication & Security](#authentication--security)
5. [Example Code (Arduino/ESP32)](#example-code-arduinoesp32)
6. [GPIO Mapping](#gpio-mapping)
7. [JSON Key Standardization (v11.70+)](#json-key-standardization-v1170)
8. [Troubleshooting](#troubleshooting)
9. [Configuration (PROD vs TEST)](#configuration-prod-vs-test)

---

## 🎯 Overview

### Communication Architecture

```
ESP32 (Sensors + GPIO)
      ↓ POST every 2-3 min
      ↓ 
Server (https://iot.olution.info/ffp3/)
      ↓ Database insertion
      ↓
MySQL Database
  ├─ ffp3Data (PROD)
  ├─ ffp3Data2 (TEST)
  ├─ ffp3Outputs (GPIO PROD)
  ├─ ffp3Outputs2 (GPIO TEST)
  ├─ ffp3Heartbeat (monitoring PROD)
  └─ ffp3Heartbeat2 (monitoring TEST)
```

### Communication Cycle (every 2-3 minutes)

1. **ESP32 reads** all sensors
2. **ESP32 POST** data to `/post-data`
3. **Server inserts** into database
4. **ESP32 GET** GPIO states from `/api/outputs/state`
5. **ESP32 applies** received GPIO states
6. **Wait** 2-3 minutes → Repeat

---

## 🚀 Quick Start

### Minimal ESP32 Test Code

```cpp
#include <WiFi.h>
#include <HTTPClient.h>

const char* ssid = "YOUR_SSID";
const char* password = "YOUR_PASSWORD";
const char* serverUrl = "https://iot.olution.info/ffp3/post-data";
const char* apiKey = "YOUR_API_KEY";

void setup() {
    Serial.begin(115200);
    WiFi.begin(ssid, password);
    
    while (WiFi.status() != WL_CONNECTED) {
        delay(500);
        Serial.print(".");
    }
    Serial.println("\nWiFi connected!");
}

void loop() {
    if (WiFi.status() == WL_CONNECTED) {
        HTTPClient http;
        http.begin(serverUrl);
        http.addHeader("Content-Type", "application/x-www-form-urlencoded");
        http.setTimeout(10000);
        
        String postData = "api_key=" + String(apiKey);
        postData += "&sensor=ESP32-Main";
        postData += "&version=1.0.0";
        postData += "&TempAir=22.5";
        postData += "&Humidite=65.0";
        postData += "&TempEau=24.0";
        
        int httpCode = http.POST(postData);
        
        if (httpCode == 200) {
            Serial.println("✓ Data sent successfully");
        } else {
            Serial.printf("✗ HTTP Error: %d\n", httpCode);
        }
        
        http.end();
    }
    
    delay(180000); // 3 minutes
}
```

---

## 📡 Endpoints

### 📤 PRODUCTION - Send Sensor Data

#### POST `/post-data`
**Alias**: `POST /post-ffp3-data.php`

Main ingestion endpoint for sensor data.

**Authentication**:
- **API Key** (legacy): `api_key` parameter in body
- **HMAC-SHA256 Signature** (recommended):
  - `timestamp`: Current Unix timestamp
  - `signature`: HMAC-SHA256 calculated with `API_SIG_SECRET`
  - Validation window: `SIG_VALID_WINDOW` seconds (default: 300s)

**Parameters** (application/x-www-form-urlencoded):

| Parameter | Type | Unit | Example | Description |
|-----------|------|------|---------|-------------|
| `api_key` | string | - | `YOUR_KEY` | **Required** - API authentication key |
| `timestamp` | int | - | `1697123456` | Optional - Unix timestamp for HMAC |
| `signature` | string | - | `abc123...` | Optional - HMAC-SHA256 signature |
| `sensor` | string | - | `ESP32-Main` | ESP32 identifier |
| `version` | string | - | `10.90` | Firmware version |
| `TempAir` | float | °C | `22.5` | Air temperature |
| `Humidite` | float | %RH | `65.0` | Relative humidity |
| `TempEau` | float | °C | `24.0` | Water temperature |
| `EauPotager` | float | cm | `45.0` | Garden water level |
| `EauAquarium` | float | cm | `32.0` | Aquarium water level |
| `EauReserve` | float | cm | `78.0` | Tank water level |
| `diffMaree` | float | cm | `2.5` | Tide difference |
| `Luminosite` | float | lux | `850` | Ambient light |
| `etatPompeAqua` | int | - | `1` | Aquarium pump state (0/1) |
| `etatPompeTank` | int | - | `0` | Tank pump state (0/1) |
| `etatHeat` | int | - | `1` | Heating state (0/1) |
| `etatUV` | int | - | `1` | UV light state (0/1) |
| `bouffeMatin` | int | - | `1` | Morning feeding done (0/1) |
| `bouffeMidi` | int | - | `0` | Noon feeding done (0/1) |
| `bouffeSoir` | int | - | `1` | Evening feeding done (0/1) |
| `bouffePetits` | int | - | `0` | Small fish feeder (0/1) |
| `bouffeGros` | int | - | `1` | Large fish feeder (0/1) |

**Responses**:
- `200 OK`: `Données enregistrées avec succès`
- `401 Unauthorized`: Invalid API key or signature
- `400 Bad Request`: Missing or invalid data
- `500 Internal Server Error`: Server error

---

### 📥 PRODUCTION - Get Configuration

#### GET `/api/outputs/state`

Retrieves current state of all configured GPIO/outputs.

**Response (JSON)**:
```json
{
  "4": 1,      // GPIO 4 (Aquarium Pump) = ON
  "5": 0,      // GPIO 5 (Tank Pump) = OFF
  "12": 1,     // GPIO 12 (Heating) = ON
  "13": 0,     // GPIO 13 (UV Light) = OFF
  "100": "user@example.com",  // Notification email
  "101": 1,    // Notifications enabled
  "102": 40,   // Aquarium low threshold (cm)
  "103": 30,   // Tank low threshold (cm)
  "104": 22,   // Heating threshold (°C)
  "105": 8,    // Morning feeding time (0-23)
  "106": 13,   // Noon feeding time (0-23)
  "107": 20,   // Evening feeding time (0-23)
  "108": 0,    // Feed small fish (trigger)
  "109": 0,    // Feed large fish (trigger)
  "110": 0,    // Reset ESP (trigger)
  "111": 5,    // Large feeding duration (seconds)
  "112": 3,    // Small feeding duration (seconds)
  "113": 120,  // Tank filling duration (seconds)
  "114": 95,   // Overflow threshold (cm)
  "115": 0,    // Force wake (0/1)
  "116": 900   // WakeUp frequency (seconds)
}
```

---

### 💓 PRODUCTION - Heartbeat

#### POST `/heartbeat`
**Alias**: `POST /heartbeat.php`

Regular ESP32 system status update.

**Parameters** (application/x-www-form-urlencoded):
- `uptime`: Runtime in seconds
- `free`: Current free memory (bytes)
- `min`: Minimum free memory since boot (bytes)
- `reboots`: Reboot counter
- `crc`: CRC32 calculated on `uptime={uptime}&free={free}&min={min}&reboots={reboots}`

**CRC32 Calculation**:
```c
// Use polynomial 0xEDB88320
String payload = "uptime=" + String(uptime) + 
                 "&free=" + String(freeHeap) + 
                 "&min=" + String(minHeap) + 
                 "&reboots=" + String(rebootCount);
uint32_t crc = calculateCRC32(payload);
String crcHex = String(crc, HEX);
crcHex.toUpperCase();
```

**Responses**:
- `200 OK`: `OK`
- `400 Bad Request`: Invalid CRC or missing fields
- `500 Internal Server Error`: Server error

---

### 🧪 TEST Endpoints

**All PROD endpoints have TEST equivalents:**

| PROD Endpoint | TEST Endpoint |
|--------------|---------------|
| `POST /post-data` | `POST /post-data-test` |
| `GET /api/outputs/state` | `GET /api/outputs-test/state` |
| `POST /heartbeat` | `POST /heartbeat-test` |

TEST endpoints use separate database tables (`ffp3Data2`, `ffp3Outputs2`, `ffp3Heartbeat2`).

---

## 🔐 Authentication & Security

### Method 1: API Key (Legacy) ✅ SIMPLE

**POST Parameter**: `api_key=YOUR_API_KEY`

**Server Configuration**: `.env` variable `API_KEY`

**Security**: ⚠️ Medium (key in cleartext)

---

### Method 2: HMAC-SHA256 ✅ RECOMMENDED

**POST Parameters**:
```
timestamp=1697123456
signature=abc123def456...
```

**Signature Generation (ESP32)**:

```cpp
#include <mbedtls/md.h>

String calculateHMAC(String timestamp, String secret) {
    byte hmacResult[32];
    mbedtls_md_context_t ctx;
    mbedtls_md_type_t md_type = MBEDTLS_MD_SHA256;
    
    mbedtls_md_init(&ctx);
    mbedtls_md_setup(&ctx, mbedtls_md_info_from_type(md_type), 1);
    mbedtls_md_hmac_starts(&ctx, (const unsigned char*)secret.c_str(), secret.length());
    mbedtls_md_hmac_update(&ctx, (const unsigned char*)timestamp.c_str(), timestamp.length());
    mbedtls_md_hmac_finish(&ctx, hmacResult);
    mbedtls_md_free(&ctx);
    
    String signature = "";
    for(int i = 0; i < 32; i++) {
        char hex[3];
        sprintf(hex, "%02x", hmacResult[i]);
        signature += hex;
    }
    return signature;
}

// Usage
unsigned long timestamp = timeClient.getEpochTime();
String signature = calculateHMAC(String(timestamp), API_SIG_SECRET);
```

**Server Validation**: Timestamp must be within validation window (±300s default)

**Security**: ✅ High (protection against replay attacks)

---

### Method 3: Dual Authentication ✅ MAXIMUM

Use **both**: `api_key` **AND** `timestamp` + `signature`

Server validates both methods (OR logic).

---

## 💻 Example Code (Arduino/ESP32)

### Complete Production-Ready Example

```cpp
#include <WiFi.h>
#include <HTTPClient.h>
#include <time.h>
#include <mbedtls/md.h>
#include <CRC32.h>
#include <ArduinoJson.h>

// Configuration
const char* WIFI_SSID = "YOUR_SSID";
const char* WIFI_PASSWORD = "YOUR_PASSWORD";
const char* API_KEY = "YOUR_API_KEY";
const char* API_SIG_SECRET = "YOUR_SECRET";
const char* SERVER_URL = "https://iot.olution.info/ffp3";

// Timing
unsigned long lastSendTime = 0;
unsigned long lastHeartbeat = 0;
const unsigned long SEND_INTERVAL = 180000; // 3 minutes
const unsigned long HEARTBEAT_INTERVAL = 600000; // 10 minutes

// GPIO Configuration
const int GPIO_PUMP_AQUA = 4;
const int GPIO_PUMP_TANK = 5;
const int GPIO_HEATING = 12;
const int GPIO_UV = 13;

void setup() {
    Serial.begin(115200);
    
    // Configure GPIO
    pinMode(GPIO_PUMP_AQUA, OUTPUT);
    pinMode(GPIO_PUMP_TANK, OUTPUT);
    pinMode(GPIO_HEATING, OUTPUT);
    pinMode(GPIO_UV, OUTPUT);
    
    // Connect WiFi
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    while (WiFi.status() != WL_CONNECTED) {
        delay(500);
        Serial.print(".");
    }
    Serial.println("\n✓ WiFi connected");
    Serial.println("IP: " + WiFi.localIP().toString());
    
    // Initialize NTP for timestamp
    configTime(0, 0, "pool.ntp.org");
}

void loop() {
    // Send sensor data every 3 minutes
    if (millis() - lastSendTime >= SEND_INTERVAL) {
        sendSensorData();
        getConfiguration();
        lastSendTime = millis();
    }
    
    // Send heartbeat every 10 minutes
    if (millis() - lastHeartbeat >= HEARTBEAT_INTERVAL) {
        sendHeartbeat();
        lastHeartbeat = millis();
    }
    
    delay(1000);
}

void sendSensorData() {
    if (WiFi.status() != WL_CONNECTED) return;
    
    HTTPClient http;
    String endpoint = String(SERVER_URL) + "/post-data";
    
    http.begin(endpoint);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    http.setTimeout(10000);
    
    // Read sensors
    float tempAir = readTempAir();
    float humidity = readHumidity();
    float tempEau = readTempWater();
    float eauAqua = readWaterLevelAquarium();
    float eauReserve = readWaterLevelTank();
    float lux = readLuminosity();
    
    // Build POST data
    String postData = "api_key=" + String(API_KEY);
    postData += "&sensor=ESP32-Main";
    postData += "&version=1.0.0";
    postData += "&TempAir=" + String(tempAir);
    postData += "&Humidite=" + String(humidity);
    postData += "&TempEau=" + String(tempEau);
    postData += "&EauAquarium=" + String(eauAqua);
    postData += "&EauReserve=" + String(eauReserve);
    postData += "&Luminosite=" + String(lux);
    postData += "&etatPompeAqua=" + String(digitalRead(GPIO_PUMP_AQUA));
    postData += "&etatPompeTank=" + String(digitalRead(GPIO_PUMP_TANK));
    postData += "&etatHeat=" + String(digitalRead(GPIO_HEATING));
    postData += "&etatUV=" + String(digitalRead(GPIO_UV));
    
    // Optional: Add HMAC signature for security
    unsigned long timestamp = getEpochTime();
    if (timestamp > 0) {
        String signature = calculateHMAC(String(timestamp), API_SIG_SECRET);
        postData += "&timestamp=" + String(timestamp);
        postData += "&signature=" + signature;
    }
    
    Serial.println("[HTTP] POST: " + endpoint);
    int httpCode = http.POST(postData);
    
    if (httpCode == 200) {
        Serial.println("✓ Data sent successfully");
    } else {
        Serial.printf("✗ HTTP Error: %d\n", httpCode);
        Serial.println("Response: " + http.getString());
    }
    
    http.end();
}

void getConfiguration() {
    if (WiFi.status() != WL_CONNECTED) return;
    
    HTTPClient http;
    String endpoint = String(SERVER_URL) + "/api/outputs/state";
    
    http.begin(endpoint);
    int httpCode = http.GET();
    
    if (httpCode == 200) {
        String payload = http.getString();
        
        // Parse JSON
        DynamicJsonDocument doc(1024);
        DeserializationError error = deserializeJson(doc, payload);
        
        if (!error) {
            // Apply GPIO states
            digitalWrite(GPIO_PUMP_AQUA, doc["4"] | 0);
            digitalWrite(GPIO_PUMP_TANK, doc["5"] | 0);
            digitalWrite(GPIO_HEATING, doc["12"] | 0);
            digitalWrite(GPIO_UV, doc["13"] | 0);
            
            Serial.println("✓ Configuration updated");
        }
    }
    
    http.end();
}

void sendHeartbeat() {
    if (WiFi.status() != WL_CONNECTED) return;
    
    HTTPClient http;
    String endpoint = String(SERVER_URL) + "/heartbeat";
    
    http.begin(endpoint);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    
    unsigned long uptime = millis() / 1000;
    uint32_t freeHeap = ESP.getFreeHeap();
    uint32_t minHeap = ESP.getMinFreeHeap();
    static uint32_t reboots = 0;
    
    // Calculate CRC32
    String payload = "uptime=" + String(uptime) +
                     "&free=" + String(freeHeap) +
                     "&min=" + String(minHeap) +
                     "&reboots=" + String(reboots);
    
    CRC32 crc;
    for (int i = 0; i < payload.length(); i++) {
        crc.update(payload.charAt(i));
    }
    uint32_t crcValue = crc.finalize();
    String crcHex = String(crcValue, HEX);
    crcHex.toUpperCase();
    
    String postData = payload + "&crc=" + crcHex;
    
    int httpCode = http.POST(postData);
    
    if (httpCode == 200) {
        Serial.println("✓ Heartbeat sent");
    } else {
        Serial.printf("✗ Heartbeat error: %d\n", httpCode);
    }
    
    http.end();
}

// Helper functions (implement according to your hardware)
float readTempAir() { return 22.5; }
float readHumidity() { return 65.0; }
float readTempWater() { return 24.0; }
float readWaterLevelAquarium() { return 32.0; }
float readWaterLevelTank() { return 78.0; }
float readLuminosity() { return 850.0; }

unsigned long getEpochTime() {
    time_t now;
    struct tm timeinfo;
    if (!getLocalTime(&timeinfo)) return 0;
    time(&now);
    return now;
}

String calculateHMAC(String data, String secret) {
    byte hmacResult[32];
    mbedtls_md_context_t ctx;
    mbedtls_md_type_t md_type = MBEDTLS_MD_SHA256;
    
    mbedtls_md_init(&ctx);
    mbedtls_md_setup(&ctx, mbedtls_md_info_from_type(md_type), 1);
    mbedtls_md_hmac_starts(&ctx, (const unsigned char*)secret.c_str(), secret.length());
    mbedtls_md_hmac_update(&ctx, (const unsigned char*)data.c_str(), data.length());
    mbedtls_md_hmac_finish(&ctx, hmacResult);
    mbedtls_md_free(&ctx);
    
    String signature = "";
    for (int i = 0; i < 32; i++) {
        char hex[3];
        sprintf(hex, "%02x", hmacResult[i]);
        signature += hex;
    }
    return signature;
}
```

---

## 🔌 GPIO Mapping

| GPIO | Name | Function | Type |
|------|------|----------|------|
| **4** | Aquarium Pump | Aquarium water circulation | Relay |
| **5** | Tank Pump | Filling from tank | Relay |
| **12** | Heating | Aquarium water heating | Relay |
| **13** | UV | UV sterilization | Relay |
| **100** | Notification Email | Email address | Config (string) |
| **101** | Notifications Enabled | Enable/disable notifications | Config (0/1) |
| **102** | Aquarium Low Threshold | Low water level (cm) | Config (int) |
| **103** | Tank Low Threshold | Low tank level (cm) | Config (int) |
| **104** | Heating Threshold | Minimum temperature (°C) | Config (int) |
| **105** | Morning Feeding Time | Hour (0-23) | Config (int) |
| **106** | Noon Feeding Time | Hour (0-23) | Config (int) |
| **107** | Evening Feeding Time | Hour (0-23) | Config (int) |
| **108** | Feed Small Fish | Trigger action | Action |
| **109** | Feed Large Fish | Trigger action | Action |
| **110** | Reset ESP | Reboot ESP32 | Action |
| **111** | Large Feeding Duration | Seconds | Config (int) |
| **112** | Small Feeding Duration | Seconds | Config (int) |
| **113** | Tank Filling Duration | Seconds | Config (int) |
| **114** | Overflow Threshold | Maximum level (cm) | Config (int) |
| **115** | Force Wake | Force wake mode | Config (0/1) |
| **116** | WakeUp Frequency | Wake interval (seconds) | Config (int) |

---

## 📋 JSON Key Standardization (v11.70+)

### Overview
Starting from ESP32 firmware v11.70, all JSON keys have been standardized across ESP32, server, and web interface to eliminate data conversion overhead and improve system consistency.

### Standardized Keys

| Parameter | JSON Key | Type | Description |
|-----------|----------|------|-------------|
| Email Address | `mail` | string | Notification email |
| Email Notifications | `mailNotif` | string | Enable/disable ("checked" or empty) |
| Large Feeding Duration | `tempsGros` | int | Duration in seconds |
| Small Feeding Duration | `tempsPetits` | int | Duration in seconds |
| Tank Filling Duration | `tempsRemplissageSec` | int | Duration in seconds |
| Heating Threshold | `chauffageThreshold` | float | Temperature in °C |
| Aquarium Threshold | `aqThreshold` | int | Water level in cm |
| Tank Threshold | `tankThreshold` | int | Water level in cm |
| Overflow Limit | `limFlood` | int | Maximum level in cm |
| Force Wake | `WakeUp` | int | Force wake mode (0/1) |
| Wake Frequency | `FreqWakeUp` | int | Wake interval in seconds |

### Feeding Times (GPIO Numbers)
Feeding times use GPIO numbers directly (no named keys):
- GPIO 105: Morning feeding hour (0-23)
- GPIO 106: Noon feeding hour (0-23) 
- GPIO 107: Evening feeding hour (0-23)

### Benefits
- ✅ **Eliminated 218 lines** of conversion code
- ✅ **No more memory duplication** (JSON copy eliminated)
- ✅ **Improved performance** (direct parsing)
- ✅ **System consistency** (same keys everywhere)
- ✅ **Easier maintenance** (single source of truth)

### Migration Notes
- **Backward Compatible**: Server already accepted these keys
- **No Interface Changes**: Web interface already used these keys
- **ESP32 Only**: Only ESP32 firmware needed updates (v11.70+)

---

## 🐛 Troubleshooting

### Quick Diagnostic (2 minutes)

#### Step 1: Check Last Received Data

```sql
SELECT 
    reading_time, 
    sensor,
    version,
    TempAir,
    TIMESTAMPDIFF(MINUTE, reading_time, NOW()) as minutes_ago
FROM ffp3Data 
ORDER BY reading_time DESC 
LIMIT 1;
```

**Expected**: `minutes_ago` should be < 5 minutes  
❌ If `minutes_ago` > 60 minutes → **ESP32 not publishing**

---

#### Step 2: Test Server Response

```bash
curl -X POST "https://iot.olution.info/ffp3/post-data" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "api_key=YOUR_API_KEY&sensor=TEST&version=DIAG&TempAir=22.5"
```

**Possible Results**:

| Response | Meaning | Action |
|----------|---------|--------|
| `200 Données enregistrées avec succès` | ✅ Server OK | ESP32 problem |
| `401 Clé API incorrecte` | ❌ Invalid API Key | Check .env |
| `405 Méthode non autorisée` | ❌ Routing problem | Check Slim |
| `500 Erreur serveur` | ❌ PHP/DB error | Check logs |
| Timeout / No response | ❌ Server down | Check Apache |

---

### Common Issues

#### Issue 1: Server OK, ESP32 Not Publishing

**Symptoms**:
- ✅ Curl works (200 OK)
- ❌ No recent data in database

**Possible Causes**:
1. ESP32 off / not powered
2. ESP32 disconnected from WiFi
3. Incorrect URL in ESP32 code
4. Incorrect API Key in ESP32 code
5. Network problem (firewall, DNS)

**Solutions**:
```cpp
// Check in ESP32 code:
const char* serverUrl = "https://iot.olution.info/ffp3/post-data";  // Correct
const char* apiKey = "YOUR_CORRECT_API_KEY";

// NOT:
// ❌ "http://..." (without HTTPS)
// ❌ ".../ffp3datas/public/post-data" (old path)
// ❌ ".../post-ffp3-data.php" (legacy)

// Set timeout:
http.setTimeout(10000);  // 10 seconds minimum

// Add debug logs:
Serial.println("URL: " + String(serverUrl));
Serial.println("WiFi Status: " + String(WiFi.status()));
Serial.println("HTTP Code: " + String(httpCode));
Serial.println("Response: " + http.getString());
```

---

#### Issue 2: 401 Unauthorized (Invalid API Key)

**Symptoms**:
- ❌ Curl returns `401 Clé API incorrecte`

**Causes**:
1. API_KEY in `.env` different from ESP32
2. Spaces or invisible characters in API Key
3. `.env` file not loaded

**Solutions**:
```bash
# 1. Check API Key in .env
cat .env | grep API_KEY

# Expected: API_KEY=YOUR_API_KEY

# 2. Test with extracted key
API_KEY=$(grep "^API_KEY=" .env | cut -d'=' -f2)
curl -X POST https://iot.olution.info/ffp3/post-data \
  -d "api_key=${API_KEY}&sensor=TEST&TempAir=20"

# 3. If still 401, regenerate key
# Update .env AND ESP32 code
```

---

#### Issue 3: 500 Internal Server Error

**Symptoms**:
- ❌ Curl returns `500 Erreur serveur`

**Causes**:
1. SQL error (missing table, missing column)
2. PHP error (fatal error, exception)
3. Database connection failed
4. Disk space full

**Solutions**:
```bash
# 1. Check error logs
tail -n 50 /path/to/ffp3/error_log

# 2. Check disk space
df -h

# 3. Check MySQL is running
systemctl status mysql

# 4. Test database connection
mysql -u USER -p -e "SELECT 1"

# 5. Check file permissions
ls -la /path/to/ffp3/public/
```

---

#### Issue 4: Timeout / Server Not Responding

**Symptoms**:
- ❌ Curl timeout after 30 seconds
- ❌ No server response

**Causes**:
1. Apache/Nginx stopped
2. Network problem (firewall, DNS)
3. SSL/TLS expired
4. Server overloaded

**Solutions**:
```bash
# 1. Check Apache is running
systemctl status httpd
# or
systemctl status apache2

# If stopped:
systemctl start httpd

# 2. Check open ports
netstat -tlnp | grep -E '80|443'

# 3. Test locally (on server)
curl -I http://localhost/ffp3/

# 4. Check Apache logs
tail -f /var/log/httpd/error_log

# 5. Check SSL certificate
openssl s_client -connect iot.olution.info:443
```

---

### Diagnostic Checklist

#### Server Checklist
- [ ] Apache/Nginx started
- [ ] MySQL started
- [ ] `.env` file present and correct
- [ ] `API_KEY` defined in `.env`
- [ ] `ffp3Data` table exists
- [ ] Disk space available (> 10%)
- [ ] File permissions OK (644/755)
- [ ] No recent errors in logs
- [ ] Curl test returns 200 OK
- [ ] Manual database insertion works

#### ESP32 Checklist
- [ ] ESP32 powered
- [ ] WiFi LED active
- [ ] WiFi connection established
- [ ] Correct URL in code
- [ ] Correct API Key in code
- [ ] HTTP timeout >= 10 seconds
- [ ] Serial logs show POST attempts
- [ ] No errors in serial logs

#### Network Checklist
- [ ] Server accessible from Internet
- [ ] DNS resolves `iot.olution.info`
- [ ] Port 443 (HTTPS) open
- [ ] SSL certificate valid
- [ ] No firewall blocking ESP32

---

### Emergency Actions

If ESP32 hasn't published for a long time and it's critical:

#### Option 1: Complete Restart

```bash
# 1. Restart ESP32
#    → Unplug/replug power
#    → Wait 10 seconds

# 2. Restart Apache
systemctl restart httpd

# 3. Restart MySQL (if necessary)
systemctl restart mysql

# 4. Verify everything is OK
curl -X POST https://iot.olution.info/ffp3/post-data \
  -d "api_key=YOUR_API_KEY&sensor=TEST&TempAir=20"
```

#### Option 2: Enable Debug Mode

```cpp
// Add to ESP32 code:
#define DEBUG_HTTP 1

void sendData() {
    #ifdef DEBUG_HTTP
    Serial.println("=== HTTP POST START ===");
    Serial.println("URL: " + String(serverUrl));
    Serial.println("WiFi Status: " + String(WiFi.status()));
    Serial.println("WiFi RSSI: " + String(WiFi.RSSI()));
    Serial.println("Free Heap: " + String(ESP.getFreeHeap()));
    #endif
    
    // ... HTTP code ...
    
    #ifdef DEBUG_HTTP
    Serial.println("HTTP Code: " + String(httpCode));
    Serial.println("Response: " + http.getString());
    Serial.println("=== HTTP POST END ===\n");
    #endif
}
```

---

## 🌍 Configuration (PROD vs TEST)

### Environments

The system has two separate environments with their own database tables:

- **PRODUCTION** (`ENV=prod`): Tables `ffp3Data`, `ffp3Outputs`, `ffp3Heartbeat`
- **TEST** (`ENV=test`): Tables `ffp3Data2`, `ffp3Outputs2`, `ffp3Heartbeat2`

### PRODUCTION Configuration

```cpp
// In ESP32 code:
const char* serverUrl = "https://iot.olution.info/ffp3/post-data";
const char* outputsUrl = "https://iot.olution.info/ffp3/api/outputs/state";
const char* heartbeatUrl = "https://iot.olution.info/ffp3/heartbeat";
```

### TEST Configuration

```cpp
// In ESP32 code:
const char* serverUrl = "https://iot.olution.info/ffp3/post-data-test";
const char* outputsUrl = "https://iot.olution.info/ffp3/api/outputs-test/state";
const char* heartbeatUrl = "https://iot.olution.info/ffp3/heartbeat-test";
```

### Recommended Polling

- **Sensor data**: Every 5-10 minutes
- **Heartbeat**: Every 5 minutes
- **Configuration**: At boot + every hour

---

## 📚 Additional Resources

- **Project README**: Main documentation and architecture
- **CHANGELOG**: Version history
- **ENVIRONNEMENT_TEST**: Detailed TEST/PROD guide
- **Server logs**: `/ffp3/cronlog.txt`

---

## 🔧 Developer Checklist

Before modifying ESP32 code, verify:

- [ ] Server URL correct: `https://iot.olution.info/ffp3/`
- [ ] API key correct
- [ ] HTTP timeout: minimum 5 seconds
- [ ] Retry on failure: 3 attempts
- [ ] All sensors initialized
- [ ] GPIO correctly configured (pinMode)
- [ ] Interval: 2-3 minutes (180000 ms)
- [ ] NTP configured (for HMAC timestamp)

---

**End of ESP32 Complete Guide**  
**Version**: 4.4.0  
**Last Update**: October 11, 2025  
**© 2025 olution | FFP3 Aquaponie IoT System**

