# 📡 État Synchronisation Serveur - Endpoints POST Data

**Date**: 14 Octobre 2025  
**Vérification**: Fichiers locaux vs Serveur distant  

---

## ❌ **NON, les endpoints ne sont PAS à jour sur le serveur**

---

## 📊 État Détaillé

### 1️⃣ `post-data.php` (Production - Moderne)

**Localisation** : `ffp3/public/post-data.php`

| Aspect | État |
|--------|------|
| **Fichier local** | ✅ Modifié avec GPIO 100 (email) |
| **Git staged** | ❌ Non committé |
| **Git pushed** | ❌ Non pushé sur origin/main |
| **Serveur distant** | ❌ Version ANCIENNE (sans GPIO 100) |

**Modifications locales non déployées** :
```diff
- 100 => null,  // Mail (texte, géré séparément)
+ 100 => $data->mail,  // Mail (texte - stocké dans state comme varchar)

- if ($state !== null) {
-     $outputRepo->updateState($gpio, (int)$state);
+ if ($state !== null && $state !== '') {
+     if ($gpio === 100) {
+         $outputRepo->updateState($gpio, $state); // Texte pour email
+     } else {
+         $outputRepo->updateState($gpio, (int)$state); // Entier pour autres
+     }
```

**Impact** :
- ❌ GPIO 100 (email) non mis à jour sur serveur
- ✅ Autres GPIO (2, 15, 16, 18, 101-116) fonctionnent

---

### 2️⃣ `post-data-test.php` (Test - Legacy)

**Localisation serveur** : `/path/to/ffp3/post-data-test.php`  
**Fichier corrigé local** : `ffp3/post-data-test-CORRECTED.php`

| Aspect | État |
|--------|------|
| **Fichier sur serveur** | ❌ Version ANCIENNE (avec colonnes invalides) |
| **Fichier corrigé local** | ✅ Créé (post-data-test-CORRECTED.php) |
| **Git versionné** | ❌ Non (fichier legacy non dans repo) |
| **Déployé** | ❌ À copier manuellement sur serveur |

**Problème actuel sur serveur** :
```php
// Version serveur (ANCIENNE) :
INSERT INTO ffp3Data2 (
    api_key,     ← ❌ Colonne inexistante → HTTP 500
    ...,
    tempsGros,   ← ❌ Colonne inexistante → HTTP 500
    tempsPetits  ← ❌ Colonne inexistante → HTTP 500
)
```

**Impact** :
- ❌ HTTP 500 à chaque POST de l'ESP32 vers endpoint test
- ❌ Chauffage s'éteint (données non sauvegardées dans outputs)
- ❌ Queue ESP32 se remplit (14 payloads bloqués)

---

## 🎯 Actions Requises

### Étape 1 : Déployer `post-data.php` (Production)

```bash
# Local
cd "C:\Users\olivi\Mon Drive\travail\##olution\##Projets\##prototypage\platformIO\Projects\ffp5cs\ffp3"
git add public/post-data.php
git commit -m "v11.36: Fix GPIO 100 (email) - UPDATE complet dans outputs"
git push origin main

# Sur serveur
ssh user@iot.olution.info
cd /path/to/ffp3
git pull origin main
```

**Résultat attendu** :
- ✅ GPIO 100 (email) mis à jour
- ✅ 21 GPIO synchronisés (au lieu de 20)

---

### Étape 2 : Déployer `post-data-test.php` (Test)

**Option A - Copie Manuelle** :
```bash
# Sur serveur via SSH
ssh user@iot.olution.info
cd /path/to/ffp3

# Backup ancien fichier
cp post-data-test.php post-data-test.php.backup-$(date +%Y%m%d)

# Éditer le fichier et coller le contenu de post-data-test-CORRECTED.php
nano post-data-test.php
# (Coller le contenu, Ctrl+X, Y, Enter)
```

**Option B - SCP** :
```powershell
# Depuis Windows local
scp "C:\Users\olivi\Mon Drive\travail\##olution\##Projets\##prototypage\platformIO\Projects\ffp5cs\ffp3\post-data-test-CORRECTED.php" user@iot.olution.info:/path/to/ffp3/post-data-test.php
```

**Option C - Versionner dans Git** :
```bash
# Local
cd "C:\Users\olivi\Mon Drive\travail\##olution\##Projets\##prototypage\platformIO\Projects\ffp5cs\ffp3"
mv post-data-test-CORRECTED.php post-data-test.php
git add post-data-test.php
git commit -m "v11.36: Fix post-data-test.php - Colonnes compatibles ffp3Data2"
git push origin main

# Sur serveur
cd /path/to/ffp3
git pull origin main
```

**Résultat attendu** :
- ✅ HTTP 200 (fini le 500)
- ✅ INSERT dans ffp3Data2 fonctionne
- ✅ 21 GPIO mis à jour dans ffp3Outputs2
- ✅ Chauffage reste allumé

---

## 📋 Checklist Déploiement

- [ ] **Commit local** : `git add public/post-data.php`
- [ ] **Commit local** : `git commit -m "v11.36: Fix GPIO 100 email"`
- [ ] **Push vers serveur** : `git push origin main`
- [ ] **Pull sur serveur** : `cd /path/to/ffp3 && git pull`
- [ ] **Backup post-data-test.php** : `cp post-data-test.php post-data-test.php.backup`
- [ ] **Déployer post-data-test.php** : Copier contenu de CORRECTED
- [ ] **Test endpoint production** : `curl http://iot.olution.info/ffp3/post-data`
- [ ] **Test endpoint test** : `curl http://iot.olution.info/ffp3/post-data-test`
- [ ] **Monitor ESP32** : 90 secondes de logs
- [ ] **Vérifier queue vide** : Plus d'erreurs HTTP 500
- [ ] **Vérifier chauffage** : Reste allumé quand activé

---

## 🚨 Urgence

**Priorité HAUTE** : `post-data-test.php`  
**Raison** : ESP32 utilise endpoint TEST → HTTP 500 → Queue bloquée

**Priorité MOYENNE** : `post-data.php`  
**Raison** : Endpoint production fonctionne (20 GPIO sur 21), seul email manque

---

## 📊 Résumé

| Fichier | Local | Git Staged | Git Pushed | Serveur | Action |
|---------|-------|------------|------------|---------|--------|
| `post-data.php` | ✅ Modifié | ❌ | ❌ | ❌ Ancien | Commit + Push |
| `post-data-test.php` | ✅ Corrigé | ❌ | ❌ | ❌ Ancien | Copie manuelle |

**Statut global** : ❌ **Aucun fichier à jour sur serveur**

---

## 🎯 Commandes Rapides

```powershell
# 1. Commit + Push post-data.php
cd "C:\Users\olivi\Mon Drive\travail\##olution\##Projets\##prototypage\platformIO\Projects\ffp5cs\ffp3"
git add public/post-data.php
git commit -m "v11.36: Fix GPIO 100 (email) - UPDATE complet outputs"
git push origin main

# 2. Versionner post-data-test.php (recommandé)
mv post-data-test-CORRECTED.php post-data-test.php
git add post-data-test.php
git commit -m "v11.36: Fix post-data-test - Colonnes compatibles ffp3Data2"
git push origin main
```

Puis sur serveur :
```bash
cd /path/to/ffp3
git pull origin main
```

Veux-tu que j'exécute ces commandes maintenant ? 🚀

