# DIAGNOSTIC HTTP 500 - ENVIRONNEMENT TEST
**Date**: 2025-10-15  
**Problème**: Erreur HTTP 500 sur `/post-data-test`  
**Impact**: Données ESP32 non synchronisées avec le serveur distant  

---

## 🔍 ANALYSE DU PROBLÈME

### Symptômes observés
```
[HTTP] → http://iot.olution.info/ffp3/post-data-test (460-505 bytes)
[HTTP] ← code 500, 14 bytes
[HTTP] === DÉBUT RÉPONSE ===
Erreur serveur
[HTTP] === FIN RÉPONSE ===
```

### Hypothèses principales
1. **Tables TEST manquantes** : `ffp3Data2`, `ffp3Outputs2` n'existent pas
2. **Configuration environnement** : Variable `ENV=test` non définie
3. **Structure SQL** : Désalignement entre code PHP et structure BDD
4. **OutputRepository** : Erreur lors de la mise à jour des GPIO

---

## 🛠️ OUTILS DE DIAGNOSTIC CRÉÉS

### 1. Script de vérification des tables
**Fichier**: `ffp3/tools/check_test_tables.php`

**Fonctionnalités**:
- Vérifie l'existence des tables TEST
- Compare les structures PROD vs TEST
- Teste une insertion simulée
- Affiche la configuration environnement

**Usage**:
```bash
cd ffp3/tools
php check_test_tables.php
```

### 2. Script de test curl
**Fichier**: `ffp3/tools/test_post_data.sh`

**Fonctionnalités**:
- Simule exactement les requêtes ESP32
- Teste requête minimale et complète
- Compare PROD vs TEST
- Vérifie les logs serveur

**Usage**:
```bash
cd ffp3/tools
./test_post_data.sh
```

---

## 📋 PLAN D'ACTION

### Phase 1: Diagnostic (EN COURS)
- [x] Créer script de vérification des tables
- [x] Créer script de test curl
- [x] Ajouter logs détaillés dans post-data.php
- [x] Créer script de vérification .env
- [x] Créer fichier .env d'exemple pour TEST
- [ ] Exécuter les scripts sur le serveur
- [ ] Analyser les résultats

### Phase 2: Correction
- [ ] Ajouter logs détaillés dans `post-data.php`
- [ ] Vérifier/corriger configuration `.env`
- [ ] Créer tables TEST si manquantes
- [ ] Tester avec curl

### Phase 3: Validation
- [ ] Vérifier HTTP 200 au lieu de 500
- [ ] Confirmer insertion dans `ffp3Data2`
- [ ] Confirmer mise à jour GPIO dans `ffp3Outputs2`
- [ ] Documenter la solution

---

## 🔧 MODIFICATIONS APPORTÉES

### Fichiers créés
1. `ffp3/tools/check_test_tables.php` - Diagnostic des tables
2. `ffp3/tools/test_post_data.sh` - Tests curl
3. `ffp3/tools/test_simple.php` - Test simple PHP
4. `ffp3/tools/check_env.php` - Vérification configuration .env
5. `ffp3/env.test.example` - Fichier .env d'exemple pour TEST
6. `DIAGNOSTIC_HTTP500_TEST_2025-10-15.md` - Ce rapport

### Fichiers modifiés
1. `ffp3/public/post-data.php` - Ajout logs détaillés pour diagnostic

### Instructions d'utilisation

#### 1. Vérifier la configuration
```bash
cd ffp3/tools
php check_env.php
```

#### 2. Diagnostiquer les tables
```bash
php check_test_tables.php
```

#### 3. Tester l'endpoint
```bash
php test_simple.php
# ou
./test_post_data.sh
```

#### 4. Analyser les logs
```bash
tail -f ../var/logs/post-data.log
```

### Prochaines étapes
1. **Exécuter le diagnostic** sur le serveur distant
2. **Analyser les résultats** pour identifier la cause exacte
3. **Appliquer les corrections** selon les erreurs trouvées
4. **Valider** que l'ESP32 reçoit HTTP 200

---

## 📊 STRUCTURE ATTENDUE

### Tables TEST requises
- `ffp3Data2` : Données capteurs TEST
- `ffp3Outputs2` : GPIO/relais TEST  
- `ffp3Heartbeat2` : Heartbeat ESP32 TEST

### Configuration .env requise
```env
ENV=test
API_KEY=fdGTMoptd5CD2ert3
DB_HOST=localhost
DB_NAME=oliviera_iot
DB_USER=...
DB_PASS=...
```

### Routes requises
- `/post-data-test` → Force `TableConfig::setEnvironment('test')`
- `/api/outputs-test/state` → GPIO TEST
- `/aquaponie-test` → Interface TEST

---

## 🎯 RÉSULTAT ATTENDU

Après correction :
- ✅ HTTP 200 au lieu de 500
- ✅ Données dans `ffp3Data2` (pas `ffp3Data`)
- ✅ GPIO dans `ffp3Outputs2` (pas `ffp3Outputs`)
- ✅ Logs clairs environnement TEST
- ✅ ESP32 reçoit "Données enregistrées avec succès"

---

**Status**: 🔄 **DIAGNOSTIC EN COURS**  
**Priorité**: 🔴 **HAUTE** - Bloque la synchronisation des données TEST
