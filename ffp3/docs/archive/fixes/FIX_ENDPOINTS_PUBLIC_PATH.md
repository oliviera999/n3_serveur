# Fix Endpoints - Suppression /public/ du Chemin (v10.93)

**Date:** 2025-10-08
**Version:** 10.92 → 10.93
**Type:** Bug Fix Critique

## 🚨 Problème Identifié

Lors du monitoring du wroom-test, tous les endpoints modernes Slim retournaient **HTTP 404** :

```
[HTTP] → http://iot.olution.info/ffp3/ffp3datas/public/post-data-test
[HTTP] ← code 404
[Web] GET remote state -> HTTP 404
```

**Impact:**
- ❌ Les données capteurs n'étaient PAS enregistrées en base de données
- ❌ Les commandes GPIO distantes n'étaient PAS reçues par l'ESP32
- ✅ Le heartbeat fonctionnait (ancien endpoint PHP legacy)

## 🔍 Analyse de la Cause

### Architecture Slim Framework

Le serveur utilise Slim Framework avec cette structure:
```
/ffp3/ffp3datas/
├── public/           ← DocumentRoot (point d'entrée web)
│   ├── index.php    ← Routeur Slim
│   └── .htaccess    ← Rewrite rules
├── src/             ← Code application (Controllers, Services, etc.)
└── vendor/          ← Dépendances Composer
```

### Détection Automatique du Base Path

Dans `ffp3/ffp3datas/public/index.php` (lignes 24-28):
```php
// Forcer le chemin base pour être identique à l'ancien (dossier parent de /public)
$basePath = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'])));
if ($basePath !== '/' && $basePath !== '') {
    $app->setBasePath($basePath);
}
```

