# Serveur unifié n3 IoT

Backend PHP (Slim 4) pour [iot.olution.info](https://iot.olution.info) : collecte des données (msp1, n3pp, ffp3), contrôle des sorties, galeries photo.

- **`site initial/`** : ancienne version des fichiers serveur (archive). **Ne pas modifier** — conservé pour consultation uniquement (référence, comparaison, historique).

- **`ffp3/`** : sous-projet historique. Scripts (`bin/`, `tools/`, `scripts/`) et doc utiles. Les doublons (src, config, templates, vendor) ont été supprimés — le code actif est dans `serveur/src/`. Voir section « Scripts FFP3 » ci-dessous.

- **Point d’entrée** : `public/index.php` (front controller unique).
- **Documentation détaillée** : voir [ffp3/README.md](ffp3/README.md) pour l’architecture, la configuration et les environnements PROD/TEST.

## Test local rapide

Pour tester rapidement le routage Slim, les templates et les assets sans Apache, utiliser le serveur intégré PHP avec le dossier `public/` comme document root :

```powershell
php -c "C:\php\php.ini" -S 127.0.0.1:8082 -t "c:\IOT_n3\serveur\public" "c:\IOT_n3\serveur\public\index.php"
```

Vérifications utiles :

- Accueil : `http://127.0.0.1:8082/`
- CSS principal : `http://127.0.0.1:8082/assets/css/main.css`
- Description aquaponie : `http://127.0.0.1:8082/aquaponie-description`
- Aquaponie : `http://127.0.0.1:8082/aquaponie`
- Aquaponie classique : `http://127.0.0.1:8082/aquaponie-alt`
- Page MSP1 : `http://127.0.0.1:8082/msp1/msp1datas/msp1-data.php`
- Page N3PP : `http://127.0.0.1:8082/n3pp/n3ppdatas/n3pp-data.php`

Prérequis locaux :

- `pdo_mysql` doit être activé dans `C:\php\php.ini`
- la commande PHP doit charger ce `php.ini`

Comportement local actuel :

- `pdo_mysql` reste nécessaire si l’on veut tester les vraies données MySQL
- sans base joignable, le serveur intégré active un fallback local pour `aquaponie`, `aquaponie-alt`, `msp1` et `n3pp`
- ce fallback rend les pages avec des séries vides et des valeurs neutres, ce qui permet de vérifier localement le HTML, Twig, les assets CSS/JS, les formulaires et le routage sans erreur `DB connection failed`
- l’accueil, `login`, les galeries et `aquaponie-description` restent aussi testables localement

## Scripts de déploiement et de test (FFP3)

Le dossier **`ffp3/`** est un sous-projet historique / archive. Il contient des scripts utiles (`bin/`, `tools/`, `scripts/`) et de la documentation. Les doublons (`ffp3/src/`, `ffp3/config/`, `ffp3/templates/`, `ffp3/vendor/`) ont été supprimés. Le code actif du serveur est dans `serveur/src/`, `serveur/config/`, `serveur/templates/`. Le point d'entrée réel est `public/index.php`.

Les scripts de déploiement, diagnostic et test liés à FFP3 se trouvent dans **`ffp3/`** :

- **`ffp3/bin/`** : `deploy.sh`, `DEPLOY_NOW.sh` (à la racine ffp3), `deploy_diagnostics.sh`, etc.
- **`ffp3/tools/`** : tests POST, diagnostic (quick_diagnostic.sh, test_post_data.sh, etc.).
- **`ffp3/scripts/`** : utilitaires (ex. copy_photos_aquaponie.ps1).

**À exécuter depuis la racine de `ffp3/`** (répertoire contenant `composer.json`), sauf indication contraire dans le script ou dans `ffp3/bin/README.md`.

## Configuration serveur (URLs /ffp3/*)

Pour eviter des 404 sur les URLs du type `https://iot.olution.info/ffp3/`, `/ffp3/dashboard`, etc., toutes les requetes doivent etre acheminees vers `public/index.php`. Voir [docs/CONFIG_SERVEUR_FFP3_URLS.md](docs/CONFIG_SERVEUR_FFP3_URLS.md) pour Apache (AllowOverride All) et Nginx.

## Diagnostic / Logs

- **Cronlog production** : [https://iot.olution.info/public/cronlog.txt](https://iot.olution.info/public/cronlog.txt) — log applicatif (Monolog) des erreurs et exceptions. À consulter pour retrouver une **référence d’erreur** (ex. `bb3262da436c`) affichée à l’utilisateur en cas d’erreur 500.
- **Processus de debug** : voir [docs/DEBUG_ERREURS_SERVEUR.md](docs/DEBUG_ERREURS_SERVEUR.md) pour le diagnostic à partir d’une référence et le lien avec `ErrorHandlerMiddleware` / `ErrorAlertService`.
