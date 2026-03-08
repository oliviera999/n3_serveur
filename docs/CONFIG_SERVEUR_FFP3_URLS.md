# Configuration serveur : URLs /ffp3/*

## Contexte

Les URLs du type `https://iot.olution.info/ffp3/`, `/ffp3/dashboard`, `/ffp3/aquaponie`, etc. doivent renvoyer une **redirection 301** vers les chemins sans préfixe (`/`, `/dashboard`, `/aquaponie`) ou, à défaut, être traitées par l’application. Si ces URLs renvoient **404**, la requête n’atteint pas le front controller PHP.

## Point d’entrée unique

Toutes les requêtes (y compris `https://iot.olution.info/ffp3/*`) doivent être envoyées vers **`public/index.php`**. Le code applicatif (middleware dans `index.php`) redirige alors les GET `/ffp3` et `/ffp3/xxx` vers `/` et `/xxx` en 301.

## Apache

- **AllowOverride All** doit être activé pour le répertoire contenant `public/` (ou la racine du site) afin que le fichier **`public/.htaccess`** soit pris en compte.
- Les règles présentes dans `.htaccess` font déjà :
  - pour les requêtes **GET** : redirection 301 `^ffp3/(.*)$` → `/$1` ;
  - pour les autres requêtes : réécriture interne vers `index.php` (règle générale).

Si les URLs `/ffp3/*` renvoient 404, vérifier que :
1. Le document root pointe bien vers `public/` (ou que les règles Rewrite s’appliquent au bon chemin).
2. `AllowOverride All` est bien configuré pour ce répertoire.

## Nginx

Il faut l’équivalent des règles Apache :

1. **Redirection 301** pour les GET `/ffp3` et `/ffp3/` vers `/`, et pour `/ffp3/xxx` vers `/xxx`.
2. **Passage de toutes les requêtes** (fichiers/dossiers inexistants) vers `public/index.php` (front controller).

Exemple (à adapter selon la racine et le nom du site) :

```nginx
# Redirection 301 /ffp3 et /ffp3/ vers /
location = /ffp3 { return 301 /; }
location = /ffp3/ { return 301 /; }
# Redirection 301 /ffp3/xxx vers /xxx
location ~ ^/ffp3/(.+)$ {
    return 301 /$1;
}
# Front controller
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## Vérification

Après configuration, les requêtes GET vers `https://iot.olution.info/ffp3/` ou `https://iot.olution.info/ffp3/dashboard` doivent retourner **301** (redirection vers `/` et `/dashboard`), pas **404**. Le script `scripts/check-server-pages.ps1` (depuis la racine IOT_n3) peut être utilisé pour vérifier les URLs et les codes de réponse.
