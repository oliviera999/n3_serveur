# CLAUDE.md

Guide pour Claude Code (et tout agent) travaillant sur **FFP3 Datas** — plateforme PHP de
supervision de systèmes IoT (aquaponie, serre/élevage, station météo) basés sur ESP32.

> 🔗 Ce dépôt contient aussi un fichier [`.cursorrules`](.cursorrules) (assistant Cursor).
> Les deux fichiers doivent rester cohérents : **toute règle métier modifiée ici doit l'être
> aussi dans `.cursorrules`** (et inversement). `.cursorrules` reste la référence détaillée ;
> ce `CLAUDE.md` en est la version opérationnelle pour Claude Code.

## Commandes essentielles

```bash
composer install                     # Dépendances (dev : PHPUnit, PHPStan, php-cs-fixer)

# Qualité — à lancer AVANT chaque commit (équivalent local de la CI)
composer cs:check                    # Style (php-cs-fixer, dry-run)
composer cs:fix                      # Corrige le style automatiquement
composer analyse                     # PHPStan niveau 6
composer audit                       # Vulnérabilités des dépendances

# Tests
composer test:unit                   # Suite unitaire
composer test:integration            # Suite d'intégration (nécessite MySQL)
composer test                        # Toutes les suites

# Serveur de dev
php -S localhost:8080 -t public
```

> 💡 La CI (`.github/workflows/ci.yml`) exécute `cs:check`, `analyse`, les tests et
> `composer audit`. Faire passer ces commandes en local = PR verte. Un skill
> [`qa-gate`](.claude/skills/qa-gate/SKILL.md) enchaîne tout cela.

## Architecture

