# 📝 Résumé - Diagnostic ESP32 Non Fonctionnel

**Date**: 11 octobre 2025  
**Problème**: L'ESP32 n'arrive plus à publier les données sur la BDD depuis plus d'une heure

---

## 🎯 Ce qui a été créé

J'ai créé **4 outils de diagnostic** pour vous aider à identifier et résoudre le problème:

### 1. **Script PHP Complet** (`tools/diagnostic_esp32.php`)
Script PHP exhaustif qui vérifie:
- ✅ Configuration serveur (.env)
- ✅ Connexion base de données
- ✅ Dernières données reçues
- ✅ Endpoints POST-DATA
- ✅ Logs récents
- ✅ Espace disque et permissions
- ✅ Simulation d'une requête ESP32

**Usage**:
```bash
cd /home4/oliviera/iot.olution.info/ffp3/ffp3datas
php tools/diagnostic_esp32.php
```

---

### 2. **Script Shell Rapide** (`tools/quick_diagnostic.sh`)
Script shell léger pour un diagnostic rapide (30 secondes):
- 🔍 Test de l'endpoint POST
- 🔍 Vérification dernières données BDD
- 🔍 Analyse des logs
- 🔍 Vérification espace disque
- 🔍 Vérification fichiers critiques

**Usage**:
```bash
cd /home4/oliviera/iot.olution.info/ffp3/ffp3datas
bash tools/quick_diagnostic.sh
```

---

### 3. **Guide de Dépannage Complet** (`DIAGNOSTIC_ESP32_TROUBLESHOOTING.md`)
Documentation exhaustive (60 pages) avec:
- 📋 Diagnostic rapide en 2 minutes
- 🖥️ Vérifications serveur détaillées
- 🔌 Vérifications ESP32
- 🧪 Tests manuels
- 🔧 Solutions par scénario
- 📊 Checklist complète
- 🚨 Actions d'urgence

---

### 4. **Aide-Mémoire Commandes** (`QUICK_FIX_COMMANDS.md`)
Liste ultra-rapide des commandes essentielles:
- ⚡ Diagnostic en 30 secondes
- 📊 Commandes SQL utiles
- 🔧 Tests serveur
- 📜 Vérification logs
- 🔄 Redémarrages d'urgence
- 🐛 Erreurs courantes et solutions

---

## 🚀 Comment Démarrer

### Étape 1: Diagnostic Rapide (30 secondes)

**Option A - Depuis votre PC**:
```bash
curl -X POST "https://iot.olution.info/ffp3/public/post-data" \
  -d "api_key=fdGTMoptd5CD2ert3&sensor=TEST&TempAir=22.5"
```

**Résultat attendu**: `Données enregistrées avec succès`

- ✅ Si ça fonctionne → Le problème vient de l'ESP32
- ❌ Si ça ne fonctionne pas → Le problème vient du serveur

---

**Option B - Sur le serveur**:
```bash
# Se connecter
ssh user@iot.olution.info

# Aller dans le projet
cd /home4/oliviera/iot.olution.info/ffp3/ffp3datas

# Lancer le diagnostic rapide
bash tools/quick_diagnostic.sh
```

---

### Étape 2: Vérifier les dernières données

```sql
-- Se connecter à MySQL
mysql -u oliviera_iot -p

-- Vérifier
USE oliviera_iot;
SELECT 
    reading_time,
    sensor,
    TIMESTAMPDIFF(MINUTE, reading_time, NOW()) as minutes_ago
FROM ffp3Data 
ORDER BY reading_time DESC 
LIMIT 1;
```

**Interprétation**:
- `minutes_ago` < 5 → ✅ ESP32 fonctionne normalement
- `minutes_ago` entre 5 et 15 → ⚠️ Léger retard (tolérable)
- `minutes_ago` > 60 → ❌ ESP32 ne publie plus

---

### Étape 3: Identifier le problème

Suivant le résultat des étapes 1 et 2:

