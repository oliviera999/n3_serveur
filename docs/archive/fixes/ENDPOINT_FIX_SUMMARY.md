# Fix des Endpoints FFP3 - Résumé

## 🚨 Problème Critique Résolu

Les endpoints pour la récupération des états GPIO étaient **inversés** entre les modes PRODUCTION et TEST dans `include/project_config.h`, causant une contamination croisée des bases de données.

## ✅ Corrections Appliquées

### Avant (ERRONÉ)
```cpp
#if defined(PROFILE_TEST) || defined(PROFILE_DEV)
    // TEST utilisait l'endpoint PRODUCTION
    constexpr const char* OUTPUT_ENDPOINT = "/ffp3/ffp3datas/public/api/outputs/states/1";
#else
    // PRODUCTION utilisait l'endpoint TEST
    constexpr const char* OUTPUT_ENDPOINT = "/ffp3/ffp3datas/public/api/outputs-test/states/1";
#endif
```

### Après (CORRIGÉ)
```cpp
#if defined(PROFILE_TEST) || defined(PROFILE_DEV)
    // TEST utilise maintenant l'endpoint TEST (table ffp3Outputs2)
    constexpr const char* OUTPUT_ENDPOINT = "/ffp3/ffp3datas/public/api/outputs-test/states/1";
#else
    // PRODUCTION utilise maintenant l'endpoint PRODUCTION (table ffp3Outputs)
    constexpr const char* OUTPUT_ENDPOINT = "/ffp3/ffp3datas/public/api/outputs/states/1";
#endif
```

## 📊 Mappings Vérifiés

### POST Sensor Data (ESP32 → Serveur)

| Environnement | Endpoint ESP32 | Route Serveur | Table BDD | Status |
|---------------|----------------|---------------|-----------|--------|
| **PRODUCTION** | `/ffp3/ffp3datas/public/post-data` | `POST /post-data` | `ffp3Data` | ✅ OK |
| **TEST** | `/ffp3/ffp3datas/public/post-data-test` | `POST /post-data-test` | `ffp3Data2` | ✅ OK |

**Champs POST vérifiés :**
- api_key, sensor, version
- TempAir, Humidite, TempEau
- EauPotager, EauAquarium, EauReserve
- diffMaree, Luminosite
- etatPompeAqua, etatPompeTank, etatHeat, etatUV
- bouffeMatin, bouffeMidi, bouffeSoir, bouffePetits, bouffeGros
- aqThreshold, tankThreshold, chauffageThreshold
- mail, mailNotif, resetMode

✅ **Tous les champs correspondent entre l'ESP32 et le serveur.**

### GET Output States (Serveur → ESP32)

| Environnement | Endpoint ESP32 | Route Serveur | Table BDD | Status |
|---------------|----------------|---------------|-----------|--------|
| **PRODUCTION** | `/api/outputs/states/1` | `GET /api/outputs/states/{board}` | `ffp3Outputs` | ✅ CORRIGÉ |
| **TEST** | `/api/outputs-test/states/1` | `GET /api/outputs-test/states/{board}` | `ffp3Outputs2` | ✅ CORRIGÉ |

**Format de réponse JSON :**
```json
{"2":"1","3":"0","4":"1","5":"0","6":"1","7":"0","8":"1"}
```

## 🎯 Impact des Corrections

### Avant le Fix
- ❌ ESP32 en PRODUCTION lisait/écrivait dans les tables TEST (`ffp3Outputs2`)
- ❌ ESP32 en TEST lisait/écrivait dans les tables PRODUCTION (`ffp3Outputs`)
- ❌ Contamination croisée des données entre environnements

### Après le Fix
- ✅ ESP32 en PRODUCTION lit/écrit dans les tables PRODUCTION (`ffp3Outputs`)
- ✅ ESP32 en TEST lit/écrit dans les tables TEST (`ffp3Outputs2`)
- ✅ Isolation complète entre environnements

## 🔍 Audit Complet FFP3

### Architecture Serveur Moderne (Slim Framework)

Le serveur FFP3 utilise maintenant une architecture moderne avec :
- **Routeur Slim** : Gestion des routes RESTful
- **Controllers** : `PostDataController`, `OutputController`
- **Services** : Logique métier isolée
- **Repositories** : Accès aux données
- **Security** : Validation API_KEY + HMAC signatures optionnelles

### Routes API Disponibles

#### Données Capteurs
- `POST /post-data` (PROD) → `PostDataController::handle()`
- `POST /post-data-test` (TEST) → Idem avec table `ffp3Data2`
- `POST /post-ffp3-data.php` → Alias legacy pour compatibilité

#### Contrôle GPIO/Outputs
- `GET /api/outputs/states/{board}` (PROD)
- `GET /api/outputs-test/states/{board}` (TEST)
- `POST /api/outputs/{id}/state` → Mise à jour état
- `POST /api/outputs/{id}/toggle` → Bascule état
- `DELETE /api/outputs/{id}` → Suppression
- `GET /api/boards` → Info boards

### Compatibilité Legacy

Un proxy de compatibilité existe dans `ffp3/ffp3datas/public/esp32-compat.php` pour rediriger les anciennes URLs, mais l'ESP32 utilise directement les endpoints modernes (pas besoin de ce proxy).

## ✅ Tests Recommandés

1. **Compiler et flasher** en mode PRODUCTION
2. **Vérifier les logs** HTTP dans le moniteur série
3. **Confirmer** que les données arrivent dans `ffp3Outputs` (pas `ffp3Outputs2`)
4. **Répéter** avec mode TEST et vérifier l'isolation

## 📁 Fichiers Modifiés

- `include/project_config.h` (lignes 63 et 67)

## 📚 Documentation Associée

- `ffp3/ffp3datas/ESP32_MIGRATION.md` - Guide de migration des endpoints
- `ffp3/ffp3datas/ENVIRONNEMENT_TEST.md` - Configuration environnements
- `ffp3/RESUME_MIGRATION_COMPLETE.md` - Résumé architecture moderne

