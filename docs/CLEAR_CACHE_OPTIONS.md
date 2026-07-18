# 🧹 Options de nettoyage du cache serveur - FFP3

## 📋 Vue d'ensemble

Le projet FFP3 utilise plusieurs types de cache qui peuvent nécessiter un nettoyage :

1. **Cache Twig** : Templates compilés (`var/cache/twig/`)
2. **Cache DI Container** : Injection de dépendances compilée (`var/cache/di/`)
3. ~~Cache en mémoire (OutputCacheService)~~ : **Supprimé v5.x** — Lecture BDD directe (évitait stale data en PHP-FPM multi-workers)

---

## 🎯 Options de nettoyage disponibles

### Option 1 : Script PHP en ligne de commande ⭐ **RECOMMANDÉ**

**Fichier** : `bin/clear-cache.php`

**Avantages** :
- ✅ Simple et direct
- ✅ Utilisable en SSH
- ✅ Peut être intégré dans des scripts CRON
- ✅ Affiche un feedback détaillé

**Utilisation** :
```bash
# Depuis la racine du projet
php bin/clear-cache.php
```

**Sortie attendue** :
```
🧹 Vidage des caches en cours...

🗑️  Vidage de twig/...
   ✅ 42 fichier(s) supprimé(s)
   📁 Dossier recréé
🗑️  Vidage de di/...
   ✅ 8 fichier(s) supprimé(s)
   📁 Dossier recréé

✅ Cache vidé avec succès ! (50 fichier(s) au total)
ℹ️  Les caches seront régénérés automatiquement à la prochaine requête.
```

**Quand l'utiliser** :
- Après un déploiement manuel
- Pour déboguer des problèmes de cache
- Dans des scripts de maintenance

---

### Option 2 : Route API JSON (via curl / script)

**URL** (méthode **POST** uniquement — écriture d'état) :
- Production : `https://iot.olution.info/ffp3/admin/clear-cache` (ou `/admin/clear-cache` si le
  DocumentRoot pointe directement sur `public/`)
- Test : `https://iot.olution.info/ffp3/admin/clear-cache-test`
- Autres environnements : `/admin/clear-cache3-test`, `/admin/clear-cache3`,
  `/admin/clear-cache-s3-test` (voir `config/routes_ffp3.php`)

> ⚠️ **Depuis la v6.8.8** : la route est en **POST** (plus de GET) et **réservée aux administrateurs**
> (middleware `$applyAuth`, voir `public/index.php` + `config/routes_helpers.php`).
> Une simple ouverture d'URL dans le navigateur ne vide plus le cache. Deux modes d'auth
> (voir `App\Security\AuthService::isAuthenticatedByToken`) :
> - **session admin** : depuis l'UI (page supervision ou `/admin/clear-cache-page`), le bouton envoie
>   automatiquement l'en-tête CSRF `X-CSRF-Token` ;
> - **jeton admin** : variable d'environnement `ADMIN_TOKEN` (pas `ADMIN_CACHE_TOKEN`, qui n'est lu
>   par aucun code du dépôt), passé via `?token=<ADMIN_TOKEN>` sur la requête POST (exempté de CSRF
>   car secret non-ambiant, voir `CsrfMiddleware`), l'en-tête `X-Admin-Token` / `Authorization: Bearer`,
>   ou le cookie `admin_token` posé après une première validation. Il n'existe **pas** de valeur par
>   défaut : `ADMIN_TOKEN` doit être défini dans `.env`, sinon le jeton est toujours refusé.
>
> ⛔ **Historique** : les anciens scripts HTTP non authentifiés `public/maintenance/clear-cache.php`
> et `public/maintenance/clear-di-cache.php` (vidage sans aucune vérification) ont été **supprimés**
> pour cette raison. Utiliser exclusivement la route `/admin/clear-cache*` ci-dessus (authentifiée)
> ou `bin/clear-cache.php` en SSH (option 1).

