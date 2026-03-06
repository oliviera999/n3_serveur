# 🧹 Options de nettoyage du cache serveur - FFP3

## 📋 Vue d'ensemble

Le projet FFP3 utilise plusieurs types de cache qui peuvent nécessiter un nettoyage :

1. **Cache Twig** : Templates compilés (`var/cache/twig/`)
2. **Cache DI Container** : Injection de dépendances compilée (`var/cache/di/`)
3. **Cache en mémoire (OutputCacheService)** : Cache des états outputs (TTL 5 secondes, auto-invalidé)

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

### Option 2 : Route API JSON (via navigateur ou curl)

**URL** : 
- Production : `https://iot.olution.info/ffp3/admin/clear-cache`
- Test : `https://iot.olution.info/ffp3/admin/clear-cache-test`

**Avantages** :
- ✅ Accessible depuis n'importe où (pas besoin de SSH)
- ✅ Retour JSON structuré
- ✅ Peut être appelée depuis un script ou un outil externe
- ✅ Protection optionnelle par token

**Utilisation** :

**Avec navigateur** :
```
https://iot.olution.info/ffp3/admin/clear-cache?token=clear-cache-2025
```

**Avec curl** :
```bash
curl "https://iot.olution.info/ffp3/admin/clear-cache?token=clear-cache-2025"
```

**Avec PowerShell** :
```powershell
Invoke-WebRequest -Uri "https://iot.olution.info/ffp3/admin/clear-cache?token=clear-cache-2025"
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
- Token configurable via variable d'environnement `ADMIN_CACHE_TOKEN`
- Par défaut : `clear-cache-2025`
- Si le token ne correspond pas, retourne une erreur 403

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

### Option 7 : Invalidation du cache en mémoire (OutputCacheService)

**Service** : `App\Service\OutputCacheService`

**Type de cache** : Cache en mémoire des états outputs (TTL 5 secondes)

**Avantages** :
- ✅ Auto-invalidé après chaque modification d'output
- ✅ TTL court (5 secondes) donc se renouvelle automatiquement
- ✅ Séparé par environnement (PROD/TEST)

**Utilisation programmatique** :
```php
use App\Service\OutputCacheService;

$outputCache = new OutputCacheService();
$outputCache->invalidateCache(); // Invalide le cache pour l'environnement actuel
```

**Quand c'est nécessaire** :
- ✅ **Déjà automatique** : Le cache est invalidé automatiquement après chaque modification d'output
- ✅ **TTL court** : Le cache expire automatiquement après 5 secondes
- ✅ **Pas d'action manuelle nécessaire** dans la plupart des cas

**Vérification des statistiques** :
```php
$stats = $outputCache->getCacheStats();
// Retourne : ['valid', 'environment', 'age_seconds', 'ttl_seconds', 'cached_items']
```

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
| **Cache mémoire** | Code PHP | ✅ | ❌ | Déjà automatique |

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
# Ouvrir dans le navigateur
https://iot.olution.info/ffp3/admin/clear-cache-page?token=clear-cache-2025
```

### Scénario 5 : Intégration dans un outil externe
```bash
# Appel API JSON
curl "https://iot.olution.info/ffp3/admin/clear-cache?token=clear-cache-2025"
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

### Token de sécurité (optionnel)

Pour sécuriser les routes `/admin/clear-cache*`, configurez dans `.env` :

```env
ADMIN_CACHE_TOKEN=votre-token-secret-ici
```

Si non défini, le token par défaut est `clear-cache-2025`.

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

### Erreur 403 sur les routes API

- Vérifier que le token correspond à `ADMIN_CACHE_TOKEN` dans `.env`
- Ou utiliser le token par défaut : `clear-cache-2025`

---

## 📚 Fichiers liés

- `bin/clear-cache.php` : Script principal de vidage
- `src/Controller/CacheController.php` : Contrôleur des routes API/web
- `bin/deploy.sh` : Script de déploiement complet
- `.git/hooks/post-merge` : Hook Git automatique
- `docs/deployment/CACHE_MANAGEMENT.md` : Documentation détaillée du cache

---

**Document créé le** : 2025-01-27  
**Dernière mise à jour** : 2025-01-27
