# 📜 Fichiers Legacy - Documentation

Ce document explique le rôle de chaque fichier legacy présent dans le projet FFP3 Datas et leur statut actuel.

---

## 🎯 Vue d'ensemble

Le projet FFP3 Datas a été progressivement migré vers une architecture moderne (Slim 4 + Twig + DI). Plusieurs fichiers legacy ont été conservés pour assurer la rétrocompatibilité avec :
- Les ESP32 qui pointent vers d'anciennes URL
- Les liens existants dans d'autres systèmes
- La transition progressive vers la nouvelle architecture

---

## 📂 Fichiers de Redirection

### `index.php`
**Statut** : ✅ Actif - Redirection permanente  
**Rôle** : Redirige `https://iot.olution.info/ffp3/ffp3datas/` vers `ffp3-data.php`  
**Action recommandée** : Conserver pour compatibilité

```php
header('Status: 301 Moved Permanently', false, 301);
header('Location: https://iot.olution.info/ffp3/ffp3datas/ffp3-data.php');
```

### `ffp3-data.php`
**Statut** : ✅ Actif - Pont vers Slim  
**Rôle** : Redirige vers la route moderne `/aquaponie` (PROD) en transférant les données POST via session  
**Action recommandée** : Conserver pour ESP32 et liens legacy

**Mécanisme** :
1. Détecte les requêtes POST et stocke les données en session
2. Redirige vers `/aquaponie` (route Slim)
3. `AquaponieController` récupère les données POST depuis la session

### `ffp3-data2.php`
**Statut** : ✅ Actif - Pont vers Slim TEST  
**Rôle** : Identique à `ffp3-data.php` mais redirige vers `/aquaponie-test` (TEST)  
**Action recommandée** : Conserver pour environnement de test

---

## 📡 Endpoints POST pour ESP32

### `post-ffp3-data.php`
**Statut** : ✅ Actif - Endpoint legacy PROD  
**Rôle** : Point d'entrée pour les ESP32 de production qui envoient leurs données  
**Fonctionnement** :
```php
$_ENV['ENV'] = 'prod';
require_once __DIR__ . '/vendor/autoload.php';
\App\Config\Env::load();
$controller = new \App\Controller\PostDataController();
$controller->handle();
```

**Action recommandée** : Conserver jusqu'à migration complète des ESP32 vers `/public/post-data`

### `post-ffp3-data2.php`
**Statut** : ✅ Actif - Endpoint legacy TEST  
**Rôle** : Identique à `post-ffp3-data.php` mais pour l'environnement TEST  
**Action recommandée** : Conserver pour environnement de test

---

## 🔧 Fichiers de Configuration Legacy

### `ffp3-config2.php`
**Statut** : ⚠️ Obsolète (mais conservé)  
**Rôle** : Ancienne configuration pour l'environnement TEST (avant migration vers `.env`)  
**Contenu** : Probablement des constantes de configuration DB, API keys, etc.  
**Action recommandée** : Vérifier si encore utilisé, sinon supprimer après audit

---

## 🗃️ Fichier Pont (Bridge)

### `legacy_bridge.php`
**Statut** : ❓ Incomplet/Obsolète  
**Rôle** : Semble être un template Twig incomplet qui n'est pas utilisé par le projet actuel  
**Problème** : Référence des fonctions `path('...')` qui n'existent pas dans les routes Slim  
**Action recommandée** : **À SUPPRIMER** après vérification qu'aucun système ne l'utilise

---

## 📅 Fichiers Utilitaires

### `run-cron.php`
**Statut** : ✅ Actif  
**Rôle** : Point d'entrée pour les tâches CRON  
**Fonctionnement** : Charge l'autoloader Composer et exécute les commandes CRON  
**Action recommandée** : Conserver

### `heartbeat.php`
**Statut** : ✅ Actif  
**Rôle** : Endpoint de vérification de santé du serveur (health check)  
**Action recommandée** : Conserver

### `debug_tide_stats.php`
**Statut** : 🛠️ Debug/Développement  
**Rôle** : Script de debug pour tester les statistiques de marée  
**Action recommandée** : Supprimer en production, conserver en dev

---

## 🚦 Plan de Migration

### Phase 1 : Redirection (✅ Terminé)
- [x] Créer les routes Slim modernes
- [x] Créer les fichiers de redirection (`ffp3-data.php`, `post-ffp3-data.php`)
- [x] Transférer les données POST via session

### Phase 2 : Migration ESP32 (En cours)
- [ ] Mettre à jour le firmware des ESP32 pour pointer vers `/public/post-data`
- [ ] Tester en environnement TEST (`/public/post-data-test`)
- [ ] Déployer sur tous les ESP32 de production

### Phase 3 : Nettoyage (Futur)
- [ ] Supprimer `post-ffp3-data.php` et `post-ffp3-data2.php`
- [ ] Supprimer `ffp3-data.php` et `ffp3-data2.php`
- [ ] Supprimer `ffp3-config2.php` si obsolète
- [ ] Supprimer `legacy_bridge.php`
- [ ] Documenter les changements dans `CHANGELOG.md`

---

## 🔒 Règles de Sécurité

⚠️ **Important** : Les fichiers legacy doivent respecter les mêmes règles de sécurité que le reste du projet :

1. **Authentification API** : Tous les endpoints POST doivent valider `API_KEY` et optionnellement la signature HMAC
2. **Validation des données** : Les paramètres doivent être filtrés et validés
3. **Protection SQL** : Utiliser des prepared statements (déléguer aux repositories)
4. **Logs** : Toute action doit être loggée via `LogService`

---

## 📞 Contact

Pour toute question concernant les fichiers legacy, consulter :
- `AUDIT_PROJET.md` : Analyse complète du projet
- `MIGRATION_CONTROL_COMPLETE.md` : Documentation de la migration du contrôle
- `ENVIRONNEMENT_TEST.md` : Guide des environnements PROD/TEST

**Dernière mise à jour** : 2025-10-10  
**Version du projet** : 3.0.0

