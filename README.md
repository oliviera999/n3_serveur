# Serveur unifié n3 IoT

Backend PHP (Slim 4) pour [iot.olution.info](https://iot.olution.info) : collecte des données (msp1, n3pp, ffp3, poissonglouton), contrôle des sorties, galeries photo.

- **`analyse-ffp3/`** : extrait de l’ancien sous-projet FFP3 conservé pour analyse (scripts `bin/`, `tools/`, doc). Le code actif est dans `src/`, `config/`, `templates/` ; les outils PHP de diagnostic sont dans **`tools/`**. Voir section « Scripts FFP3 » ci-dessous.

- **Point d’entrée** : `public/index.php` (front controller unique).
- **Documentation détaillée** : voir [analyse-ffp3/README.md](analyse-ffp3/README.md) pour l’architecture FFP3 et l’extrait à analyser, et l’index [docs/README.md](docs/README.md) pour la documentation technique.

## Module Poissonglouton (recyclage)

Le serveur expose un nouveau flux firmware pour le compteur de bouteilles :

- `POST /pgl/post-data` : ingestion batch des événements (firmware `poissonglouton`, dépôt externe).
- `POST /pgl/heartbeat` : supervision (uptime, heap, reboots) — flag firmware `PGL_ENABLE_SERVER_HEARTBEAT`.
- `GET /pgl` : page statistiques publique (menu « Poissonglouton », bandeau LIVE/HORS LIGNE).
- `GET /pgl/api/system/health` : API JSON pour le statut en ligne (poll accueil + page stats).

Voir le contrat complet dans `docs/ENDPOINTS_ESP32_SERVEUR.md`.

## Test local complet (Docker)

Pour tester le site completement en local (pages, controle, APIs, upload photo, BDD), utiliser la stack Docker fournie:

- `app` (PHP 8.2 + Slim, port `8082`)
- `db` (MySQL 8, port `3307` cote hote)
- `phpmyadmin` (port `8083`)

### Fichiers ajoutes pour le local

- `docker-compose.local.yml`
- `docker/php/Dockerfile`
- `docker/mysql/init/00-schema.sql`
- `docker/mysql/init/10-seed.sql`
- `.env.docker.example`
- `tools/local-docker.ps1`
- `tools/local-smoke-test.ps1`
- `tools/import-mysql-dump-to-local-docker.ps1` (optionnel : snapshot production vers `iot_n3_local`)
- `docker/mysql/sync-import-staging-to-local.sql` (synchro staging vers local, appele par le script ci-dessus)

### Demarrage rapide

Depuis `serveur/`:

```powershell
# 1) Démarrer la stack locale
powershell -ExecutionPolicy Bypass -File .\tools\local-docker.ps1 -Action up

# 2) Vérifier les conteneurs
powershell -ExecutionPolicy Bypass -File .\tools\local-docker.ps1 -Action ps

# 3) Lancer le smoke test HTTP/API/upload
powershell -ExecutionPolicy Bypass -File .\tools\local-docker.ps1 -Action smoke

# 4) Lancer PHPUnit dans le conteneur app
powershell -ExecutionPolicy Bypass -File .\tools\local-docker.ps1 -Action test
```

Suites Composer (depuis `serveur/`, hors ou dans le conteneur) :

- `composer test` : **Unit** + **Integration** (comportement par défaut, identique à `phpunit` sans filtre).
- `composer test:unit` : uniquement les tests hors `tests/Integration` (rapide, utile sans snapshot MySQL).
- `composer test:integration` : uniquement `tests/Integration` (BDD joignable ; assertions snapshot si import production effectué).

URLs locales:

- App: `http://127.0.0.1:8082/`
- phpMyAdmin: `http://127.0.0.1:8083/`
- MySQL hote: `127.0.0.1:3307` (db `iot_n3_local`, user `iot_n3_user`)

Notes:

- Smoke test : délai HTTP par défaut **60 s** par requête (`tools/local-smoke-test.ps1`, paramètre `-TimeoutSec`). Exemple : `powershell -ExecutionPolicy Bypass -File .\tools\local-smoke-test.ps1 -TimeoutSec 90`
- Smoke test : modes d’authentification **`AuthMode`** `token` / `session` / `both` (jeton admin, login session, ou les deux) ; option **`-RunNegativeAuthChecks`** pour vérifier les refus d’accès. Paramètres : `-AdminToken`, `-AdminUsername`, `-AdminPassword`, `-ApiKey`.
- Docker local (`.env.docker.example`) : `AUTH_METHOD=both`, jeton `local_admin_token_change_me`, mot de passe session **`localadmin`** — ex. `.\tools\local-smoke-test.ps1 -AuthMode both -AdminPassword localadmin -RunNegativeAuthChecks`
- Script `tools/verify_environments.php` : la section « connexion BDD » lit `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` depuis `.env` (Docker local : `DB_HOST=db`).
- Au premier lancement, le script copie `.env.docker.example` vers `.env` si absent.
- Le schema BDD est initialise automatiquement via `docker/mysql/init/`.
- Les donnees de seed evitent les erreurs de pages de controle (tables/rows minimales presentes).

### Importer un export MySQL de production (tests etendus)

Pour charger les memes donnees que la production dans `iot_n3_local` **sans creer de tables hors schéma local** : le dump brut est importe dans une base tampon `iot_n3_import_staging`, puis recopie vers les tables de `iot_n3_local` avec mappage des colonnes (ex. `Boards` en cle `board` varchar, `ffp3Heartbeat.timestamp` -> `reading_time`, `ffp3Data` sans `bootCount`/`mailSent`, `post_id` a NULL si absent).

**Confidentialite** : ne pas versionner le fichier `.sql` (e-mails, chemins serveur possibles).

```powershell
# Docker Desktop demarre ; stack up
powershell -ExecutionPolicy Bypass -File .\tools\local-docker.ps1 -Action up

# Chemin absolu vers l'export phpMyAdmin (ex.)
powershell -ExecutionPolicy Bypass -File .\tools\import-mysql-dump-to-local-docker.ps1 -DumpPath "C:\Users\olivi\Downloads\oliviera_iot.sql"

# Rejouer uniquement la synchro si la staging est deja chargee :
# powershell -ExecutionPolicy Bypass -File .\tools\import-mysql-dump-to-local-docker.ps1 -SyncOnly
```

Les tables presentes en production mais absentes du schéma local (`ffp3Data4`, `ffp3DataDel`, etc.) ne sont pas creees ; elles restent uniquement dans la staging jusqu'a suppression du conteneur ou `DROP DATABASE iot_n3_import_staging`.

Les tests PHPUnit sous `tests/Integration/` (classe de base `IntegrationDbTestCase`, volumétrie, repositories FFP3/MSP/N3PP/Boards) s'activent lorsque `ffp3Data` depasse un seuil (snapshot charge) ; `composer test` dans le conteneur `app` les execute avec les variables `DB_*` injectees par Docker.

Apres une synchro reussie, vous pouvez liberer l'espace disque du conteneur MySQL en supprimant la base tampon : `docker exec -e MYSQL_PWD=root_local_iot_n3 n3_iot_db_local mysql -uroot -e "DROP DATABASE IF EXISTS iot_n3_import_staging;"` (le script d'import la recreera au prochain import complet).

