# Que faire côté serveur (déploiement 5.0 — serveur unifié)

Ce document décrit les étapes à effectuer **sur le serveur de production** (iot.olution.info) après la migration vers le serveur unifié (Slim 4 à la racine, routes msp1/n3pp/galeries).

---

## 0. Déploiement automatique (CRON)

Le serveur de production exécute un **CRON toutes les minutes** qui fait un `git pull` sur le dépôt **n3_serveur** (`/home4/oliviera/iot.olution.info`).

**En pratique** : une fois le code poussé sur GitHub, il est déployé en production en moins d'une minute, sans action manuelle.

> Le push vers GitHub **est** le déploiement.

**Cas particulier — cache DI** : si `config/dependencies.php` ou des classes de contrôleurs sont ajoutés/modifiés, le cache DI compilé (`var/cache/di/CompiledContainer.php`) peut devenir obsolète et provoquer des erreurs 500. Solution :
1. Pousser un script `public/maintenance/clear-di-cache.php` dans le dépôt.
2. Attendre ~1 min (CRON pull).
3. Appeler `https://iot.olution.info/public/maintenance/clear-di-cache.php` pour vider le cache.
4. Supprimer le script du dépôt (sécurité) et pousser à nouveau.

**Note sur le DocumentRoot** : le DocumentRoot Apache pointe sur la **racine du dépôt** (pas sur `public/`). Le `.htaccess` racine redirige vers `public/index.php`. Les fichiers statiques dans `public/` sont accessibles avec le préfixe `/public/` (ex. `/public/maintenance/clear-di-cache.php`).

---

## 1. Récupérer le code (si le CRON n'est pas configuré)

Selon la façon dont le site est déployé :

**Si le site est un clone direct du dépôt n3_serveur :**

```bash
cd /chemin/vers/iot.olution.info   # ex. /home4/oliviera/iot.olution.info
git pull origin master
```

**Si le site est déployé depuis IOT_n3 (dépôt parent avec submodule) :**

```bash
cd /chemin/vers/IOT_n3
git pull origin master
git submodule update --init --recursive
cd serveur
git pull origin master
```

---

## 2. Configurer Apache / Nginx

Le **DocumentRoot** doit pointer vers le dossier **`public/`** du serveur unifié (plus vers `ffp3/` ou l’ancienne racine).

- **Recommandé** : `DocumentRoot = /chemin/vers/site/public`
- Ainsi les URLs sont : `https://iot.olution.info/`, `https://iot.olution.info/post-data`, `https://iot.olution.info/msp1/...`, etc. sans `/public/` dans l’URL.

**Si vous gardez le DocumentRoot sur la racine du dépôt** (et non sur `public/`) : le fichier `.htaccess` à la racine redirige vers `public/index.php`. Vérifier que `AllowOverride All` est actif pour ce répertoire.

**Exemple Apache (vhost) :**

```apache
DocumentRoot /home4/oliviera/iot.olution.info/public
<Directory /home4/oliviera/iot.olution.info/public>
    AllowOverride All
    Require all granted
</Directory>
```

---

## 3. Composer

À exécuter **à la racine du dépôt serveur** (là où se trouvent `composer.json` et `composer.lock`) :

```bash
cd /chemin/vers/site
composer install --no-dev --optimize-autoloader
```

En cas de souci avec `vendor/` :

```bash
rm -rf vendor/
composer install --no-dev --optimize-autoloader
```

---

## 4. Fichier .env

Le fichier `.env` ne doit **pas** être versionné. Sur le serveur :

```bash
cd /chemin/vers/site
cp .env.example .env
# Éditer .env avec les vraies valeurs (DB_*, API_KEY, etc.)
```

À renseigner au minimum :

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `API_KEY` (la même que celle utilisée par les firmwares)
- `GALLERY_MSP1_DIR`, `GALLERY_N3PP_DIR`, `GALLERY_FFP3_DIR` (ex. `uploads/msp1`, `uploads/n3pp`, `uploads/ffp3`)

---

## 5. Dossiers d’upload (galeries photo)