Cette logique détecte automatiquement `/ffp3/ffp3datas` comme base path (en remontant d'un niveau depuis `/public/`).

### Routes Slim Définies

**TEST (lignes 100, 177):**
```php
$app->post('/post-data-test', ...);
$app->get('/api/outputs-test/states/{board}', ...);
```

**PROD (lignes 60, 130):**
```php
$app->post('/post-data', ...);
$app->get('/api/outputs/states/{board}', ...);
```

### URLs Complètes Résultantes

Avec base path `/ffp3/ffp3datas`, les URLs deviennent:
- ✅ `http://iot.olution.info/ffp3/ffp3datas/post-data-test`
- ✅ `http://iot.olution.info/ffp3/ffp3datas/api/outputs-test/states/1`

### Le Problème

L'ESP32 incluait `/public/` dans les endpoints:
- ❌ `http://iot.olution.info/ffp3/ffp3datas/public/post-data-test`
- ❌ `http://iot.olution.info/ffp3/ffp3datas/public/api/outputs-test/states/1`

Le dossier `/public/` est le **DocumentRoot** du serveur web, pas une partie de l'URL de l'API.

## ✅ Corrections Appliquées

### Fichier: `include/project_config.h`

#### Avant (v10.92)
```cpp
#if defined(PROFILE_TEST) || defined(PROFILE_DEV)
    constexpr const char* POST_DATA_ENDPOINT = "/ffp3/ffp3datas/public/post-data-test";
    constexpr const char* OUTPUT_ENDPOINT = "/ffp3/ffp3datas/public/api/outputs-test/states/1";
#else
    constexpr const char* POST_DATA_ENDPOINT = "/ffp3/ffp3datas/public/post-data";
    constexpr const char* OUTPUT_ENDPOINT = "/ffp3/ffp3datas/public/api/outputs/states/1";
#endif
```

#### Après (v10.93)
```cpp
#if defined(PROFILE_TEST) || defined(PROFILE_DEV)
    constexpr const char* POST_DATA_ENDPOINT = "/ffp3/ffp3datas/post-data-test";
    constexpr const char* OUTPUT_ENDPOINT = "/ffp3/ffp3datas/api/outputs-test/states/1";
#else
    constexpr const char* POST_DATA_ENDPOINT = "/ffp3/ffp3datas/post-data";
    constexpr const char* OUTPUT_ENDPOINT = "/ffp3/ffp3datas/api/outputs/states/1";
#endif
```

**Changements:**
- ✅ Suppression de `/public/` de tous les endpoints TEST
- ✅ Suppression de `/public/` de tous les endpoints PROD

## 📊 Mapping Complet des Endpoints

### TEST (PROFILE_TEST / PROFILE_DEV)

| Action | Méthode | URL ESP32 | Route Slim | Table BDD |
|--------|---------|-----------|------------|-----------|
| POST données | POST | `/ffp3/ffp3datas/post-data-test` | `/post-data-test` | `ffp3Data2` |
| GET états GPIO | GET | `/ffp3/ffp3datas/api/outputs-test/states/1` | `/api/outputs-test/states/{board}` | `ffp3Outputs2` |

### PRODUCTION (PROFILE_PROD)

| Action | Méthode | URL ESP32 | Route Slim | Table BDD |
|--------|---------|-----------|------------|-----------|
| POST données | POST | `/ffp3/ffp3datas/post-data` | `/post-data` | `ffp3Data` |
| GET états GPIO | GET | `/ffp3/ffp3datas/api/outputs/states/1` | `/api/outputs/states/{board}` | `ffp3Outputs` |

### Legacy (toujours fonctionnels)

| Action | URL | Status |
|--------|-----|--------|
| Heartbeat | `/ffp3/ffp3datas/heartbeat.php` | ✅ OK |
| POST data legacy | `/ffp3/ffp3datas/post-ffp3-data.php` | ✅ Alias vers Slim |
| GET outputs legacy | `/ffp3/ffp3control/ffp3-outputs-action.php` | ⚠️ À vérifier |

## 🎯 Résultats Attendus

Après flash de la version 10.93:

### ✅ POST Données Capteurs
```
[HTTP] → http://iot.olution.info/ffp3/ffp3datas/post-data-test
[HTTP] ← code 200, "Données enregistrées avec succès"
```

### ✅ GET États GPIO
```
[HTTP] → GET remote state
[Web] GET remote state -> HTTP 200
[HTTP] response: {"16":"1","18":"0","13":"1","15":"0"}
```

### ✅ Données Enregistrées
- Les mesures capteurs apparaissent dans la table `ffp3Data2` (TEST)
- Les commandes GPIO sont lues/écrites dans `ffp3Outputs2` (TEST)
- Pas de contamination croisée entre TEST et PROD

## 📋 Configuration Serveur Vérifiée

### .htaccess
**Fichier:** `ffp3/ffp3datas/public/.htaccess`
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /ffp3/ffp3datas/public/
    
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . index.php [L]
</IfModule>
```
✅ Correctement configuré

### Routing Slim
**Fichier:** `ffp3/ffp3datas/public/index.php`
- ✅ Routes TEST définies (lignes 100-116, 177-210)
- ✅ Routes PROD définies (lignes 60-77, 130-163)
- ✅ Base path auto-détecté correctement
- ✅ Middleware erreur activé

## 🔄 Historique des Corrections

| Version | Date | Correction |
|---------|------|------------|
| 10.91 | 2025-10-08 | Version initiale avec endpoints inversés PROD/TEST |
| 10.92 | 2025-10-08 | Fix inversion endpoints PROD/TEST (mais avec /public/) |
| 10.93 | 2025-10-08 | **Fix suppression /public/ des endpoints** |

## 📝 Notes Techniques

### Pourquoi /public/ dans la Structure mais pas dans l'URL?

C'est une **bonne pratique** de sécurité et d'architecture:

1. **DocumentRoot = `/public/`**
   - Seul le dossier `public/` est exposé au web
   - Les dossiers `src/`, `vendor/`, `.env` sont inaccessibles depuis internet

2. **URL Rewriting**
   - Apache/Nginx pointe vers `/ffp3/ffp3datas/public/`
   - Le `.htaccess` redirige tout vers `index.php`
   - Slim gère le routing sans exposer la structure interne

3. **URLs Propres**
   - L'utilisateur voit: `http://example.com/api/users`
   - Pas: `http://example.com/public/index.php?route=/api/users`

### Base Path Auto-détection

```php
dirname(dirname($_SERVER['SCRIPT_NAME']))
```

Avec `SCRIPT_NAME = /ffp3/ffp3datas/public/index.php`:
1. `dirname()` → `/ffp3/ffp3datas/public`
2. `dirname()` → `/ffp3/ffp3datas`

Le base path est donc `/ffp3/ffp3datas`, et toutes les routes Slim sont relatives à ce chemin.

## 🚀 Déploiement

### ESP32
1. Incrémenter version → 10.93 ✅
2. Modifier endpoints (supprimer /public/) ✅
3. Compiler et flasher wroom-test
4. Vérifier logs HTTP 200 OK

### Serveur
1. Vérifier que `ffp3/` est synchronisé avec `iot.olution.info`
2. Vérifier `.htaccess` présent dans `/public/`
3. Vérifier mod_rewrite activé
4. Tester manuellement les endpoints avec curl

## 🧪 Tests de Validation

```bash
# Test POST données TEST
curl -X POST "http://iot.olution.info/ffp3/ffp3datas/post-data-test" \
  -d "api_key=fdGTMoptd5CD2ert3&sensor=test&version=10.93&TempEau=25.0"

# Test GET états GPIO TEST
curl "http://iot.olution.info/ffp3/ffp3datas/api/outputs-test/states/1"

# Test POST données PROD
curl -X POST "http://iot.olution.info/ffp3/ffp3datas/post-data" \
  -d "api_key=fdGTMoptd5CD2ert3&sensor=prod&version=10.93&TempEau=25.0"

# Test GET états GPIO PROD
curl "http://iot.olution.info/ffp3/ffp3datas/api/outputs/states/1"
```

Tous devraient retourner **200 OK** (pas 404).