### Arret / logs / shell

```powershell
# Arrêt
powershell -ExecutionPolicy Bypass -File .\tools\local-docker.ps1 -Action down

# Logs
powershell -ExecutionPolicy Bypass -File .\tools\local-docker.ps1 -Action logs

# Shell dans le conteneur app
powershell -ExecutionPolicy Bypass -File .\tools\local-docker.ps1 -Action shell
```

## Test local rapide (serveur PHP intégré)

Pour tester rapidement le routage Slim, les templates et les assets sans Apache, utiliser le serveur intégré PHP avec le dossier `public/` comme document root :

```powershell
php -c "C:\php\php.ini" -S 127.0.0.1:8082 -t "c:\IOT_n3\serveur\public" "c:\IOT_n3\serveur\public\index.php"
```

Vérifications utiles :

- Navigation : barre horizontale au-dessus de 980px de largeur ; en dessous, bouton menu (sandwich) et panneau latéral — styles et accessibilité (ARIA, focus) sont centralisés dans `public/assets/css/main.css`, `theme-variables.css` et `public/assets/js/main.js` ; les entrées optionnelles depuis la supervision utilisent `page-nav-toggles.js`.
- Accueil : `http://127.0.0.1:8082/`
- CSS principal : `http://127.0.0.1:8082/assets/css/main.css`
- Description aquaponie : `http://127.0.0.1:8082/aquaponie-description`
- Description MSP1 (météo) : `http://127.0.0.1:8082/meteo-description`
- Description N3PP (serre) : `http://127.0.0.1:8082/serre-description`
- Aquaponie : `http://127.0.0.1:8082/aquaponie`
- Aquaponie classique : `http://127.0.0.1:8082/aquaponie-alt`
- Données MSP1 (météo) : `http://127.0.0.1:8082/meteo`
- Données N3PP (serre) : `http://127.0.0.1:8082/serre`
- Contrôle galerie MSP1 : `http://127.0.0.1:8082/gallery/msp1/control`
- Contrôle galerie N3PP : `http://127.0.0.1:8082/gallery/n3pp/control`
- Contrôle galerie FFP3 : `http://127.0.0.1:8082/gallery/ffp3/control`

