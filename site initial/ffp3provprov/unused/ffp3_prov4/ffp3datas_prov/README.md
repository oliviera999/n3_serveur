# FFP3 Datas – Plate-forme Aquaponie & IoT

Une petite application PHP 8 dédiée au suivi d’un système d’aquaponie : collecte des mesures via ESP32, supervision depuis un tableau de bord et tâches CRON pour l’entretien automatique.

> Framework : **Slim 4** – Rendu : **Twig** – Logs : **Monolog** – Tests : **PHPUnit**

---

## Sommaire

1. [Fonctionnalités](#fonctionnalités)  
2. [Structure du projet](#structure-du-projet)  
3. [Installation rapide](#installation-rapide)  
4. [Configuration `.env`](#configuration-env)  
5. [Lancement](#lancement)  
6. [Tâches CRON](#tâches-cron)  
7. [Tests & Qualité](#tests--qualité)  
8. [Roadmap & Idées](#roadmap--idées)  
9. [Licence](#licence)

---

## Fonctionnalités

* API POST sécurisée (clé API + option HMAC) pour enregistrer les relevés capteurs.
* Tableau de bord interactif Highcharts / Twig –– filtrage par période, export CSV.
* Supervision : détection niveau d’eau, panne marée, système offline.
* Services dédiés : statistiques SQL, commande des pompes (GPIO), notifications e-mail.
* CRONs PHP (`CleanDataCommand`, `ProcessTasksCommand`) verrouillés par *flock*.
* Couverture unitaire (Signature, SensorData cleaning, etc.).

## Structure du projet

```
├── public/              # Front-controller Slim
│   └── index.php
├── src/
│   ├── Config/          # Chargement .env, connexion PDO
│   ├── Controller/      # Endpoints HTTP (Slim callbacks)
│   ├── Domain/          # DTO métier
│   ├── Repository/      # Accès BD (PDO)
│   ├── Service/         # Log, Statistiques, Pompes, Notifications…
│   └── Command/         # Jobs CRON exécutables via `php`
├── templates/           # Vues Twig (Bootstrap 5)
├── tests/               # PHPUnit
├── .env.dist            # Exemple de configuration
└── composer.json
```

## Installation rapide

1. Pré-requis : PHP ≥ 8.1, Composer, MySQL / MariaDB.

```bash
# Clonage
$ git clone https://github.com/<org>/ffp3datas.git
$ cd ffp3datas

# Dépendances
$ composer install --no-dev   # ou sans --no-dev en dev
```

2. Copiez le fichier d’exemple puis ajustez :

```bash
$ cp .env.dist .env
$ nano .env        # hote BD, user, pass, clés API…
```

3. Créez la base et les tables (exemple SQL dans `database/ffp3_schema.sql`).

## Configuration `.env`

| Variable | Rôle |
|----------|------|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Connexion MySQL |
| `API_KEY` | Clé API legacy (ESP32) |
| `API_SIG_SECRET` | Secret HMAC SHA-256 |
| `SIG_VALID_WINDOW` | Fenêtre en secondes (déf. 300) |
| `GPIO_POMPE_AQUA`, `GPIO_POMPE_TANK`, `GPIO_RESET_MODE` | # GPIO (int) |
| `AQUA_LOW_LEVEL_THRESHOLD` | Seuil niveau eau (cm/%) |
| `TIDE_STDDEV_THRESHOLD` | Seuil écart-type marées |
| `LOG_FILE_PATH` | Fichier de log Monolog (déf. `cronlog.txt`) |
| `NOTIF_EMAIL_RECIPIENT` | Destinataire alertes |
| `MAIL_FROM` | Adresse expéditeur |

## Lancement

```bash
# Dev : serveur PHP intégré
$ php -S localhost:8080 -t public

# Production : vhost Apache/Nginx pointant vers public/
```

Routes principales :

* `GET  /` ou `/dashboard` – Tableau de bord général
* `GET|POST /aquaponie` – Page Aquaponie + export CSV
* `POST /post-data` – Point d’ingestion capteurs (voir API ci-dessous)

### API POST `/post-data`

Corps `application/x-www-form-urlencoded` attend les champs décrits dans `SensorData`.  
Authentification : `api_key` **et/ou** `timestamp + signature` (HMAC-SHA256).  
Réponse : *text/plain* (200 OK ou 40x/500).

## Tâches CRON

```
*/5 * * * * php /var/www/ffp3datas/bin/clean-data.php
0 * * * *   php /var/www/ffp3datas/bin/process-tasks.php
```

*Les scripts wrapper dans `bin/` appellent respectivement `CleanDataCommand` et `ProcessTasksCommand`.*
Chaque commande écrit son PID dans `/tmp/*.lock` afin d’éviter un chevauchement.

## Tests & Qualité

```bash
# Exécution des tests
$ composer require --dev phpunit/phpunit
$ ./vendor/bin/phpunit

# Analyse statique (optionnel)
$ composer require --dev phpstan/phpstan
$ ./vendor/bin/phpstan analyse src
```

CI : un fichier `ci.yml` GitHub Actions est recommandé pour lancer PHPUnit + PHPStan automatiquement.

## Roadmap & Idées

* Remplacer `mail()` par **Symfony Mailer** ou **PHPMailer** + SMTP.
* Ajouter **PHPStan niveau 6**, **Psalm** et un *pre-commit hook*.
* Conteneur DI (Slim-Psr11, PHP-DI ou Symfony Container) pour éliminer les `new` manuels.
* Spécification **OpenAPI/Swagger** de l’API `/post-data` + client Postman.
* Dockerfile + docker-compose avec MariaDB & MailHog pour faciliter la démo.

## Licence

MIT – © 2024 O-Lution – utilisation libre sous réserve de conserver ce fichier de licence.