<!-- cd30e83d-9764-4afb-80ab-ea8d5df49856 61f4f8c2-3352-41d1-b2b4-280dd8e42d9a -->
# Correction des Problèmes de Production FFP3

## Vue d'ensemble

Corriger les 27 erreurs identifiées dans l'audit, restaurer les fonctionnalités critiques (API temps réel, interface de contrôle), et nettoyer le projet.

## Priorité 1 - CRITIQUE (Immédiat)

### 1.1 Diagnostiquer les erreurs 500 sur les API

- Lire les logs serveur pour identifier la cause racine
- Vérifier `RealtimeApiController` et `OutputController`
- Vérifier le container DI (`config/container.php`, `config/dependencies.php`)
- Tester les dépendances (services injectés)

### 1.2 Corriger les erreurs 500

- Corriger les bugs identifiés dans les contrôleurs
- Vérifier les appels de méthodes sur les repositories
- S'assurer que toutes les dépendances sont correctement configurées
- Tester localement avant déploiement

### 1.3 Corriger `/post-ffp3-data.php`

Trois options possibles :

- **Option A (Recommandée)** : Créer un bridge legacy fonctionnel qui instancie correctement Request/Response
- **Option B** : Créer une redirection 301 vers `/post-data`
- **Option C** : Supprimer le fichier et documenter la migration

Fichier à créer/modifier : `post-ffp3-data.php` (à la racine ou dans public/)

## Priorité 2 - IMPORTANTE

### 2.1 Résoudre les fichiers OTA manquants

Fichiers à créer ou nettoyer dans metadata.json :

- `ota/esp32-s3/firmware.bin` (PROD)
- `ota/test/firmware.bin` (TEST)
- `ota/test/esp32-wroom/firmware.bin` (TEST)
- `ota/test/esp32-s3/firmware.bin` (TEST)

Options :

- Si les firmwares existent localement : les uploader
- Sinon : nettoyer `ota/metadata.json` pour retirer les références

### 2.2 Optimiser `/tide-stats` (1.37s)

- Analyser les requêtes SQL dans `TideStatsController`
- Identifier les requêtes lentes
- Implémenter du cache ou optimiser les requêtes

## Priorité 3 - OPTIONNELLE (Nettoyage)

### 3.1 Nettoyer les fichiers legacy obsolètes

Fichiers à déplacer vers `unused/` ou supprimer :

- `index.html` (ancien index statique)
- Évaluer `/ffp3gallery/` (si non utilisé)
- Vérifier `/ffp3control/` (déjà dans unused?)

### 3.2 Supprimer les alias legacy inutiles

Routes à évaluer pour suppression ou redirection :

- `/ffp3-data` → rediriger vers `/aquaponie`
- `/export-data.php` → déjà redirigé (308)
- `/heartbeat.php` → évaluer si encore utilisé par ESP32

### 3.3 Créer des redirections 301 propres

Dans `public/index.php`, ajouter des redirections explicites :

- `/ffp3-data` → `/aquaponie` (301)
- `/heartbeat.php` → `/heartbeat` (301)

### 3.4 Documenter les changements

- Mettre à jour `CHANGELOG.md`
- Incrémenter la version dans `VERSION`
- Documenter les endpoints obsolètes dans un fichier DEPRECATED.md

## Fichiers Clés à Modifier

- `src/Controller/RealtimeApiController.php` - Corriger erreurs 500
- `src/Controller/OutputController.php` - Corriger erreurs 500
- `config/container.php` ou `config/dependencies.php` - Vérifier DI
- `post-ffp3-data.php` - Créer bridge legacy ou redirection
- `ota/metadata.json` - Nettoyer références fichiers manquants
- `public/index.php` - Ajouter redirections 301
- `VERSION` - Incrémenter version
- `CHANGELOG.md` - Documenter corrections

## Tests de Validation

Après chaque correction, tester :

1. Les API temps réel : `curl https://iot.olution.info/ffp3/api/realtime/sensors/latest`
2. La page control : `curl https://iot.olution.info/ffp3/control`
3. Le post-data legacy : `curl -X POST https://iot.olution.info/ffp3/post-ffp3-data.php`
4. Les fichiers OTA : `curl -I https://iot.olution.info/ffp3/ota/esp32-s3/firmware.bin`

## Déploiement

1. Tester toutes les corrections en local
2. Commit des changements
3. Push vers GitHub
4. SSH vers le serveur : `ssh oliviera@toaster`
5. Naviguer vers `/home4/oliviera/iot.olution.info/ffp3`
6. Exécuter `bash DEPLOY_NOW.sh`
7. Vérifier les endpoints corrigés

### To-dos

- [ ] Lire les logs et diagnostiquer les erreurs 500 sur RealtimeApiController et OutputController
- [ ] Corriger les bugs dans RealtimeApiController et OutputController
- [ ] Corriger /post-ffp3-data.php (créer bridge ou redirection)
- [ ] Résoudre les fichiers OTA manquants (uploader ou nettoyer metadata.json)
- [ ] Optimiser la performance de /tide-stats (analyser et cacher)
- [ ] Nettoyer les fichiers legacy obsolètes (index.html, ffp3gallery)
- [ ] Ajouter redirections 301 pour alias legacy dans public/index.php
- [ ] Incrémenter VERSION et documenter dans CHANGELOG.md
- [ ] Tester tous les endpoints corrigés en production