Prérequis locaux :

- `pdo_mysql` doit être activé dans `C:\php\php.ini`
- la commande PHP doit charger ce `php.ini`

Comportement local actuel :

- `pdo_mysql` reste nécessaire si l’on veut tester les vraies données MySQL
- sans base joignable, le serveur intégré active un fallback local pour `aquaponie`, `aquaponie-alt`, `msp1` et `n3pp`
- ce fallback rend les pages avec des séries vides et des valeurs neutres, ce qui permet de vérifier localement le HTML, Twig, les assets CSS/JS, les formulaires et le routage sans erreur `DB connection failed`
- l’accueil, `login`, les galeries , `aquaponie-description`, `meteo-description` et `serre-description` restent aussi testables localement

## Scripts de déploiement et de test (FFP3)

Le dossier **`analyse-ffp3/`** contient un **extrait utile** (scripts bin/tools, doc) de l’ancien sous-projet FFP3. Le code actif du serveur est dans `src/`, `config/`, `templates/` ; le point d’entrée réel est `public/index.php`. Les **outils PHP** de diagnostic (vérification tables, environnements, etc.) sont dans **`tools/`** (versions de référence avec .env).

Scripts FFP3 (extrait dans **`analyse-ffp3/`**) :

- **`analyse-ffp3/bin/`** : `deploy.sh`, `deploy_diagnostics.sh`, `deploy_endpoints.ps1`, etc.
- **`analyse-ffp3/tools/`** : scripts .sh/.ps1 de test POST, diagnostic (quick_diagnostic.sh, test_post_data.sh, etc.).
- **`analyse-ffp3/scripts/`** : ex. copy_photos_aquaponie.ps1.

Pour les scripts PHP de diagnostic (tables, environnements), utiliser **`tools/`** à la racine du serveur.

## Maintenance du changelog (durable)

Le dépôt applique une stratégie "rolling window" :

- `CHANGELOG.md` reste court (entrées récentes) ;
- l'historique est archivé dans `docs/changelog/archive/` ;
- un garde-fou `pre-commit` peut bloquer les anomalies de changelog avant commit.

Commandes utiles depuis `serveur/` :

```powershell
# Vérification stricte (UTF-8, doublons, taille)
composer changelog:check

# Rotation des anciennes entrées vers l'archive
composer changelog:rotate
```

Installation du hook local :

```bash
bash bin/install-hook-pre-commit.sh
```

Documentation : `docs/changelog/README.md`

## Configuration serveur (URLs /ffp3/*)

