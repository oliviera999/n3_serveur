# 🧪 Guide de Test - Synchronisation Temps Réel Interface de Contrôle

**Version** : 4.2.0  
**Date** : 11 octobre 2025  
**Fonctionnalité** : Synchronisation temps réel de l'interface `/control`

---

## 📋 Checklist de Test

### ✅ Test 1 : Vérification du Badge LIVE

1. **Accéder à l'interface de contrôle** :
   - PROD : https://iot.olution.info/ffp3/ffp3datas/control
   - TEST : https://iot.olution.info/ffp3/ffp3datas/control-test

2. **Vérifier le badge en haut à droite** :
   - [ ] Le badge affiche "CONNEXION..." (orange) au chargement
   - [ ] Après ~1 seconde, le badge passe à "SYNC" (vert)
   - [ ] Le badge est bien positionné (coin haut-droit)
   - [ ] Le badge est responsive sur mobile (plus petit)

### ✅ Test 2 : Vérification des Logs Console

1. **Ouvrir la console DevTools** (F12 → Console)

2. **Vérifier les logs suivants** :
   ```
   [Control] Initializing real-time sync...
   [ControlSync] ControlSync initialized
   [ControlSync] Starting control sync...
   [ControlSync] Initialized X switches from DOM
   [Control] Real-time sync started (polling every 10s)
   [ControlSync] Updated switch GPIO X to true/false
   ```

3. **Attendre 10 secondes** :
   - [ ] Une requête GET vers `/api/outputs/state` doit apparaître dans l'onglet Network
   - [ ] La requête retourne un objet JSON `{"gpio": state, ...}`
   - [ ] Aucune erreur dans la console

### ✅ Test 3 : Synchronisation Multi-Utilisateur

**Scénario** : Deux utilisateurs modifient les GPIO en même temps

1. **Ouvrir deux onglets** ou **deux navigateurs différents** sur la page `/control`

2. **Dans l'onglet 1** :
   - Activer un switch (ex: Pompe aquarium)
   - Vérifier que l'état change immédiatement

3. **Dans l'onglet 2** (attendre max 10 secondes) :
   - [ ] Le switch se met à jour automatiquement (sans recharger)
   - [ ] Le div du switch affiche une animation flash jaune
   - [ ] Une notification toast apparaît : "Changement détecté: GPIO X"
   - [ ] Le badge reste "SYNC" (vert)

4. **Répéter l'opération dans l'autre sens** (onglet 2 → onglet 1)

### ✅ Test 4 : Changements Multiples

1. **Modifier rapidement plusieurs GPIO** (3-4 switches)

2. **Dans l'autre onglet** :
   - [ ] Tous les switches se mettent à jour
   - [ ] Toast notification affiche : "Changement détecté: GPIO X, GPIO Y, GPIO Z"
   - [ ] Pas d'erreurs dans la console

### ✅ Test 5 : Gestion de la Visibilité de Page

1. **Sur la page `/control`** avec badge "SYNC" (vert)

2. **Changer d'onglet** (aller sur un autre site)
   - [ ] Attendre quelques secondes puis revenir
   - [ ] Le badge affiche brièvement "PAUSE" (bleu) ou "CONNEXION..." (orange)
   - [ ] Le badge repasse à "SYNC" (vert) après ~1 seconde
   - [ ] La synchronisation reprend normalement

### ✅ Test 6 : Gestion d'Erreur

**Simuler une perte de connexion** :

1. **Dans DevTools** → Network → Cocher "Offline"

2. **Attendre le prochain poll** (max 10 secondes) :
   - [ ] Le badge passe à "RECONNEXION..." (jaune, animation pulse)
   - [ ] Console affiche : `[ControlSync] Polling error: ...`
   - [ ] Console affiche : `[ControlSync] Retry 1/5 in Xms`

3. **Décocher "Offline"** :
   - [ ] Le badge repasse à "SYNC" (vert)
   - [ ] La synchronisation reprend normalement

4. **Simuler 5 échecs consécutifs** (garder Offline pendant 1 minute) :
   - [ ] Le badge passe à "ERREUR" (rouge)
   - [ ] Toast notification : "Synchronisation interrompue après plusieurs échecs"
   - [ ] La synchronisation s'arrête

### ✅ Test 7 : Toggle Manuel + Sync

1. **Activer un switch manuellement**

2. **Vérifier la console** :
   ```
   Toggle ID X (GPIO Y) to state 1
   Response status: 200
   ```

