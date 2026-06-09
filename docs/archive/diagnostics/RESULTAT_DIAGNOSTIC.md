# 🔍 Résultat du Diagnostic ESP32

**Date**: 11 octobre 2025, 16:30  
**Test effectué**: Option 1 - Test curl depuis le PC

---

## 📊 Tests Effectués

| # | Endpoint Testé | Méthode | Code HTTP | Réponse | Statut |
|---|----------------|---------|-----------|---------|--------|
| 1 | `/ffp3/public/post-data` | POST | 200 | Page HTML (accueil) | ❌ Routing Slim cassé |
| 2 | `/ffp3/ffp3datas/public/post-data` | POST | 404 | "Not found" (Slim) | ❌ Route inexistante |
| 3 | `/ffp3/post-ffp3-data.php` | POST | 500 | "Erreur serveur" | ⚠️ Erreur PHP |
| 4 | `/ffp3/post-ffp3-data2.php` | POST | 500 | "Erreur serveur" | ⚠️ Erreur PHP |
| 5 | **`/ffp3/public/post-data.php`** | POST | **500** | **"Configuration serveur manquante"** | ⚠️ **PROBLÈME IDENTIFIÉ** |

---

## 🎯 PROBLÈME IDENTIFIÉ

### **Le fichier `.env` n'est pas chargé ou est manquant sur le serveur**

**Preuve** :
- Le fichier `public/post-data.php` s'exécute correctement
- Mais il retourne "Configuration serveur manquante"
- Ce message apparaît quand `$_ENV['API_KEY']` est `null`

**Extrait du code (public/post-data.php, ligne 79-91)**:
```php
$apiKeyConfig = $_ENV['API_KEY'] ?? null;
if ($apiKeyConfig === null) {
    $logger->error('La variable API_KEY est absente du .env');
    http_response_code(500);
    echo 'Configuration serveur manquante';
    exit;
}
```

---

## 🔍 Analyse Technique

### Routing Slim
- **Statut**: ❌ Non fonctionnel
- **Symptôme**: Les routes POST `/post-data` retournent la page d'accueil HTML
- **Cause probable**: `.htaccess` manquant ou mal configuré dans le répertoire `/ffp3/`
- **Impact**: Les endpoints modernes Slim ne fonctionnent pas

### Fichiers Legacy PHP
- **Statut**: ⚠️ Accessibles mais erreur d'exécution
- **Fichiers testés**:
  - `post-ffp3-data.php` → 500 "Erreur serveur"
  - `post-ffp3-data2.php` → 500 "Erreur serveur"
  - `public/post-data.php` → 500 "Configuration serveur manquante"
- **Cause**: Variables d'environnement non chargées

### Fichier .env
- **Statut**: 🔴 **CRITIQUE** - Non chargé
- **Conséquence**: Toutes les variables `$_ENV` sont `null`
- **Variables affectées**:
  - `API_KEY` (authentification)
  - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (connexion BDD)
  - `ENV` (environnement prod/test)
  - Toutes les autres variables de configuration

---

## 🚨 Impact

### ESP32 ne peut pas publier
**Pourquoi ?**
- L'ESP32 envoie probablement vers un de ces endpoints :
  - `https://iot.olution.info/ffp3/public/post-data` (Slim) → Retourne page HTML ❌
  - `https://iot.olution.info/ffp3/post-ffp3-data.php` → 500 Erreur ❌
  - `https://iot.olution.info/ffp3/public/post-data.php` → 500 Config manquante ❌

Tous les endpoints retournent une erreur, donc **l'ESP32 ne peut pas insérer de données**.

---

## 🔧 SOLUTION

### Étape 1: Se connecter au serveur

```bash
ssh user@iot.olution.info
```

---

### Étape 2: Vérifier le fichier .env

```bash
cd /home4/oliviera/iot.olution.info/ffp3
ls -la .env
```

**Si le fichier existe**:
```bash
cat .env | head -n 20
```

Vérifier qu'il contient bien :
```env
API_KEY=<votre_cle_api>
DB_HOST=localhost
DB_NAME=<votre_base>
DB_USER=<votre_user>
DB_PASS="<votre_mot_de_passe>"
ENV=prod
APP_TIMEZONE=Europe/Paris
```

**Si le fichier n'existe PAS**:
```bash
cp env.dist .env
```

---

### Étape 3: Corriger les permissions

```bash
cd /home4/oliviera/iot.olution.info/ffp3
chmod 644 .env
chown $(whoami):$(whoami) .env
```

---

### Étape 4: Vérifier le .htaccess (pour Slim)

```bash
cd /home4/oliviera/iot.olution.info/ffp3
cat .htaccess
```

**Contenu attendu**:
```apache
# Compatibilité ESP32
RewriteEngine On
RewriteRule ^ffp3datas/api/(.*)$ api/$1 [L]

# Router Slim
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ public/index.php [L]
```

