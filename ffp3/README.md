# FFP3 Datas – Plate-forme Aquaponie & IoT

Application PHP 8.1+ pour la supervision complète d'un système d'aquaponie piloté par ESP32 : collecte des mesures, visualisation en temps réel, contrôle des actionneurs et automatisations planifiées.

> Framework : **Slim 4** · Templates : **Twig** · Logs : **Monolog** · DI : **PHP-DI** · Tests : **PHPUnit**

---

## Sommaire

1. [Fonctionnalités principales](#fonctionnalités-principales)  
2. [Architecture & dossiers](#architecture--dossiers)  
3. [Installation rapide](#installation-rapide)  
4. [Configuration `.env`](#configuration-env)  
5. [Gestion des environnements PROD/TEST](#gestion-des-environnements-prodtest)  
6. [Lancement & scripts utiles](#lancement--scripts-utiles)  
7. [Tâches CRON](#tâches-cron)  
8. [Tests & qualité](#tests--qualité)  
9. [Documentation associée](#documentation-associée)  
10. [Support & maintenance](#support--maintenance)

---

## Fonctionnalités principales

- **Ingestion sécurisée des données capteurs** (`POST /post-data*`) avec double protection : clé API legacy et signature HMAC-SHA256.
- **Dashboard interactif** (Highcharts) : filtres temporels, exports CSV, courbes personnalisables.
- **Surveillance aquaponie** : statistiques eau/aquarium/potager, alerte niveau bas, marée et analyse de tendance.
- **Contrôle des GPIO** via interface web (`/aquaponie-control*`, `/aquamobile-control*`) et API temps réel (synchronisation ESP32 ↔ serveur).
- **Automatisations planifiées** : commandes Slim (`CleanDataCommand`, `ProcessTasksCommand`, etc.) protégées par verrou `flock`.
- **Logging centralisé** : `LogService` (Monolog) configurable via `.env`.
- **Stack prête pour la production** : configuration par environnement, cache Twig, services injectés par container.

---

## Architecture & dossiers

**Racine du projet** : dossier qui contient `public/`, `src/`, `templates/`, etc.  
Sur une machine de développement typique : `C:\ffp5cs\ffp3` (ou le chemin où le dépôt a été cloné).

```
├── public/              # Front controller Slim (index.php, assets exposés)
│   └── assets/
│       └── images/
│           └── aquaponie-description/   # Photos page Caractéristiques (copiées depuis photos aquaponie/)
├── photos aquaponie/    # Photos sources pour la page Caractéristiques (script scripts/copy_photos_aquaponie.ps1)
├── src/
│   ├── Config/          # Env, PDO, TableConfig, dépendances container
│   ├── Controller/      # Routes HTTP (Slim)
│   ├── Domain/          # DTO & objets métiers
│   ├── Repository/      # Accès MySQL (PDO + requêtes préparées)
│   ├── Service/         # Logique métier (stats, pompes, notifications…)
│   └── Command/         # Jobs CLI/CRON (php bin/*)
├── templates/           # Vues Twig (Bootstrap 5 + Highcharts)
├── tests/               # Tests unitaires PHPUnit
├── docs/                # Documentation détaillée & archives
├── VERSION              # Numéro de version (affiché dans l'UI)
└── CHANGELOG.md         # Historique des évolutions
```

Voir `docs/README.md` pour l'index complet de la documentation.

---

## Installation rapide

### Prérequis

- PHP ≥ 8.1 avec extensions : `pdo_mysql`, `mbstring`, `json`, `openssl`
- Composer 2.x
- MySQL/MariaDB ≥ 5.7
- Accès shell (bash ou PowerShell) sur le serveur

### Étapes

```bash
# 1. Cloner le dépôt
git clone https://github.com/<organisation>/ffp3datas.git
cd ffp3datas

# 2. Installer les dépendances
composer install            # En développement (avec dev-tools)
composer install --no-dev   # En production

# 3. Configurer l'environnement
cp .env.dist .env
# Éditer .env (DB, API, timezone, GPIO, email…)

# 4. Créer les répertoires de cache (si nécessaires)
mkdir -p var/cache/twig var/cache/di

# 5. Préparer la base de données
# Importer les tables (cf. migrations/ et CREATE_TEST_TABLES.sql)
```

### Vérifications rapides

```bash
# Charger l'environnement et vérifier la configuration
echo "<?php require 'vendor/autoload.php'; App\\Config\\Env::load(); echo 'OK';" | php

# Lancer la suite de tests (optionnel)
./vendor/bin/phpunit
```

---

## Configuration `.env`

⚠️ Contrairement aux pratiques usuelles, le fichier `.env` **est versionné**. Mettez à jour les secrets sensibles si nécessaire avant chaque déploiement.

| Variable | Description |
|----------|-------------|
| `ENV` | Environnement par défaut (`prod` / `test`) pour `TableConfig` |
| `APP_TIMEZONE` | Fuseau horaire global (défaut : `Europe/Paris`) |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Connexion MySQL/MariaDB |
| `API_KEY` | Clé API legacy utilisée par certains ESP32 |
| `API_SIG_SECRET`, `SIG_VALID_WINDOW` | Signature HMAC-SHA256 + fenêtre de validité |
| `LOG_FILE_PATH` | Chemin du fichier de logs Monolog (ex. `cronlog.txt`) |
| `GPIO_*` | Mapping des broches pour `PumpService` |
| `MAIL_FROM`, `NOTIF_EMAIL_RECIPIENT` | Configuration NotificationService |

> Le fuseau horaire est appliqué automatiquement par `Env::load()`. **Ne jamais** appeler `date_default_timezone_set()` ailleurs dans le code.

---

## Gestion des environnements PROD/TEST

- Les deux environnements partagent le **même code** mais pas les **mêmes tables**.
- `TableConfig::getEnvironment()` et `TableConfig::getDataTable()` doivent être utilisés partout (pas de noms de table en dur).
- Routes dédiées : `/aquaponie` vs `/aquaponie-test`, `/post-data` vs `/post-data-test`, etc.
- `ENV` dans `.env` indique l'environnement par défaut, surchargé si l'URL contient `-test`.
- Guides détaillés : `ENVIRONNEMENT_TEST.md` et `ESP32_GUIDE.md`.

Résumé des tables :

| Environnement | Données | Outputs |
|---------------|---------|---------|
| PROD          | `ffp3Data` | `ffp3Outputs` |
| TEST          | `ffp3Data2` | `ffp3Outputs2` |

---

## Lancement & scripts utiles

```bash
# Dev : serveur PHP intégré
php -S localhost:8080 -t public

# Déploiement automatisé (exemples)
./deploy-and-test.sh          # Bash
pwsh ./deploy-and-test.ps1    # PowerShell
```

Script `deploy-and-test.*` : vérifie les pages clés, API temps réel, endpoints ESP32 et routes TEST.

---

## Tâches CRON

```
*/5 * * * * php /var/www/ffp3datas/bin/clean-data.php
0  * * * * php /var/www/ffp3datas/bin/process-tasks.php
```

- Les commandes Slim sont verrouillées (`flock`) pour éviter les chevauchements.
- Les logs d'exécution sont centralisés dans `cronlog.txt` (configurable via `.env`).
- Scripts d'assistance dans `bin/` et `tools/` pour diagnostics (HTTP 500, synchronisation GPIO, etc.).

---

## Tests & qualité

```bash
# Exécuter les tests unitaires
./vendor/bin/phpunit

# (Optionnel) Analyse statique
./vendor/bin/phpstan analyse src
```

- Les tests visent la logique métier (services, repositories, sécurité).
- Pensez à regénérer les données de test et à paramétrer la base TEST avant d'exécuter les suites.

---

## Documentation associée

| Fichier | Contenu |
|---------|---------|
| `CHANGELOG.md` | Historique complet des versions (suivre SEMVER) |
| `docs/README.md` | Index de toute la documentation (guides, archives, diagnostics) |
| `ENVIRONNEMENT_TEST.md` | Procédures PROD/TEST, mapping des routes |
| `ESP32_GUIDE.md` | Intégration complète ESP32, exemples de code |
| `TIMEZONE_MANAGEMENT.md` | Notes sur l'unification des fuseaux horaires |
| `TODO_AMELIORATIONS_CONTROL.md` | Suivi des évolutions de l'UI de contrôle |

> Après **chaque modification** : mettre à jour `VERSION`, consigner dans `CHANGELOG.md`, vérifier l'affichage de la version dans l'interface.

---

## Support & maintenance

- **Logs** : consulter `cronlog.txt`, `error_log`, ou les diagnostics dans `docs/`.
- **Diagnostic rapide** : scripts `bin/` et `tools/` (ex. `tools/diagnostic_500_errors.php`).
- **Questions** : se référer aux rapports d'audit (`docs/archive/diagnostics`) ou contacter l'équipe O-Lution.
- **Licence** : MIT – © 2024-2025 O-Lution. Utilisation libre avec attribution.

---

**Version actuelle** : voir `VERSION`