3. **Après ~500ms** :
   - [ ] Console affiche : `[ControlSync] Force sync requested`
   - [ ] Une requête immédiate est envoyée (pas besoin d'attendre 10s)
   - [ ] L'état est confirmé depuis le serveur

### ✅ Test 8 : Mise à Jour des Paramètres

1. **Modifier un paramètre** dans le formulaire (ex: température chauffage)

2. **Cliquer "Enregistrer"**
   - [ ] Alert "Changement pris en compte"
   - [ ] La page se recharge après 1,5 secondes
   - [ ] Après rechargement, le badge LIVE se réinitialise normalement

### ✅ Test 9 : Performance

1. **Ouvrir DevTools** → Performance → Enregistrer pendant 30 secondes

2. **Vérifier** :
   - [ ] Pas de fuite mémoire (Memory tab)
   - [ ] CPU usage reste faible (<5% entre les polls)
   - [ ] Requêtes réseau espacées de ~10 secondes
   - [ ] Pas de requêtes manquées ou en erreur

### ✅ Test 10 : Mobile

1. **Sur smartphone ou simulateur mobile** (Chrome DevTools → Toggle device toolbar)

2. **Vérifier** :
   - [ ] Badge LIVE est plus petit mais visible (coin haut-droit)
   - [ ] Switches fonctionnent bien au toucher
   - [ ] Animations flash sont visibles
   - [ ] Toast notifications apparaissent correctement
   - [ ] Synchronisation fonctionne identique au desktop

---

## 🐛 Résolution de Problèmes

### Badge reste bloqué sur "CONNEXION..."

**Cause** : L'API `/api/outputs/state` ne répond pas

**Solution** :
1. Vérifier la route dans `public/index.php` (ligne ~68 ou ~103 pour test)
2. Tester manuellement : `curl https://iot.olution.info/ffp3/api/outputs/state`
3. Vérifier les logs serveur PHP

### Pas de mise à jour automatique

**Cause** : Le script `control-sync.js` ne se charge pas

**Solution** :
1. Vérifier le fichier existe : `public/assets/js/control-sync.js`
2. Console : chercher "Failed to load control-sync.js"
3. Vérifier les permissions du fichier (chmod 644)

### Erreurs 404 sur les requêtes

**Cause** : Mauvais chemin d'API (PROD vs TEST)

**Solution** :
1. Console : vérifier la valeur de `API_BASE`
2. Doit être `/ffp3/api/outputs` (PROD) ou `/ffp3/api/outputs-test` (TEST)
3. Vérifier la variable `environment` dans Twig

### Badge affiche "ERREUR" immédiatement

**Cause** : Problème de connexion ou de permission

**Solution** :
1. Vérifier que la table `ffp3Outputs` (ou `ffp3Outputs2`) existe
2. Vérifier les credentials de base de données dans `.env`
3. Tester avec un autre navigateur (problème de CORS ?)

---

## 📊 Résultats Attendus

### Comportement Normal

- ✅ Badge "SYNC" (vert) en permanence
- ✅ Requête GET toutes les 10 secondes vers `/api/outputs/state`
- ✅ Switches se mettent à jour dans les 10 secondes max après un changement externe
- ✅ Animation flash visible lors des changements
- ✅ Toast notifications informatives (pas trop fréquentes)
- ✅ Aucune erreur dans la console

### Métriques de Performance

- **Taille du script** : ~7 KB (control-sync.js)
- **Taille de la réponse API** : ~500 bytes (JSON compact)
- **Fréquence de polling** : 10 secondes
- **Bande passante** : ~3 KB/minute par utilisateur actif
- **CPU usage** : <5% (entre les polls)
- **Latence de synchronisation** : 0-10 secondes

---

## ✅ Validation Finale

Une fois tous les tests passés, cocher :

- [ ] Badge LIVE fonctionne avec tous les états
- [ ] Synchronisation multi-utilisateur opérationnelle
- [ ] Gestion des erreurs robuste
- [ ] Performance acceptable
- [ ] Compatible mobile
- [ ] Compatible environnement TEST

**Si tous les tests passent** : ✅ La synchronisation temps réel est opérationnelle !

**Si des tests échouent** : ❌ Consulter la section "Résolution de Problèmes" ou les logs serveur.

---

## 📝 Notes de Déploiement

### Fichiers modifiés / créés
- ✅ `public/assets/js/control-sync.js` (nouveau)
- ✅ `templates/control.twig` (modifié)
- ✅ `VERSION` (4.1.0 → 4.2.0)
- ✅ `CHANGELOG.md` (nouvelle entrée)

### Aucune modification de base de données requise
- Utilise l'API existante `/api/outputs/state`
- Pas de nouvelle table, pas de migration

### Compatibilité
- ✅ Rétrocompatible : l'interface fonctionne toujours sans JavaScript
- ✅ Progressive enhancement : synchronisation = bonus si JS activé
- ✅ Pas de breaking changes

---

**Créé le** : 2025-10-11  
**Auteur** : AI Assistant  
**Version** : 1.0