Pour eviter des 404 sur les URLs du type `https://iot.olution.info/ffp3/`, `/ffp3/dashboard`, etc., toutes les requetes doivent etre acheminees vers `public/index.php`. Voir [docs/CONFIG_SERVEUR_FFP3_URLS.md](docs/CONFIG_SERVEUR_FFP3_URLS.md) pour Apache (AllowOverride All) et Nginx.

## Diagnostic / Logs

- **Cronlog production** : [https://iot.olution.info/public/cronlog.txt](https://iot.olution.info/public/cronlog.txt) — log applicatif (Monolog) des erreurs et exceptions. À consulter pour retrouver une **référence d’erreur** (ex. `bb3262da436c`) affichée à l’utilisateur en cas d’erreur 500.
- **Rotation et PII** (v5.1.0) : `RotatingFileHandler` actif (14 jours configurables via `LOG_ROTATE_DAYS`), masquage IP par défaut (`LOG_MASK_IP=true` — IPv4 `.0`, IPv6 `::/80`). Désactivable via `.env` pour les diagnostics fins.
- **Processus de debug** : voir [docs/DEBUG_ERREURS_SERVEUR.md](docs/DEBUG_ERREURS_SERVEUR.md) pour le diagnostic à partir d’une référence et le lien avec `ErrorHandlerMiddleware` / `ErrorAlertService`.

## Tâches CRON

Deux crons sur le serveur de production :

1. **Déploiement** (toutes les minutes) : `git pull origin master` + hook `post-merge` (vidage caches).
2. **Applicatif FFP3** (toutes les 5 minutes) : `php run-cron.php` → `CronOrchestrator`.

Référence complète (crontab, variables `.env`, dépannage) : [docs/deployment/CRON.md](docs/deployment/CRON.md).

## Sécurité (v5.1.0)

- **HMAC** : firmwares FFP3/MSP/N3PP peuvent envoyer `timestamp + signature` (HMAC-SHA256). FFP5CS v13.80+ peut aussi envoyer `X-Sig-Timestamp`, `X-Sig-Nonce`, `X-Sig-Hmac` avec message canonique `<timestamp>\n<nonce>\n<body_brut>`. Modes :
  - défaut : fallback `API_KEY` si HMAC absent ;
  - `HMAC_STRICT_MODE=true` : refuse l'absence de HMAC ;
  - `HMAC_NONCE_REQUIRED=true` : exige `post_id` (nonce) — message canonique `<timestamp>|<post_id>`.
- **OTA** : `OTA_REQUIRE_AUTH=true` (`.env`) protège `/ota/*` et `/ffp3/ota/*` (header `X-Api-Key` / `X-OTA-Key` ou `?api_key=`). Désactivé par défaut (compat firmwares).
- **CSP / HSTS** : `SecurityHeadersMiddleware` injecte Content-Security-Policy, Permissions-Policy, HSTS (auto sur `X-Forwarded-Proto: https` ou `SECURITY_FORCE_HSTS=true`).
- **Galeries** : rate-limit upload `GALLERY_UPLOAD_RATE_LIMIT_SECONDS` (défaut 10 s/IP), code `HTTP 429` si dépassé.
- **Sessions** : `cookie_samesite=Lax`, `cookie_secure` si HTTPS détecté (direct ou via reverse-proxy `X-Forwarded-Proto`).
- **Rotation API key** : voir [docs/SECURITE_ROTATION_API_KEY.md](docs/SECURITE_ROTATION_API_KEY.md).

## Qualité du code (v5.1.0)

- **PHPStan** : `composer analyse` (niveau 5, config `phpstan.neon`).
- **PHP-CS-Fixer** : `composer cs:check` / `composer cs:fix` (PSR-12 + règles modérées, config `.php-cs-fixer.php`).
- **Audit dépendances** : `composer audit` (+ `roave/security-advisories` en require-dev).
- **Tests PHPUnit** : `composer test` (Unit + Integration), `composer test:unit`, `composer test:integration`.
