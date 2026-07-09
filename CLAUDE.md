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
- ✅ Libre de s'inspirer de dépôts GitHub connus / bibliothèques éprouvées — **en citant la source** (voir ci-dessous).

### Inspiration — bonnes pratiques externes (encouragé, avec citation)

Voir le skill [`external-inspiration`](.claude/skills/external-inspiration/SKILL.md).

Tu es **libre de t'inspirer d'excellentes pratiques** décrites dans des dépôts GitHub connus et
accessibles ou des bibliothèques éprouvées (ex. patterns Slim/Symfony, PSR, projets PHP de
référence sur l'auth/HMAC, la sécurité, l'observabilité…). C'est une source d'inspiration utile
pour la qualité et la robustesse du code.

**Conditions obligatoires :**

1. **Citer la source** dès que tu t'inspires d'un projet ou reprends une approche/du code : nom du
   projet ou de la bibliothèque **+ lien** (et version/commit si pertinent). La citation va dans le
   **message de commit / la description de PR**, et — si l'emprunt est localisé — en **commentaire de
   code** (docblock) au-dessus du passage concerné.
2. **Respecter les licences** : ne pas copier-coller du code sous licence incompatible ; adapter/réécrire
   et mentionner licence + origine. En cas de doute sur la compatibilité, demander avant d'intégrer.
   Préférer une **dépendance Composer** propre à un copier-coller quand la lib existe.
3. **Adapter, ne pas plaquer** : rester cohérent avec les conventions du dépôt (Slim/Twig/PDO,
   `TableConfig`, PSR-4, `strict_types`) plutôt que dupliquer un pattern externe tel quel.

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

## Cohérence inter-PR — anti-conflit de merge (OBLIGATOIRE)

> Le bump de version touche **toujours les mêmes lignes** (`VERSION`, haut de `CHANGELOG.md`).
> Deux PR ouvertes en parallèle qui bumpent chacune → **conflit de merge garanti**. Cette règle
> l'évite en imposant une vérification croisée à **chaque publication ou mise à jour d'une PR**.

**À chaque fois que tu ouvres, mets à jour (push) ou réactualises une PR de ce dépôt :**

1. **Lister les autres PR ouvertes** du dépôt (`mcp__github__list_pull_requests`, state `open`) et
   repérer celles qui touchent les **fichiers de versionnage sensibles** :
   - `VERSION` (racine)
   - `CHANGELOG.md` (surtout l'entrée en tête / section `[Non publié]`)
   - toute migration `migrations/NNN_*.sql` (collision de **numéro** `NNN`)
2. **Détecter les collisions** : même numéro de version cible, même ligne de `CHANGELOG.md`
   modifiée, ou même numéro de migration `NNN`.
3. **Résoudre AVANT de (re)pousser** :
   - Rebaser la branche sur le dernier `master` (`git fetch origin master && git rebase origin/master`).
   - Prendre le **numéro de version suivant encore libre** (ne pas réutiliser celui d'une autre PR
     déjà ouverte) et re-bumper via le skill [`bump-version`](.claude/skills/bump-version/SKILL.md).
   - Ajouter l'entrée `CHANGELOG.md` en **nouvelle ligne** sous `[Non publié]` (ne pas écraser
     l'entrée d'une autre PR) ; renuméroter une migration si `NNN` est déjà pris ailleurs.
4. **Ne fusionner qu'une PR versionnante à la fois** ; après un merge, rebaser/rebumper les autres
   PR ouvertes plutôt que de les fusionner à l'aveugle.

> 💡 En pratique : garde le bump de version **le plus tard possible** dans la vie d'une PR
> (juste avant merge) pour minimiser la fenêtre de conflit.

## Documentation de référence

`docs/README.md` (index), `docs/TIMEZONE_MANAGEMENT.md`, `docs/AUTHENTICATION.md`,
`docs/ENDPOINTS_ESP32_SERVEUR.md`, `docs/deployment/` (déploiement, CRON, hooks).