Créer les dossiers où les photos envoyées par les ESP32-CAM seront enregistrées :

```bash
cd /chemin/vers/site
mkdir -p uploads/msp1 uploads/n3pp uploads/ffp3
chmod 755 uploads
chmod 775 uploads/msp1 uploads/n3pp uploads/ffp3
# Ou selon l’utilisateur du serveur web (ex. www-data) :
# chown -R www-data:www-data uploads/
```

---

## 6. Base de données

Les contrôleurs msp1 et n3pp écrivent dans les tables **`msp1Data`**, **`msp1Outputs`**, **`n3ppData`**, **`n3ppOutputs`**.

- Si ces tables existaient déjà (anciens scripts PHP), rien à faire côté schéma.
- Sinon, les créer en vous basant sur la structure attendue par `MspSensorRepository`, `N3ppSensorRepository`, `MspOutputRepository`, `N3ppOutputRepository` (voir le code ou des migrations si disponibles).

Les tables FFP3 (ffp3Data, ffp3Outputs, etc.) restent inchangées.

---

## 7. Vérifications rapides

- **Version déployée :** `cat VERSION` → doit afficher `5.0.0` (ou supérieur).
- **Autoload PHP :**  
  `php -r "require 'vendor/autoload.php'; echo 'OK';"`  
  doit afficher `OK`.
- **URLs de test (depuis un navigateur ou curl) :**
  - `https://iot.olution.info/ping` → réponse `OK`
  - `https://iot.olution.info/login` → page de connexion
  - Les firmwares continuent d’appeler les mêmes URLs (`/msp1/msp1datas/post-msp1-data.php`, `/n3pp/...`, etc.) : pas de changement côté firmware.
- **En cas d’erreur 500** (ex. sur msp1-datas / n3pp-datas) : consulter le log PHP (`error_log` ou log Apache/Nginx). Le middleware d’erreur écrit une ligne `[n3 500]` avec la méthode, l’URL, la classe d’exception, le message, le fichier et la ligne, puis la stack trace, pour identifier la cause sans l’exposer à l’utilisateur.

---

## 8. Cron applicatif FFP3

Documentation complète : **[docs/deployment/CRON.md](CRON.md)**

Crontab recommandée (en plus du `git pull` minute, §0) :

```cron
*/5 * * * * cd /home4/oliviera/iot.olution.info && /usr/bin/php run-cron.php >> /dev/null 2>&1
```

**Migration** : supprimer `cronpompe.php`, anciennes entrées multiples ou scripts legacy (`cronmsp1.php`, `cronn3pp.php`). Pour `triphotos.php` (galeries) : voir `RECOMMANDATIONS_IOT.md`.

---

## 9. Rétrocompatibilité /ffp3/

Les firmwares ffp5cs qui envoient vers `/ffp3/post-data`, `/ffp3/heartbeat`, etc. sont gérés par la réécriture dans **`public/.htaccess`** :

- Règle : `RewriteRule ^ffp3/(.*)$ $1 [L]` (dans `serveur/public/.htaccess`).
- Ainsi, avec DocumentRoot = `public/`, une requête vers `https://iot.olution.info/ffp3/post-data` est réécrite en `/post-data` et traitée par Slim.

Les alias legacy LVGL (`/ffp3/ffp3datas/post-ffp3-data2.php`, `/ffp3/ffp3control/ffp3-outputs-action2.php`) sont réécrits en `/ffp3datas/...` et `/ffp3control/...` puis routés par les alias définis dans `public/index.php`.

---

## Résumé des commandes (à adapter au chemin réel)

```bash
cd /chemin/vers/site
git pull origin master
composer install --no-dev --optimize-autoloader
cp .env.example .env
# Éditer .env
mkdir -p uploads/msp1 uploads/n3pp uploads/ffp3
chmod 775 uploads/msp1 uploads/n3pp uploads/ffp3
cat VERSION
```

Puis vérifier le vhost (DocumentRoot = `.../public`) et tester `https://iot.olution.info/ping`.