**Avantages** :
- ✅ Accessible depuis n'importe où (pas besoin de SSH)
- ✅ Retour JSON structuré
- ✅ Peut être appelée depuis un script ou un outil externe

**Utilisation** :

**Avec curl** :
```bash
curl -X POST "https://iot.olution.info/ffp3/admin/clear-cache?token=<ADMIN_TOKEN>"
```

**Avec PowerShell** :
```powershell
Invoke-WebRequest -Method POST -Uri "https://iot.olution.info/ffp3/admin/clear-cache?token=<ADMIN_TOKEN>"
```

**Réponse JSON** :
```json
{
    "success": true,
    "total_deleted": 50,
    "results": {
        "twig": {
            "status": "success",
            "message": "42 fichier(s) supprimé(s)",
            "deleted": 42
        },
        "di": {
            "status": "success",
            "message": "8 fichier(s) supprimé(s)",
            "deleted": 8
        }
    },
    "message": "Cache vidé avec succès ! (50 fichier(s) au total)",
    "errors": []
}
```

**Sécurité** :
- Jeton configurable via la variable d'environnement `ADMIN_TOKEN` (aucune valeur par défaut, à
  définir dans `.env`)
- Alternative sans jeton en URL : session admin (login `/admin/clear-cache-page`) ou en-tête
  `X-Admin-Token` / `Authorization: Bearer`
- Si l'authentification échoue, l'accès est refusé (redirection login ou erreur selon `AUTH_METHOD`)

**Quand l'utiliser** :
- Quand vous n'avez pas accès SSH
- Pour intégrer dans un outil de monitoring
- Pour automatiser depuis un autre serveur

---

### Option 3 : Page web interactive (interface graphique)

**URL** :
- Production : `https://iot.olution.info/ffp3/admin/clear-cache-page`
- Test : `https://iot.olution.info/ffp3/admin/clear-cache-page-test`

**Avantages** :
- ✅ Interface graphique conviviale
- ✅ Feedback visuel en temps réel
- ✅ Pas besoin de ligne de commande
- ✅ Accessible depuis n'importe quel navigateur

**Utilisation** :
1. Ouvrir l'URL dans votre navigateur
2. Cliquer sur le bouton "Vider le cache"
3. Attendre le résultat (affichage automatique)

**Fonctionnalités** :
- Bouton avec animation de chargement
- Affichage des résultats par type de cache
- Messages d'erreur clairs en cas de problème
- Lien de retour vers l'accueil

**Quand l'utiliser** :
- Pour les utilisateurs non techniques
- Pour un nettoyage rapide depuis le navigateur
- Pour visualiser l'état du cache

---

### Option 4 : Hook Git automatique (déploiement automatique)

**Fichier** : `.git/hooks/post-merge`

**Avantages** :
- ✅ Automatique après chaque `git pull` ou `git merge`
- ✅ Pas d'intervention manuelle nécessaire
- ✅ Fonctionne aussi avec les déploiements CRON

**Comment ça marche** :
1. Vous faites `git pull` sur le serveur
2. Git fusionne les modifications
3. Le hook `post-merge` s'exécute automatiquement
4. Les caches sont vidés via `bin/clear-cache.php`
5. Les modifications sont immédiatement visibles

**Installation** :
```bash
# Vérifier que le hook existe
ls -la .git/hooks/post-merge

# Le rendre exécutable si nécessaire
chmod +x .git/hooks/post-merge
```

**Contenu du hook** :
```bash
#!/bin/sh
PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if [ -f "$PROJECT_ROOT/bin/clear-cache.php" ]; then
    php "$PROJECT_ROOT/bin/clear-cache.php"
fi
```

**Quand l'utiliser** :
- ✅ **Toujours activé** pour les déploiements automatiques
- Fonctionne en arrière-plan, pas besoin d'y penser

---

### Option 5 : Script de déploiement intégré

**Fichier** : `bin/deploy.sh`

**Avantages** :
- ✅ Déploiement complet en une seule commande
- ✅ Inclut le vidage automatique des caches
- ✅ Met à jour les dépendances Composer
- ✅ Vérifie l'intégrité de l'installation