**Si le fichier n'existe pas ou est incorrect**, le créer/corriger.

---

### Étape 5: Redémarrer Apache (si nécessaire)

```bash
sudo systemctl restart httpd
# ou
sudo systemctl restart apache2
```

---

### Étape 6: Re-tester depuis votre PC

```bash
curl -X POST "https://iot.olution.info/ffp3/public/post-data.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "api_key=fdGTMoptd5CD2ert3&sensor=TEST-CURL&TempAir=22.5"
```

**Résultat attendu**:
```
Données enregistrées avec succès
```

**Code HTTP attendu**: `200`

---

### Étape 7: Vérifier dans la BDD

```sql
mysql -u oliviera_iot -p

USE oliviera_iot;
SELECT * FROM ffp3Data 
WHERE sensor = 'TEST-CURL' 
ORDER BY reading_time DESC 
LIMIT 1;

-- Nettoyer le test
DELETE FROM ffp3Data WHERE sensor = 'TEST-CURL';
```

---

### Étape 8: L'ESP32 devrait recommencer à publier

Une fois que le curl fonctionne (retourne 200 + "Données enregistrées avec succès"), l'ESP32 devrait automatiquement recommencer à publier à son prochain cycle (2-3 minutes).

**Vérifier après 5 minutes**:
```sql
SELECT 
    reading_time,
    sensor,
    TIMESTAMPDIFF(MINUTE, reading_time, NOW()) as minutes_ago
FROM ffp3Data 
ORDER BY reading_time DESC 
LIMIT 1;
```

Si `minutes_ago` < 5 → ✅ **PROBLÈME RÉSOLU !**

---

## 🔍 Diagnostic Complémentaire (si le problème persiste)

Si après avoir corrigé le `.env`, le problème persiste :

### 1. Exécuter le diagnostic automatique

```bash
cd /home4/oliviera/iot.olution.info/ffp3
bash tools/quick_diagnostic.sh
```

### 2. Vérifier les logs d'erreurs

```bash
tail -n 100 error_log
tail -n 100 public/error_log
```

### 3. Tester la connexion BDD

```bash
php -r "
require 'vendor/autoload.php';
\App\Config\Env::load();
try {
    \$pdo = \App\Config\Database::getConnection();
    echo 'Connexion BDD OK\n';
} catch (Exception \$e) {
    echo 'Erreur BDD: ' . \$e->getMessage() . '\n';
}
"
```

---

## 📝 Checklist de Vérification

Après correction, vérifier que :

- [ ] Fichier `.env` existe à la racine `/home4/oliviera/iot.olution.info/ffp3/`
- [ ] Fichier `.env` contient `API_KEY=fdGTMoptd5CD2ert3`
- [ ] Fichier `.env` contient les variables `DB_*`
- [ ] Permissions `.env` = `644`
- [ ] Fichier `.htaccess` existe et est correct
- [ ] Apache redémarré (si .htaccess modifié)
- [ ] Test curl retourne `200` + "Données enregistrées"
- [ ] Données de test insérées dans la BDD
- [ ] ESP32 publie à nouveau (< 5 min)

---

## 🎯 Résumé Exécutif

| Élément | Statut | Action Requise |
|---------|--------|----------------|
| **Serveur web** | ✅ Accessible | Aucune |
| **Fichiers PHP** | ✅ Présents | Aucune |
| **Routing Slim** | ❌ Cassé | Vérifier .htaccess |
| **Fichier .env** | 🔴 **Non chargé** | **Créer/corriger** |
| **Base de données** | ⚠️ Non testé | Tester après .env |
| **ESP32** | ⏸️ En attente | Attendra que serveur fonctionne |

---

## 🚀 Prochaine Étape

**ACTION IMMÉDIATE** :

1. Se connecter au serveur
2. Aller dans `/home4/oliviera/iot.olution.info/ffp3/`
3. Vérifier/créer le fichier `.env`
4. Re-tester avec curl

**Temps estimé** : 5-10 minutes

---

## 📚 Fichiers de Diagnostic Disponibles

- **Ce fichier** : `RESULTAT_DIAGNOSTIC.md` - Résultat du test
- **Guide complet** : `DIAGNOSTIC_ESP32_TROUBLESHOOTING.md` - Solutions détaillées
- **Commandes rapides** : `QUICK_FIX_COMMANDS.md` - Aide-mémoire
- **Script automatique** : `tools/quick_diagnostic.sh` - Diagnostic auto
- **Script PHP** : `tools/diagnostic_esp32.php` - Diagnostic complet

---

**Diagnostic effectué le** : 11 octobre 2025, 16:30  
**Prochaine action** : Corriger le fichier `.env` sur le serveur

---

🔴 **PRIORITÉ HAUTE** : Le fichier `.env` doit être corrigé en priorité pour que le système fonctionne.

