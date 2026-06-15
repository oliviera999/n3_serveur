# Tests d'intégration HTTP — panneau de contrôle FFP3 (toggle / parameters / state)

Suite d'intégration **de bout en bout** qui exerce les endpoints de contrôle
des GPIO/outputs FFP3 contre une **vraie base MySQL** (via Docker), en passant
par le **vrai front-controller Slim** (`public/index.php`).

Contrairement aux tests unitaires (`tests/Service`, `tests/Controller`, …) qui
mockent les dépendances, cette suite :

- démarre le front-controller réel avec un serveur PHP intégré (`php -S`) ;
- envoie de vraies requêtes HTTP (cURL) sur les routes `*-test` (env `test`) ;
- vérifie l'effet **persisté en base** via une connexion PDO directe.

Fichiers :

| Fichier | Rôle |
| --- | --- |
| `tests/Integration/Http/OutputControlHttpIntegrationTest.php` | La suite PHPUnit |
| `tests/Integration/Http/schema.sql` | Schéma MySQL minimal de test (idempotent) |
| `docker-compose.integration.yml` | Service MySQL dédié (port 3310) |

## Ce qui est couvert

| Test | Endpoint | Vérifie |
| --- | --- | --- |
| `testGetStateReturns200WithGpioStructure` | `GET /api/outputs-test/state` | 200 + JSON, présence des GPIO critiques (2,15,16,18,…), clé `dataStates` |
| `testToggleChangesStateInDatabaseAndReturnsOk` | `POST /api/outputs-test/toggle` | `{status:ok,id,state}` + `state=1` **persisté** + `lastModifiedBy=web` |
| `testToggleInvalidStateReturns400AndDoesNotPersist` | `POST …/toggle` (state=2) | 400 `{status:error}`, aucune écriture |
| `testToggleWithoutTokenIsForbiddenByCsrf` | `POST …/toggle` sans jeton | 403 (CSRF), aucune écriture |
| `testUpdateParametersPersistsValues` | `POST /api/outputs-test/parameters` | 200, `updated>=2`, valeurs **persistées** |
| `testUpdateParametersRejectsOutOfRangeFeedingHour` | `POST …/parameters` (`bouffeMatin=99`) | 400 (validation horaire), aucune écriture |

### Authentification & CSRF

Les écritures (`toggle`, `parameters`) sont protégées par l'auth et par le
`CsrfMiddleware`. Les requêtes de test portent un jeton d'admin hors-bande via
`?token=...` (variable `ADMIN_TOKEN`). Ce jeton :

- **authentifie** la requête (`AuthService::isAuthenticatedByToken`) ;
- **exempte** la requête de CSRF — un secret non-ambiant n'est pas falsifiable en
  cross-site (cf. `CsrfMiddleware`).

Le cas « sans jeton » prouve à l'inverse que la protection CSRF répond bien 403.

## Skip propre (CI sans MySQL)

Comme les autres `tests/Integration/*`, la suite **se skip proprement** si la BDD
de test n'est pas joignable (variables `DB_*` absentes, MySQL down, ou extension
`pdo_mysql` manquante). Aucun test ne passe en **erreur** faute de base : la suite
complète reste **verte**, seul le compteur `skipped` augmente.

```
$ composer test:integration-http        # sans MySQL
SSSSSS  6 / 6 (100%)
OK, but some tests were skipped!
Tests: 6, Assertions: 0, Skipped: 6.
```

## Lancer la suite avec MySQL (Docker)

### 1. Démarrer la base de test

```bash
docker compose -f docker-compose.integration.yml up -d
# Attendre que le healthcheck passe (≈20 s au premier démarrage)
docker compose -f docker-compose.integration.yml ps
```

Le schéma (`tests/Integration/Http/schema.sql`) est chargé automatiquement à la
première initialisation du volume. La suite le **réinjecte aussi avant chaque
test** (DROP + CREATE idempotents), donc l'état est toujours déterministe.

### 2. Exporter les variables d'environnement

```bash
export DB_HOST=127.0.0.1
export DB_PORT=3310
export DB_NAME=n3_http_test
export DB_USER=n3_http
export DB_PASS=n3_http_pass
export ADMIN_TOKEN=integration-http-token   # doit valoir la const TOKEN du test
```

> `ADMIN_TOKEN` doit correspondre à `OutputControlHttpIntegrationTest::TOKEN`
> (`integration-http-token`). Le test démarre le serveur `php -S` avec
> `ENV=test`, `AUTH_METHOD=both` et `ADMIN_TOKEN` injectés dans son environnement.

### 3. Exécuter

```bash
export COMPOSER_ALLOW_SUPERUSER=1
composer test:integration-http
```

Attendu (avec MySQL) : `OK (6 tests, …)`.

### 4. Arrêter / nettoyer

```bash
docker compose -f docker-compose.integration.yml down -v
```

## Détails techniques

- **Port MySQL 3310** : évite tout conflit avec 3306 (MySQL local) et 3307
  (`docker-compose.local.yml`).
- **Serveur HTTP éphémère** : le test choisit un port libre, démarre
  `php -S 127.0.0.1:<port> -t public` via `proc_open` avec un environnement
  dédié, attend `/ping`, puis l'arrête en fin de classe.
- **Environnement `test`** : routes `*-test`, tables `ffp3Outputs2` /
  `ffp3Data2` (cf. `App\Config\TableConfig`). Aucune table de production n'est
  touchée.
- **Pas d'image PHP dans le compose** : seul MySQL est conteneurisé ; la suite
  s'exécute avec le PHP de l'hôte/CI.
```