**Utilisation** :
```bash
# Sur le serveur de production
ssh oliviera@toaster
cd /home4/oliviera/iot.olution.info/ffp3
bash bin/deploy.sh
```

**Ce que fait le script** :
1. Fait le `git pull`
2. Vide automatiquement les caches (appelle `bin/clear-cache.php`)
3. Installe/met à jour les dépendances Composer
4. Vérifie l'intégrité de l'installation
5. Affiche les URLs de test

**Quand l'utiliser** :
- Pour un déploiement complet et sécurisé
- Pour s'assurer que tout est à jour
- Méthode recommandée pour les déploiements en production

---

### Option 6 : Nettoyage manuel (méthode de secours)

**Quand utiliser** :
- Si tous les autres scripts échouent
- En cas de problème de permissions
- Pour un nettoyage ciblé d'un seul type de cache

**Commandes** :

**Linux/Mac** :
```bash
# Vider le cache Twig uniquement
rm -rf var/cache/twig/*

# Vider le cache DI uniquement
rm -rf var/cache/di/*

# Vider tous les caches
rm -rf var/cache/twig/* var/cache/di/*

# Recréer les dossiers si nécessaire
mkdir -p var/cache/twig var/cache/di
chmod -R 775 var/cache/
```

**Windows (PowerShell)** :
```powershell
# Vider le cache Twig uniquement
Remove-Item -Path "var\cache\twig\*" -Recurse -Force

# Vider le cache DI uniquement
Remove-Item -Path "var\cache\di\*" -Recurse -Force

# Vider tous les caches
Remove-Item -Path "var\cache\twig\*", "var\cache\di\*" -Recurse -Force

# Recréer les dossiers si nécessaire
New-Item -ItemType Directory -Path "var\cache\twig", "var\cache\di" -Force
```

**⚠️ Attention** :
- Ne supprimez pas les dossiers `var/cache/twig/` et `var/cache/di/` eux-mêmes
- Seulement leur contenu (`/*`)
- Vérifiez les permissions après le nettoyage

---

### Option 7 : ~~Invalidation du cache OutputCacheService~~ (Obsolète)

**Statut** : **Cache supprimé v5.x** — `OutputCacheService` lit désormais directement en BDD à chaque GET.

**Raison** : En PHP-FPM multi-workers, l'invalidation ne s'appliquait qu'au worker courant ; un autre worker pouvait servir des données obsolètes (jusqu'à 5 s) à l'ESP32. Une requête SELECT par poll (60 s prod, 6 s test) est négligeable.

Les méthodes `invalidateCache()` et `getCacheStats()` restent pour compatibilité API mais sont des no-ops.

---

## 📊 Comparaison des options

| Option | Accès requis | Automatique | Feedback | Recommandé pour |
|--------|--------------|-------------|----------|-----------------|
| **Script PHP CLI** | SSH | ❌ | ✅ Détaillé | Déploiements manuels |
| **Route API JSON** | HTTP | ❌ | ✅ JSON | Intégration externe |
| **Page web** | Navigateur | ❌ | ✅ Visuel | Utilisateurs non-tech |
| **Hook Git** | Git | ✅ | ⚠️ Silencieux | Déploiements automatiques |
| **Script deploy.sh** | SSH | ⚠️ Semi | ✅ Complet | Déploiements complets |
| **Nettoyage manuel** | SSH/Fichier | ❌ | ❌ | Dépannage |
| ~~Cache mémoire~~ | — | — | — | Obsolète (supprimé v5.x) |

---

## 🎯 Recommandations par scénario

### Scénario 1 : Déploiement normal
```bash
# Méthode recommandée
bash bin/deploy.sh
```
→ Inclut automatiquement le vidage des caches

### Scénario 2 : Déploiement via Git
```bash
git pull origin main
# Le hook post-merge vide automatiquement les caches
```