Application **Slim 4** + **Twig** (Bootstrap 5) + **Monolog**, **MySQL/MariaDB via PDO** (pas d'ORM).
Namespace racine PSR-4 : `App\` (voir `composer.json`). PHP **8.1+**, `declare(strict_types=1)`.

```
public/            Front-controller Slim (point d'entrée web)
src/
  Config/          Env, Database (PDO), TableConfig, Version, *GpioMap…
  Controller/      Endpoints HTTP (par famille : Ffp3/, Msp/, N3pp/)
  Domain/          DTO métier (ex : SensorData)
  Middleware/      Middlewares Slim (dont bascule d'environnement par requête)
  Repository/      Accès données (PDO, requêtes préparées)
  Security/        AuthService, CsrfService, RateLimiter, SignatureValidator (HMAC)…
  Service/         Services métier (Realtime/ pour le live)
  Util/            Utilitaires transverses
  Command/         Jobs CRON (CronOrchestrator + sous-commandes)
templates/         Vues Twig
migrations/        Migrations SQL
docs/              Documentation technique (voir docs/README.md)
VERSION            Version SemVer (lue par App\Config\Version)
CHANGELOG.md       Journal des modifications
```

## Environnements et tables (POINT CRITIQUE)

Le projet supervise **3 familles d'appareils**, chacune avec ses tables :
- **FFP3** (aquaponie) : `ffp3Data`, `ffp3Outputs`, `ffp3Heartbeat`
- **N3PP** (serre / élevage) : `n3ppData`, `n3ppOutputs`, `n3ppHeartbeat`
- **MSP1** (station météo) : `msp1Data`, `msp1Outputs`, `msp1Heartbeat`

> 🏷️ **Nomenclature `ffp3` vs `ffp5cs`** : la famille aquaponie s'appelle **`ffp3`** ici (tables,
> routes `/post-data*`, dossier `Ffp3/`) ; **`ffp5cs`** est le nom du *firmware* qui l'alimente
> (dépôt n3_firmwires) — il n'existe dans aucun identifiant de code serveur. Le firmware s'identifie
> par la **route** (jamais par le champ `sensor`, seulement journalisé). Depuis firmware v15.09,
> `sensor="ffp3"`. Le mot « ffp3 » recouvre aussi la galerie caméra (`/ffp3gallery/`) et le
> sous-module firmware `ffp5cs/ffp3` (= ce dépôt). Détails : **`docs/NOMENCLATURE_FFP3.md`**.

`ENV` (dans `.env`) choisit l'environnement par défaut parmi
`prod, test, test3, s3, s3test, n3pp_test, msp_test` (défaut `prod`).
Les suffixes de table en dépendent (ex. FFP3 : `ffp3Data` en prod, `ffp3Data2` en `test`,
`ffp3DataS3` en `s3`…). ⚠️ `s3` est de la **production** (`TableConfig::isTest()` → `false`).

**Toujours** passer par `App\Config\TableConfig` — jamais de nom de table en dur :
`getDataTable()`, `getOutputsTable()`, `getHeartbeatTable()`, variantes
`getN3pp*` / `getMsp*`, et `setEnvironment()` / `resetRequestEnvironment()` pour la bascule
par requête (gérée par le middleware, sans muter `$_ENV`).

## Conventions & règles non négociables

- ❌ **Jamais** de nom de table en dur → `TableConfig`.
- ❌ **Jamais** `date_default_timezone_set()` dans un contrôleur → timezone centralisé
  (`APP_TIMEZONE=Europe/Paris`, configuré par `Env::load()`). Voir `docs/TIMEZONE_MANAGEMENT.md`.
- ❌ **Jamais** `mail()` direct → `NotificationService`.
- ❌ **Jamais** de connexion PDO manuelle → `App\Config\Database::getConnection()`.
- ❌ **Jamais** muter `$_ENV['ENV']` pour changer d'environnement → `TableConfig::setEnvironment()`.
- ❌ **Jamais** d'endpoint POST d'API sans validation de signature (`SignatureValidator` / HMAC-SHA256).
- ✅ PDO + requêtes préparées, logique d'accès dans les `Repository`.
- ✅ Respecter `.php-cs-fixer.php` et `phpstan.neon`.

### Sécurité de l'API ESP32 (`/post-data`)
Authentification par `api_key` (legacy) ET/OU signature **HMAC-SHA256**
(`API_KEY`, `API_SIG_SECRET`, `SIG_VALID_WINDOW` dans `.env`). Le `.env` n'est **pas** versionné
(seuls les `.env.example` le sont). Voir `docs/AUTHENTICATION.md`.

## Versionnage — OBLIGATOIRE après chaque modification significative

À chaque feature / bugfix / amélioration (voir le skill [`bump-version`](.claude/skills/bump-version/SKILL.md)) :
1. **Incrémenter** `VERSION` (racine) en SemVer : MAJOR (breaking) / MINOR (feature) / PATCH (fix).
2. **Documenter** dans `CHANGELOG.md` (numéro de version, date, description).
3. La version est exposée par `App\Config\Version` (`get()`, `getWithPrefix()`, `getFullName()`)
   et doit rester visible sur les pages — **ne pas coder la version en dur** dans les templates.

## CRON

Point d'entrée unique `run-cron.php` → `App\Command\CronOrchestrator` (crontab **toutes les minutes** —
le serveur est l'émetteur primaire des alertes, latence ≤ 1 min), verrou `flock` (runs chevauchés ignorés).
Sous-commande `RestartPumpCommand` (redémarrage pompe différé 5 min, délai horodaté). Alertes dérivées du
POST chaque minute (`src/Service/DerivedAlert/`). Voir `docs/deployment/CRON.md`.

## Workflow recommandé

1. Développer/tester sur un environnement de **TEST** (`/aquaponie-test`, tables `*2`).
2. **Bumper la version** (`VERSION` + `CHANGELOG.md`).
3. Lancer le **qa-gate** (cs:check, analyse, tests, audit) — tout doit être vert.
4. Commit / PR. La PROD partage routes et code.

## Documentation de référence

`docs/README.md` (index), `docs/TIMEZONE_MANAGEMENT.md`, `docs/AUTHENTICATION.md`,
`docs/ENDPOINTS_ESP32_SERVEUR.md`, `docs/deployment/` (déploiement, CRON, hooks).