| Curl | Dernières données | Diagnostic | Solution |
|------|------------------|-----------|----------|
| ✅ 200 | < 5 min | ✅ Tout fonctionne | Problème résolu |
| ✅ 200 | > 60 min | ❌ Problème ESP32 | Voir [Section ESP32](#solutions-esp32) |
| ❌ 401 | — | ❌ API Key invalide | Voir [Section API Key](#solution-api-key) |
| ❌ 500 | — | ❌ Erreur serveur | Voir [Section Serveur](#solutions-serveur) |
| ⏱️ Timeout | — | ❌ Serveur down | Voir [Section Urgence](#actions-urgence) |

---

## 🔧 Solutions Rapides

### Solutions ESP32

**Symptôme**: Serveur fonctionne (curl OK) mais pas de données récentes

**Actions**:

1. **Vérifier alimentation**
   ```
   ✓ LED bleue clignotante = WiFi connecté
   ✗ Pas de LED = Pas alimenté → Brancher l'ESP32
   ```

2. **Vérifier logs série** (USB + Arduino IDE / PlatformIO, 115200 baud)
   ```
   Chercher:
   [WiFi] Connected ✓
   [HTTP] POST... ✓
   [HTTP] Response: 200 ✓
   ```

3. **Vérifier le code ESP32**
   ```cpp
   // DOIT ÊTRE:
   const char* serverUrl = "https://iot.olution.info/ffp3/public/post-data";
   const char* apiKey = "fdGTMoptd5CD2ert3";
   
   // PAS:
   // ❌ "http://..." (sans HTTPS)
   // ❌ ".../ffp3datas/..." (ancien chemin)
   ```

4. **Redémarrer l'ESP32**
   ```
   Débrancher → Attendre 10 sec → Rebrancher
   ```

5. **Re-flasher le firmware**
   ```
   Via PlatformIO ou Arduino IDE
   Vérifier URL et API Key avant de flasher
   ```

---

### Solution API Key

**Symptôme**: `401 Clé API incorrecte`

```bash
# 1. Vérifier l'API Key serveur
cd /home4/oliviera/iot.olution.info/ffp3/ffp3datas
grep "^API_KEY=" .env

# 2. Copier la clé affichée

# 3. Mettre à jour le code ESP32
const char* apiKey = "LA_CLE_COPIEE";

# 4. Re-flasher l'ESP32
```

---

### Solutions Serveur

**Symptôme**: `500 Erreur serveur` ou timeout

```bash
# 1. Voir les logs
cd /home4/oliviera/iot.olution.info/ffp3/ffp3datas
tail -n 100 error_log

# 2. Vérifier MySQL
systemctl status mysql
# Si arrêté:
sudo systemctl restart mysql

# 3. Vérifier Apache
systemctl status httpd
# Si arrêté:
sudo systemctl restart httpd

# 4. Vérifier espace disque
df -h
# Si > 95% utilisé → Nettoyer

# 5. Tester connexion BDD
mysql -u oliviera_iot -p -e "SELECT 1"
```

---

### Actions Urgence

Si rien ne fonctionne:

```bash
# 1. Redémarrer tout
sudo systemctl restart httpd
sudo systemctl restart mysql

# 2. Redémarrer l'ESP32
# (Physiquement débrancher/rebrancher)

# 3. Attendre 30 secondes

# 4. Re-tester
curl -X POST https://iot.olution.info/ffp3/public/post-data \
  -d "api_key=fdGTMoptd5CD2ert3&sensor=TEST&TempAir=22.5"
```

---

## 📊 Tableau de Bord Diagnostic

Après avoir exécuté le diagnostic:

| Check | Commande | OK si... |
|-------|----------|----------|
| 🌐 Serveur accessible | `curl -I https://iot.olution.info/ffp3/` | HTTP 200/301 |
| 📡 POST fonctionne | `curl -X POST .../post-data -d "..."` | "Données enregistrées" |
| 🗄️ BDD accessible | `mysql -u ... -e "SELECT 1"` | Pas d'erreur |
| 📊 Données récentes | `SELECT ... minutes_ago ...` | < 5 minutes |
| 💾 Espace disque | `df -h` | < 90% |
| 🔑 API Key OK | `grep API_KEY .env` | Existe |
| 📁 Fichiers OK | `ls public/post-data.php` | Existe |

---

## 🎓 Ressources

| Document | Usage | Quand l'utiliser |
|----------|-------|------------------|
| `QUICK_FIX_COMMANDS.md` | Commandes rapides | Premier diagnostic |
| `tools/quick_diagnostic.sh` | Script rapide | Diagnostic automatique |
| `tools/diagnostic_esp32.php` | Diagnostic complet | Problème complexe |
| `DIAGNOSTIC_ESP32_TROUBLESHOOTING.md` | Guide détaillé | Tout comprendre |
| `ESP32_API_REFERENCE.md` | Référence API | Config ESP32 |

---

## ✅ Checklist Finale

Avant de conclure que le problème est résolu:

- [ ] `curl POST` retourne `200 OK`
- [ ] Dernières données BDD < 5 minutes
- [ ] Logs serveur sans erreurs récentes
- [ ] ESP32 alimenté et LED WiFi active
- [ ] URL ESP32 = `https://iot.olution.info/ffp3/public/post-data`
- [ ] API Key ESP32 = celle dans `.env`
- [ ] Logs série ESP32 montrent POST réussis
- [ ] Données s'affichent sur le dashboard

---

## 📞 Support

Si le problème persiste après avoir suivi toutes les étapes:

1. **Exécuter le diagnostic complet**:
   ```bash
   php tools/diagnostic_esp32.php > /tmp/diagnostic.txt
   ```

2. **Collecter les logs**:
   ```bash
   tail -n 200 error_log > /tmp/errors.txt
   tail -n 100 cronlog.txt > /tmp/cron.txt
   ```

3. **Partager les fichiers** avec votre administrateur système

---

## 🎯 Prochaines Étapes

1. **Maintenant**: Exécuter le diagnostic rapide
   ```bash
   bash tools/quick_diagnostic.sh
   ```

2. **Si problème serveur**: Consulter `DIAGNOSTIC_ESP32_TROUBLESHOOTING.md` section "Serveur"

3. **Si problème ESP32**: Consulter `DIAGNOSTIC_ESP32_TROUBLESHOOTING.md` section "ESP32"

4. **Si problème persistant**: Exécuter le diagnostic complet
   ```bash
   php tools/diagnostic_esp32.php
   ```

---

**Bon diagnostic ! 🔍**

Les outils créés devraient vous permettre d'identifier et de résoudre le problème rapidement.

---

**Version**: 1.0  
**Date**: 11 octobre 2025  
**Auteur**: Assistant IA  
**Projet**: FFP3 Aquaponie IoT

