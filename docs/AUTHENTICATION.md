# Guide d'authentification - Pages d'administration

Ce document explique comment configurer et utiliser le système d'authentification pour protéger les pages sensibles de l'interface d'administration.

## Vue d'ensemble

Le système d'authentification protège les pages suivantes :
- Pages de contrôle : `/aquaponie-control`, `/aquaponie-control-test`, `/aquamobile-control`, `/aquamobile-control-test`
- Pages d'administration : `/admin/*`
- Dashboards : `/dashboard*`, `/supervision`, `/tide-stats*`
- APIs sensibles : `/api/outputs/*`
- Export de données : `/export-data*`

**Pages publiques** (non protégées) :
- `/` (page d'accueil)
- Toutes les pages aquaponie : `/aquaponie`, `/aquaponie-test`, `/aquamobile`, `/aquamobile-test`
- `/login`, `/logout` (routes d'authentification)
- `/post-data*`, `/heartbeat*` (endpoints ESP32 - utilisent déjà HMAC/API_KEY)

## Méthodes d'authentification

Trois modes sont disponibles :

### 1. Authentification par session (Recommandée)

**Avantages** :
- Expérience utilisateur standard (formulaire de login)
- Sécurisée (mots de passe hashés avec bcrypt)
- Session persistante (reste connecté pendant 2 heures)

**Configuration** :
```env
AUTH_METHOD=session
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
```

### 2. Authentification par token (Simple)

**Avantages** :
- Très simple à utiliser (token dans URL ou cookie)
- Pas de formulaire de login
- Idéal pour usage personnel/unique utilisateur

**Configuration** :
```env
AUTH_METHOD=token
ADMIN_TOKEN=votre_token_aleatoire_ici
```

**Utilisation** :
- Ajouter `?token=votre_token` à l'URL
- Ou définir un cookie `admin_token` avec la valeur du token

### 3. Les deux combinés

**Configuration** :
```env
AUTH_METHOD=both
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=$2y$10$...
ADMIN_TOKEN=votre_token_aleatoire_ici
```

**Comportement** :
- Si une session existe, elle est utilisée
- Sinon, le token est vérifié (cookie ou paramètre URL)
- Si aucune des deux méthodes n'est valide, redirection vers `/login`

## Configuration

### Étape 1 : Générer le hash de mot de passe

Pour l'authentification par session, vous devez générer un hash de votre mot de passe :

```bash
# Méthode 1 : Script helper
php tools/generate_password_hash.php votre_mot_de_passe

# Méthode 2 : Ligne de commande PHP directe
php -r "echo password_hash('votre_mot_de_passe', PASSWORD_DEFAULT);"
```

### Étape 2 : Générer un token aléatoire (optionnel)

Pour l'authentification par token :

```bash
# Méthode 1 : PHP
php -r "echo bin2hex(random_bytes(32));"

# Méthode 2 : OpenSSL
openssl rand -hex 32
```

### Étape 3 : Configurer le fichier `.env`

Ajoutez les variables suivantes dans votre fichier `.env` :

```env
# Méthode d'authentification : 'session', 'token', ou 'both'
AUTH_METHOD=session

# Pour l'authentification par session
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=$2y$10$votre_hash_genere_ici

# Pour l'authentification par token (optionnel)
ADMIN_TOKEN=votre_token_aleatoire_ici
```

## Utilisation

### Authentification par session

1. Accédez à n'importe quelle page protégée (ex: `/aquaponie-control`)
2. Vous serez redirigé vers `/login`
3. Entrez votre nom d'utilisateur et mot de passe
4. Vous serez redirigé vers la page demandée
5. La session reste active pendant 2 heures d'inactivité

**Déconnexion** :
- Accédez à `/logout` pour vous déconnecter

### Authentification par token

**Méthode 1 : Paramètre URL**
```
https://iot.olution.info/ffp3/aquaponie-control?token=votre_token
```

**Méthode 2 : Cookie**
Définissez un cookie `admin_token` avec la valeur de votre token. Le cookie sera automatiquement utilisé pour les prochaines requêtes.

## Sécurité

### Bonnes pratiques implémentées

1. **Hashage des mots de passe** : Utilisation de `password_hash()` avec bcrypt (PASSWORD_DEFAULT)
2. **Protection CSRF** : Tokens CSRF sur le formulaire de login
3. **Rate limiting** : Limitation à 5 tentatives de login par 15 minutes par IP
4. **Session sécurisée** :
   - Cookie HttpOnly (protection XSS)
   - Cookie Secure si HTTPS
   - Régénération d'ID de session après login
5. **Timeout de session** : Expiration après 2 heures d'inactivité

### Protection contre les attaques

- **Brute force** : Rate limiting sur `/login`
- **Session fixation** : Régénération d'ID après login
- **XSS** : Échappement des entrées dans templates Twig
- **CSRF** : Tokens CSRF sur formulaires

## Dépannage

### Problème : Redirection infinie vers `/login`

**Cause** : La variable `AUTH_METHOD` n'est pas définie ou invalide.

**Solution** :
1. Vérifiez que `AUTH_METHOD` est défini dans `.env`
2. Vérifiez que les valeurs sont correctes : `session`, `token`, ou `both`
3. Si vous voulez désactiver temporairement l'authentification, définissez `AUTH_METHOD=none`

### Problème : "Nom d'utilisateur ou mot de passe incorrect"

**Causes possibles** :
1. Le hash du mot de passe est incorrect
2. Le nom d'utilisateur ne correspond pas à `ADMIN_USERNAME`

**Solution** :
1. Régénérez le hash avec `php tools/generate_password_hash.php`
2. Vérifiez que `ADMIN_USERNAME` correspond exactement au nom d'utilisateur saisi

### Problème : Token invalide

**Causes possibles** :
1. Le token dans `.env` ne correspond pas au token utilisé
2. Le cookie a expiré

**Solution** :
1. Vérifiez que `ADMIN_TOKEN` dans `.env` correspond au token utilisé
2. Régénérez un nouveau token si nécessaire

### Problème : Session expirée trop rapidement

**Cause** : Le timeout de session est configuré à 2 heures.

**Solution** : Modifiez la constante `SESSION_TIMEOUT` dans `src/Security/AuthService.php` si nécessaire.

## Désactiver temporairement l'authentification

Pour désactiver temporairement l'authentification (par exemple pour le développement) :

```env
AUTH_METHOD=none
```

⚠️ **Attention** : Ne jamais utiliser `AUTH_METHOD=none` en production !

## Migration depuis une version sans authentification

Si vous migrez depuis une version sans authentification :

1. Ajoutez les variables d'authentification dans `.env`
2. Générez un hash de mot de passe
3. Définissez `AUTH_METHOD=session` (ou `token` selon vos besoins)
4. Testez l'accès aux pages protégées
5. Les endpoints ESP32 (`/post-data`, `/heartbeat`) continuent de fonctionner sans changement (ils utilisent déjà HMAC/API_KEY)

## Support

Pour toute question ou problème, consultez :
- Le code source : `src/Security/AuthService.php`
- Les middlewares : `src/Middleware/AuthMiddleware.php` et `src/Middleware/TokenAuthMiddleware.php`
- Le contrôleur : `src/Controller/AuthController.php`