### Scénario 3 : Nettoyage rapide sans déploiement
```bash
# Option A : Ligne de commande
php bin/clear-cache.php

# Option B : Via navigateur
# Ouvrir : https://iot.olution.info/ffp3/admin/clear-cache-page
```

### Scénario 4 : Pas d'accès SSH
```
# Ouvrir dans le navigateur (session admin déjà connectée) :
https://iot.olution.info/ffp3/admin/clear-cache-page
# Ou avec le jeton ADMIN_TOKEN (voir .env) :
https://iot.olution.info/ffp3/admin/clear-cache-page?token=<ADMIN_TOKEN>
```

### Scénario 5 : Intégration dans un outil externe
```bash
# Appel API JSON (POST, jeton ADMIN_TOKEN)
curl -X POST "https://iot.olution.info/ffp3/admin/clear-cache?token=<ADMIN_TOKEN>"
```

### Scénario 6 : Dépannage (scripts échouent)
```bash
# Nettoyage manuel
rm -rf var/cache/twig/* var/cache/di/*
mkdir -p var/cache/twig var/cache/di
chmod -R 775 var/cache/
```

---

## 🔧 Configuration

### Token de sécurité

Les routes `/admin/clear-cache*` sont protégées par `$applyAuth` (session admin, voir
`AUTH_METHOD` dans `.env`). Pour autoriser en plus un accès par jeton (sans session), configurez
dans `.env` :

```env
ADMIN_TOKEN=votre-token-secret-ici
```

Il n'y a **pas** de valeur par défaut : sans `ADMIN_TOKEN` défini, l'authentification par jeton est
toujours refusée (`App\Security\AuthService::validateToken`).

**Utilisation** :
```
https://iot.olution.info/ffp3/admin/clear-cache?token=votre-token-secret-ici
```

---

## ⚠️ Troubleshooting

### Les caches ne se vident pas

1. **Vérifier les permissions** :
   ```bash
   chmod -R 775 var/cache/
   chown -R www-data:www-data var/cache/  # Ou votre utilisateur
   ```

2. **Vérifier que les dossiers existent** :
   ```bash
   ls -la var/cache/
   ```

3. **Tester le script manuellement** :
   ```bash
   php bin/clear-cache.php
   ```

4. **Vérifier les logs** :
   - Vérifier les erreurs PHP dans les logs du serveur
   - Vérifier les permissions d'écriture

### Le hook Git ne fonctionne pas

1. **Vérifier qu'il existe** :
   ```bash
   ls -la .git/hooks/post-merge
   ```

2. **Le rendre exécutable** :
   ```bash
   chmod +x .git/hooks/post-merge
   ```

3. **Tester manuellement** :
   ```bash
   .git/hooks/post-merge
   ```

### Erreur 401/403 (ou redirection login) sur les routes admin

- Vérifier que vous êtes connecté en session admin, **ou** que le jeton passé (`?token=`, en-tête
  `X-Admin-Token`/`Authorization: Bearer`) correspond bien à `ADMIN_TOKEN` dans `.env`
- Vérifier que `ADMIN_TOKEN` est bien défini côté serveur (aucune valeur par défaut n'est acceptée)

---

## 📚 Fichiers liés

- `bin/clear-cache.php` : Script CLI principal de vidage (SSH)
- `src/Controller/Ffp3/CacheController.php` : Contrôleur des routes admin `/admin/clear-cache*`
- `config/routes_ffp3.php` / `config/routes_helpers.php` : déclaration des routes admin par
  environnement (protégées par `$applyAuth`)
- `bin/deploy.sh` : Script de déploiement complet
- `.git/hooks/post-merge` : Hook Git automatique
- `docs/deployment/CACHE_MANAGEMENT.md` : Documentation détaillée du cache

> ⛔ Les scripts `public/maintenance/clear-cache.php` et `public/maintenance/clear-di-cache.php`
> (vidage HTTP non authentifié) ont été supprimés — ne pas les recréer.

---

**Document créé le** : 2025-01-27  
**Dernière mise à jour** : 2026-07-15 (retrait des scripts HTTP non authentifiés `public/maintenance/`)
