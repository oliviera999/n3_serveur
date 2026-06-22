# Changelog FFP3 Datas

Toutes les modifications notables de ce projet sont documentees dans ce fichier.
Le format est base sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et ce projet adhere a [Semantic Versioning](https://semver.org/lang/fr/).

## Politique de maintenance

- Ce fichier reste volontairement court (fenetre recente).
- Les entrees anciennes sont archivees dans `docs/changelog/archive/`.
- Les garde-fous automatiques sont assures par `tools/changelog-maintenance.ps1`.
- Rotation recommandee : conserver les 40 dernieres entrees, taille cible <= 300KB.

## [5.3.6] - 2026-06-22

### Correction - `LogService` compatible Monolog 2 et 3 (fatal `Class "Monolog\Level" not found`)
- **`src/Service/LogService.php`** : suppression de la dépendance directe à l'enum `Monolog\Level` (introduit seulement par Monolog 3). `parseLevel()` renvoie désormais un `int` basé sur les constantes `Monolog\Logger::DEBUG|INFO|WARNING|ERROR|CRITICAL`, présentes **à la fois dans Monolog 2 et 3** ; les méthodes `info()/warning()/error()/critical()` et la signature `log(int|string $level, …)` suivent. Comportement de log inchangé (mêmes niveaux, même format).
- **Contexte** : en production le `vendor/` déployé contenait encore Monolog 2, où la classe `Monolog\Level` n'existe pas, ce qui provoquait un `Fatal error: Uncaught Error: Class "Monolog\Level" not found` à l'instanciation de `LogService` (front-controller cassé). Le service est maintenant tolérant à la version de Monolog effectivement installée. ⚠️ Penser tout de même à `composer install` sur le serveur pour aligner `vendor/` sur `composer.lock` (Monolog 3.10).

## [5.3.5] - 2026-06-21

### Maintenabilité - Extraction d'un domaine de calcul de marée testable
- **`src/Domain/TideStatistics.php`** : nouvelle classe pure (aucune I/O, PDO ni `$_ENV`) regroupant les calculs statistiques sur les cycles de marée : `frequencyStats()` (fréquence + écart-type des fréquences), `marnageStats()` (marnage moyen + écart-type) et `aggregateSwings()` (agrégation positive/négative des deltas entre extrema).
- **`src/Service/TideCycleDetector.php`** : `computeFrequencyStats()`, `computeMarnageStats()` et la partie agrégation de `computeVariations()` **délèguent** désormais à `TideStatistics`. Aucun changement de comportement : mêmes clés de tableau, mêmes arrondis, signatures publiques inchangées ; l'algorithme zigzag/extrema reste la responsabilité du service.
- **Tests** : nouveau `tests/Domain/TideStatisticsTest.php` (12 cas, nominaux + limites : tableau vide, durée nulle, point unique, clés non séquentielles). Les tests existants du détecteur restent verts.

### Build - Migration Monolog 2 -> 3
- **`composer.json` / `composer.lock`** : `monolog/monolog` passe de `^2.9` à `^3` (2.11.0 → 3.10.0).
- **`src/Service/LogService.php`** (seul consommateur de Monolog) : adaptation à l'API Monolog 3 — `parseLevel()` renvoie l'enum `Monolog\Level` (`Level::Debug/Info/Warning/Error/Critical`) au lieu des constantes int `Logger::*` ; `log()` accepte `int|string|Level`. Comportement strictement conservé (mêmes niveaux mappés, même `LineFormatter`, même fichier et rotation `RotatingFileHandler`/`StreamHandler`). Tests de logging inchangés.

## [5.3.4] - 2026-06-21

### Maintenabilité - Activation de l'autowiring PHP-DI et purge des closures DI redondantes
- **`config/dependencies.php`** : suppression de **78** closures de fabrique manuelles purement redondantes (de 83 à 5 définitions). Toutes les classes dont le constructeur n'attend que des dépendances typées par des classes/interfaces résolvables (contrôleurs, services, repositories, middlewares) sont désormais instanciées par **autowiring** — ajouter une nouvelle classe « pure » ne nécessite plus aucune entrée dans ce fichier.
- **Entrées conservées (5)** : `PDO` (fabrique `Database::getConnection()`), `RoleAccessService` (lit `routes_config.php`), `RateLimiter` et `RateLimitMiddleware` (lisent des scalaires d'environnement), `TemplateRenderer` (chemin templates + flag cache), ainsi que `RestartPumpCommand` et `CronOrchestrator` dont les constructeurs à paramètres **tous optionnels** déclencheraient sinon un fallback `Database::getConnection()` (connexion MySQL réelle) au build.
- **`config/container.php`** : `useAutowiring(true)` rendu explicite (déjà le défaut PHP-DI 7) ; la compilation en production reste active et fonctionnelle avec l'autowiring.
- **Tests** : nouveau `tests/Config/ContainerWiringTest.php` (filet de sécurité) qui construit le vrai container (sans compilation, PDO remplacé par SQLite en mémoire) et résout tous les contrôleurs de `public/index.php` plus les services/repositories/middlewares/commandes clés ; échoue si une dépendance n'est plus autowirable. Comportement applicatif inchangé.

## [5.3.3] - 2026-06-21

### Correctif - apply-recent-migrations.php en production
- **Résumé** : ignore les requêtes SELECT de vérification des fichiers SQL et active PDO buffered query ; corrige l'erreur « Cannot execute queries while other unbuffered queries are active » après contrainte PGL déjà existante.

## [5.3.2] - 2026-06-21

### Ajout - Script migrations SQL récentes
- **`tools/apply-recent-migrations.php`** (wrappers `.sh` / `.ps1`) : enchaîne PGL `device_event_id`, table `n3_users`, GPIO 117 FFP3 et bootstrap admin ; options `--dry-run`, `--skip-bootstrap`.
- **`tools/sql/migrate-gpio117-ffp3.sql`** : insertion idempotente GPIO 117 prod/test.

## [5.3.1] - 2026-06-21

### Performance - Chargement des assets front
- **Compression HTTP** : ajout de `mod_deflate` (fallback) et `mod_brotli` (si disponible) dans les `.htaccess` racine et `public/` pour HTML/CSS/JS/JSON/SVG (CSS/JS maison réduits de ~70–80 % sur le réseau). Fonts `woff2` et images exclues.
- **Highcharts opt-in** : `layout_base.twig` ne charge plus systématiquement ~600 Ko de scripts Highcharts CDN en `<head>` bloquant (les pages admin n'affichant aucun graphe). Remplacé par un bloc `chart_libs` vide par défaut + partial `partials/_highcharts_libs.twig` (scripts en `defer`). FontAwesome basculé du CDN vers la copie locale.

### Modifié - Déduplication contrôleurs Msp/N3pp
- Nouvelles bases abstraites `AbstractDescriptionController` et `AbstractHeartbeatController` : la logique commune des contrôleurs MSP1/N3PP (Description, Heartbeat) n'est plus dupliquée ; les concrètes ne portent que leurs spécificités. Routes, DI et contrats firmware inchangés.
- Nouvelle base `AbstractHmacPostDataController` : factorise `validateAuth()` (HMAC + fallback clé API) et la normalisation de l'email firmware, jusqu'ici dupliquées dans `MspPostDataController`/`N3ppPostDataController`. Les contrôleurs `Data` restent par module (configuration spécifique : capteurs, graphes, libellés) et les repositories `Sensor` restent distincts (schémas de mesures différents) — déjà factorisés via `AbstractDataController`/`AbstractSensorRepository`.

### Tests
- Couverture unitaire des repositories `BoardRepository`, `HeartbeatRepository`, `MspOutputRepository` (dont acquittement one-shot GPIO 108/109/110), puis `SensorRepository`, `GalleryControlRepository`, `PglRepository`, `UserRepository` (whitelists colonnes/modules, transactions, vérification de mot de passe). +118 tests.

## [5.3.0] - 2026-06-19

### Ajout - Gestionnaire d'utilisateurs multi-comptes
- **Résumé** : table `n3_users`, authentification session via BDD (fallback `.env` temporaire), trois rôles (admin, opérateur, lecteur), page `/admin/users` accessible depuis `/supervision`, script `tools/bootstrap-admin-user.php` et migration SQL `tools/sql/migrate-n3-users.sql`.

## [5.2.9] - 2026-06-19

### Correctif - switch forçage pompe aquarium (GPIO 117) bloqué à ON
- **Résumé** : le toggle « Forcer pompe aquarium ON » persistait par `id` HTML ; un id obsolète renvoyait un faux succès (0 ligne mise à jour) puis le polling remettait le switch à 1. Désormais persistance par GPIO 117, `updateStateById`/`updateState` échouent si aucune ligne affectée, GET state ignore les lignes sans nom et fusionne les doublons booléens ; `control-sync.js` traite le GPIO 117 comme switch binaire.

## [5.2.8] - 2026-06-18

### Modifié
- **OTA** : publication `ffp5-wroom-prod` v14.15.

## [5.2.7] - 2026-06-18

### Modifié
- **OTA** : publication `ffp5-wroom-prod` v14.14 (déploiement canal prod après validation flash USB).

## [5.2.6] - 2026-06-18

### Modifié
- **OTA** : publication `ffp5-wroom-prod` v14.13 (correctif boot OTA prod reporté silencieusement).

## [5.2.5] - 2026-06-16

### Modifié
- **Doc API PGL** : `docs/ENDPOINTS_ESP32_SERVEUR.md` — format `events` avec `eventId`, réponse `last_acked_event_id`, note migration `device_event_id`.
- **Smoke test Docker** : `POST /pgl/post-data` avec payload firmware 0.2.x (`eventId`) et vérification JSON `last_acked_event_id`.

## [5.2.4] - 2026-06-16

### Ajout
- **Poissonglouton offline-sync** : endpoint `POST /pgl/post-data` supporte le nouveau champ `event_id` (7e champ dans le payload `events`). Insertion idempotente via `INSERT IGNORE` sur contrainte `UNIQUE(board, device_event_id)`. La réponse JSON inclut désormais `last_acked_event_id` pour permettre au firmware de faire avancer son curseur de rattrapage.
- **Migration SQL** : `migrations/2026_06_pgl_device_event_id.sql` — ajout colonne `device_event_id INT UNSIGNED` + contrainte unique sur `pglEvents` (à appliquer en production).

### Correctif
- **Galeries timelapse** : la plage par défaut et les raccourcis (6 h, 24 h, etc.) se terminent sur la dernière photo stockée, pas sur l’heure courante.
- **Galeries timelapse** : si la fenêtre par défaut est vide alors que des photos existent, affichage automatique de toute la plage disponible.
- **API** `/api/gallery/{slug}/latest` : expose aussi la photo la plus ancienne et le nombre total d’images.

## [5.2.3] - 2026-06-16

### Évolution
- **Poissonglouton** : page `/pgl` unifiée avec le pattern live (badge, panneau état, stat-cards, graphiques Highcharts).
- **Poissonglouton** : ajout d’un API temps réel standard `/pgl/api/realtime/*` pour l’UI polling.

## [5.2.2] - 2026-06-16

### Correctif
- **Poissonglouton** : retrait script maintenance temporaire (table `pglHeartbeat` appliquee en prod).

## [5.2.1] - 2026-06-16

### Correctif
- **Poissonglouton** : table `pglHeartbeat` créée en prod (maintenance 2026-06-16) — `/pgl/heartbeat` répond 200.
- **`.env.example`** : documentation `PGL_API_KEY` (alignement `secrets.h` firmware).

## [5.2.0] - 2026-06-12

### Évolution — consommation de l'aquarium d'après la courbe de tendance (cm/jour)

- **`MathUtils::linearRegression`** : ajustement d'une droite par moindres carrés (pente + ordonnée à l'origine), réutilisable.
- **`WaterBalanceService`** : nouvelle consommation de l'aquarium calculée sur la **courbe de tendance** du niveau `EauAquarium`. La pente de la régression (distance capteur → surface) est ramenée en **cm/jour** : une distance qui augmente correspond à une baisse du niveau, donc à une consommation. Champs ajoutés au bilan : `aquarium_consumption_per_day` (baisse en cm/jour, ≥ 0) et `aquarium_trend_slope_per_day` (pente signée). La « Consommation moyenne » par événement existante est conservée.
- **Templates aquaponie (`aquaponie.twig`, `aquaponie_alt.twig`)** : nouvelle ligne « Consommation (tendance) » exprimée en cm/jour dans la carte Cycles de marée.
- **Tests** : `MathUtilsTest` (régression linéaire) et cas de dérive nette dans `WaterBalanceServiceTest`.

## [5.1.16] - 2026-06-11

### Correctif — détection des marées assouplie (hystérésis cumulée) et stats du cycle

- **`TideCycleDetector`** : le seuil de 2 cm n'est plus appliqué aux deltas entre lectures consécutives mais en écart cumulé depuis le dernier extrême (zigzag). Les marées lentes (faible variation par lecture, grande amplitude totale) sont désormais détectées — auparavant seules les marées rapides « extrêmes » franchissaient le seuil. Concerne `detectCycles`, `detectExtremaSeries`, `detectCurrentTrend` et `computeVariations` (dérives lentes de la réserve comptabilisées).
- **`aquaponie-tide-markers.js`** : détection client des extrema (marqueurs LIVE) alignée sur le même algorithme cumulé.
- **`TideAnalysisService::compute`** : le retour « période sans données » inclut désormais `reserve_pos` / `reserve_neg` / `reserve_var` / `diff_maree`, ce qui supprime les clés manquantes dans `/tide-stats` et les séries hebdomadaires (stats qui n'affichaient plus rien).
- **Tests** : cas de régression marées lentes (`TideCycleDetectorTest`).

## [5.1.15] - 2026-06-08

### Correctif — niveaux d'eau NULL (aquaponie temps réel et stats)

- **`ChartDataService::extractLastReadings`** : `EauAquarium` / `EauReserve` / `EauPotager` renvoient `null` si absent ou vide (plus de `0` trompeur).
- **`stats-updater.js`** : affichage « — » et barre à 0 % quand une mesure est absente (LIVE).
- **Templates aquaponie** : cartes stats alignées sur la sémantique null.
- **Tests** : `ChartDataServiceTest`, `Ffp3RealtimeDataProviderTest`, renforts HMAC `PostDataHmacAuthTest` / `Ffp3HmacPostBodyTest`.

## [5.1.14] - 2026-06-05

### Correctif — marées aquaponie (recalcul EauAquarium, graphiques et stats)

- **`ReadingTimeParser`** : conversion unifiée `reading_time` Europe/Paris → epoch (alignement marqueurs Highcharts et courbes).
- **`TideCycleDetector`** : flush de l'extrême final, min/max conservés sur petits deltas, seuil harmonisé, `detectCurrentTrend()` + libellés FR.
- **`WaterBalanceService`** : tendance actuelle (`tide_trend` / `tide_trend_label`), stats n/a si données insuffisantes.
- **UI aquaponie** : marqueurs « Basse mer » / « Pleine mer », carte tendance, note sémantique distance.
- **`chart-updater.js`** : résolution des séries par nom (plus d'indices fixes), détection client des marqueurs en LIVE.
- **`TideAnalysisService`** : seuil réserve aligné (1 cm), définition `diffMaree` corrigée (mm, variation distance).
- **Tests** : `TideCycleDetectorTest`, `WaterBalanceServiceTest`, `ReadingTimeParserTest`.

## [5.1.13] - 2026-06-03

### Correctif — clés POST numériques (108, 109) dans reconstruction HMAC

- **`Ffp3HmacPostBody`** : `parse_str` / `parsedBody` peuvent fournir des clés entières (`108`, `109`) — cast en string avant tri et `formatPair` (évite TypeError et 401 HMAC sur nourrissage).
- **Tests** : extras triés, override `etatPompeTank` sans doublon ; aligné firmware FFP5CS v13.90 (`ffp3_post_body`).

## [5.1.12] - 2026-06-03

### Correctif — POST FFP3 401 avec en-têtes X-Sig (HMAC corps vide)

- **Cause** : sous `application/x-www-form-urlencoded`, `php://input` est souvent vide côté mod_php alors que le firmware signe le corps complet → `Signature incorrecte` malgré NTP et secret OK.
- **Correctif** : capture du corps via `RawPostBodyMiddleware` + reconstitution canonique `Ffp3HmacPostBody` (ordre aligné FFP5CS) dans `PostDataController`.
- **Tests** : `Ffp3HmacPostBodyTest`, `PostDataHmacAuthTest` (parsedBody + stream vide).

## [5.1.11] - 2026-06-02

### Ajout — Poissonglouton statut en ligne

- **`POST /pgl/heartbeat`** : ingestion heartbeat (table `pglHeartbeat`), auth `PGL_API_KEY`.
- **`GET /pgl/api/system/health`** : JSON online/offline (seuil 5 min, heartbeat + dernier événement).
- Page **`/pgl`** : bandeau LIVE/HORS LIGNE + poll 15 s ; carte accueil + module live.
- **`PglConfig`** : flags `ONLINE_CHECK_ENABLED`, `SHOW_ONLINE_STATUS_ON_PAGE`, `ONLINE_THRESHOLD_SECONDS`.
- Migration SQL `2026_06_pgl_heartbeat.sql` ; tests `PglHeartbeatControllerTest`, `PglRepositoryHealthTest`.

## [5.1.10] - 2026-06-02

### Modifié — Poissonglouton (stats publiques)

- **`GET /pgl`** : page statistiques accessible sans jeton (remplace `GET /pgl/{secret}`).
- Navigation : entrée « Poissonglouton » dans le menu principal.
- Smoke test local : assertion sur `/pgl` (200).

## [5.1.9] - 2026-06-01

### Modifié — Docker local (auth session smoke)

- **`.env.docker.example`** : `AUTH_METHOD=both`, hash pour mot de passe dev **`localadmin`** (smoke `-AuthMode both -AdminPassword localadmin`).

### Modifié — cohérence page contrôle aquaponie (audit juin 2026)

- **Documentation** : rapport [`docs/AUDIT_COHERENCE_AQUAPONIE_CONTROL_2026-06.md`](docs/AUDIT_COHERENCE_AQUAPONIE_CONTROL_2026-06.md) ; mises à jour `AUTHENTICATION.md`, `SYNCHRONISATION_BIDIRECTIONNELLE.md`, `AUDIT_PAGE_CONTROL_DISTANT.md`, `ENDPOINTS_ESP32_SERVEUR.md`.
- **UI** : texte de synchronisation ESP32 aligné sur le polling réel (6–10 s) dans `templates/control.twig`.
- **Code** : commentaires GPIO 18 (`PumpService` legacy vs page contrôle) dans `OutputSyncService` et `PumpService`.
- **Tests** : `tests/Repository/OutputRepositoryTest.php` (mapping paramètres / GPIO page contrôle).

## [5.1.8] - 2026-05-31

### Correctif — affichage aquaponie (graphiques, icônes, assets locaux)

- **Graphiques** : initialisation fiabilisée (`scheduleInit`, `prepareContainers`, fallback CSS min-height vue alt, retry layout, `window.load` + `ResizeObserver`).
- **Font Awesome 6.5.1** : auto-hébergé (`/assets/css/fontawesome.min.css`, `/assets/webfonts/`) ; override `.fas` dans `realtime-styles.css`.
- **Moment.js** : auto-hébergé (`moment.min.js`, `moment-timezone-with-data.min.js`) pour aquaponie, MSP1 et N3PP.
- **Service Worker** : chemins `/assets/` à jour, cache `n3-iot-v5.1.8`, suppression des références legacy `/ffp3/` et CDN.

## [5.1.7] - 2026-05-31

### Correctif — seuils aquarium mm (nettoyage CRON + alerte eau basse)

- **SensorDataService** : defaults `CLEAN_MIN_EAU_AQUARIUM=40`, `CLEAN_MAX_EAU_AQUARIUM=700` (mm en BDD, équivalent 4-70 cm).
- **CronOrchestrator** : alerte eau basse quand `EauAquarium > seuil` (distance élevée = eau basse, aligné firmware) ; défaut `AQUA_LOW_LEVEL_THRESHOLD=180` mm (18 cm, `aqThreshold`).
- **`.env.example`**, **`.env.docker.example`** : seuils aquarium et réserve corrigés en mm.
- **Tests** : `CronOrchestratorTest` (cas normal vs bas), `SensorDataServiceTest` (aquarium mm).
- **Doc** : `docs/deployment/CRON.md`, `firmwires/ffp5cs/docs/technical/SEUILS_SERVEUR_ESP32.md`.

## [5.1.6] - 2026-05-31

### Modifié — consolidation crons FFP3 (orchestrateur unique)

- **CronOrchestrator** : fusion de `CleanDataCommand` et `ProcessTasksCommand` en un seul orchestrateur (`run-cron.php`).
- **RestartPumpCommand** : branchée en première phase (redémarrage pompe aqua après marée figée).
- **Alerte niveau eau** : message unifié (niveau bas, arrêt pompe réserve) via `sendCustomAlert`.
- **UI** : retrait lien `cronpompe.php` ; note cron automatique dans `control.twig`.
- **Tests** : `CronOrchestratorTest`, `RestartPumpCommandTest`.
- **Doc** : `docs/deployment/CRON.md`.

## [5.1.5] - 2026-05-30

### Correctif — seuils nettoyage EauReserve (mm)

- **SensorDataService** : defaults `CLEAN_MIN_EAU_RESERVE=15`, `CLEAN_MAX_EAU_RESERVE=1000` (alignés firmware v13.84, valeurs en mm en BDD).
- **`.env.example`** : commentaire unité mm pour les niveaux d'eau.

## [5.1.4] - 2026-05-30

### Migrations BDD — audit production oliviera_iot3

- **Bundle prod** : `migrations/APPLY_PROD_AUDIT_2026.sql` (post_id, config FFP3, heartbeats msp/n3pp, Poissonglouton, ffp3OtaTrigger).
- **Scripts** : `001b_add_post_id_s3.sql`, `ADD_MISSING_COLUMNS_v11.36.sql` consolidé, `00_diagnostic_prod.sql`, `99_validate_prod.sql`.
- **Docker local** : init `85-legacy-heartbeats.sql`, `95-ffp3-ota-trigger.sql` ; colonnes `tide*` dans `00-schema.sql`.
- **Doc** : `migrations/README.md` — checklist audit prod et procédure phpMyAdmin.

## [5.1.3] - 2026-05-30

### Correctifs critiques FFP3 (PR #12 / #15)

- **HMAC FFP5CS v13.80+** : en-têtes `X-Sig-Timestamp`, `X-Sig-Nonce`, `X-Sig-Hmac` sur le corps brut (`timestamp + "\n" + nonce + "\n" + body`). Mode strict compatible sans `api_key` obligatoire.
- **Trigger OTA distant** : `ffp3OtaTrigger` auto-créée au premier usage si la table manque ; le bouton « Vérifier OTA » ne répond plus OK sans persistance.
- **`/post-data` FFP3** : insertion capteur, sync GPIO et `Boards.last_request` dans une transaction unique (rollback `post_id` si sync outputs echoue).
- **Tests** : HMAC en-têtes, corps brut, auto-création OTA, insertion atomique FFP3.

## [5.1.2] - 2026-05-25

### Migration BDD — persistance colonnes marée `tide*`

- Nouveau script `migrations/002_add_tide_event_columns.sql` : ajoute `tideEvent`, `tideTrend`, `tideNoiseMm`, `tideWindowMs`, `tideExtremeMm` sur les tables `ffp3Data`, `ffp3Data2`, `ffp3Data3`, `ffp3Data4`, `ffp3DataS3`, `ffp3DataS3Test`.
- Documentation procédure dans `migrations/README.md`.
- Test unitaire `TideCycleDetectorTest` pour valider la détection d'extrema horodatés (`peaks`/`troughs`).

## [5.1.1] - 2026-05-25

### Marées min/max (distance) - contrat firmware, extrema horodatés, rendu courbe

#### Ingestion FFP3 (retrocompatible)
- `PostDataController` accepte les nouveaux champs optionnels envoyes par FFP5CS : `tideEvent`, `tideTrend`, `tideNoiseMm`, `tideWindowMs`, `tideExtremeMm`.
- `SensorData` et `SensorRepository` etendus pour persister ces champs **uniquement si la colonne existe** (meme strategie que `Pression`/`configSynced`) afin de ne pas casser les environnements non migres.

#### Detection extrema
- `TideCycleDetector` expose `detectExtremaSeries()` qui retourne des points horodates `peaks`/`troughs` a partir de `EauAquarium` (distance, en cm cote serveur apres conversion).
- `AquaponieController` injecte ces series dans le view-model (`tide_peaks`, `tide_troughs`) pour les pages `/aquaponie` et `/aquaponie-alt`.

#### UI
- Ajout de deux series scatter sur la courbe `EauAquarium` :
  - `Pics marée (distance)`
  - `Creux marée (distance)`
- Factorisation dans `public/assets/js/aquaponie-tide-markers.js` pour eviter la divergence entre templates.
- La courbe brute est conservee (pas de sur-lissage). Les lignes de tendance restent secondaires.

## [5.1.0] - 2026-05-19

### Audit serveur exhaustif — Sécurité, qualité, tests, docs

#### Sécurité critique
- **Purge clé API** : valeur `fdGTMoptd5CD2ert3` (production legacy) supprimée des fichiers versionnés (`tools/test_simple.php`, `tools/check_env.php`, `env.test.example`, docs actives). Lecture depuis `$_ENV['API_KEY']` désormais obligatoire. Nouvelle doc `docs/SECURITE_ROTATION_API_KEY.md` (procédure de rotation, purge historique git).
- **`addErrorMiddleware`** : conditionnel à `ENV` (false en prod) — stack PHP plus exposée en cas d'exception non gérée.
- **OTA** : nouveau flag `OTA_REQUIRE_AUTH` (`.env`) qui active `X-Api-Key` / `X-OTA-Key` / `?api_key=` sur `/ota/*` et `/ffp3/ota/*`. Désactivé par défaut pour compat firmwares (l'intégrité reste vérifiée par MD5 côté firmware).
- **Scripts PHP exposés** : `public/assets/icons/generate-icons.php` et `generate_icons.py` déplacés vers `tools/icons/`. `.htaccess` durci (`*.php/.py/.sh/.ps1` interdits sous `/assets/` et `/uploads/`, fichiers `.env/.git*/composer.*` bloqués globalement).

#### Sécurité importante
- **Sessions** : `session.cookie_samesite=Lax` forcé dans `AuthService` ; détection HTTPS via `HTTPS`, `X-Forwarded-Proto` ou `SERVER_PORT 443`.
- **Headers** : `SecurityHeadersMiddleware` ajoute `Content-Security-Policy` (default-src 'self' + Highcharts/jsDelivr) et `Permissions-Policy` (geolocation/microphone/camera bloqués). HSTS envoyé aussi sur `X-Forwarded-Proto: https` ou via `SECURITY_FORCE_HSTS=true` (reverse proxy mutualisé). `X-XSS-Protection` retiré (déprécié).
- **JSON anti-XSS** : `ChartDataService`, `TideStatsController` et templates `n3pp_data.twig` / `msp1_data.twig` utilisent `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS` pour les valeurs injectées en `|raw` dans les blocs `<script>`.
- **`robots.txt`** : patterns généralisés (`/admin`, `/api`, `/dashboard`, `/supervision`, `/export-data`) au lieu de routes précises.
- **Rate limit upload galerie** : `GALLERY_UPLOAD_RATE_LIMIT_SECONDS` (défaut 10 s/IP), code `HTTP 429` si dépassé, stockage `var/cache/ratelimit/`.
- **LogService** : `RotatingFileHandler` Monolog (14 jours configurable), masquage IP automatique (`LOG_MASK_IP=true` par défaut — IPv4 `.0`, IPv6 `::/80`) pour limiter l'impact RGPD du cronlog public.

#### HMAC firmware ↔ serveur (renforcement opt-in)
- **`SignatureValidator`** : ajout de `createSignatureWithNonce()` et `isValidWithNonce()` — message canonique `<timestamp>|<nonce>`.
- **`HMAC_STRICT_MODE=true`** (`.env`) : refuse l'absence de HMAC (au lieu de fallback `api_key`). À activer après migration des firmwares.
- **`HMAC_NONCE_REQUIRED=true`** (`.env`) : exige `post_id` et utilise la variante avec nonce — anti-replay strict combiné à la dédup `post_id` BDD.
- **`PostDataController`** (FFP3) et **`HmacAuthTrait`** (MSP/N3PP) gèrent les deux modes.

#### Qualité & architecture
- **`CacheController`** : ~250 lignes de HTML inline extraites vers `templates/admin/cache_admin.twig` et `templates/admin/deploy_script.twig`. Fallback HTML minimal conservé si `TemplateRenderer` indisponible.
- **`HeartbeatController`** : nouvelle classe `HeartbeatRepository` (whitelist tables par environnement). Le contrôleur n'injecte plus directement `PDO`.
- **`RealtimeDataService`** déprécié (alias vers `Ffp3RealtimeDataProvider`) **supprimé** ; références mises à jour dans `dependencies.php`, `tools/diagnostic_500_errors.php`, `bin/diagnose-controllers.php`.
- **`SensorStatisticsService::aggregateMany()`** : 28 requêtes SQL (4 agrégats × 7 colonnes) → 1 requête unique. `StatisticsAggregatorService::aggregateAllStats()` simplifié.
- **`declare(strict_types=1)`** ajouté dans 12 fichiers (`Command/*`, `Config/{Database,Env}`, `Domain/SensorData`, `Service/{LogService,ErrorAlert,Notification,Pump,SensorData,SensorStatistics,SystemHealth,TemplateRenderer}`).
- **Outillage** : `composer require-dev` phpstan/phpstan ^1.10 + friendsofphp/php-cs-fixer ^3.50 + roave/security-advisories. Nouveaux scripts : `composer analyse`, `cs:check`, `cs:fix`, `audit`. Configs `phpstan.neon` (niveau 5) et `.php-cs-fixer.php` (PSR-12+).

#### Tests
- `SignatureValidatorTest` étendu (nonce, tampering, fenêtre future, non-numérique).
- `AuthServiceTest` nouveau (hash, validate token, query param, env manquant).
- `SecurityHeadersMiddlewareTest` nouveau (CSP, HSTS HTTPS/X-Forwarded-Proto/force-flag, override).
- `LogServiceMaskIpTest` nouveau (masquage IPv4, IPv6, invalide, désactivation env).
- `SensorStatisticsServiceAggregateManyTest` nouveau (SQLite in-memory + UDF STDDEV).

#### Documentation
- `docs/ENDPOINTS_ESP32_SERVEUR.md` : versions FFP5CS 13.51 / Serveur 5.1.0, codes HTTP upload galerie (200/202/400/401/413/415/429/500), section HMAC enrichie (modes strict/nonce).
- `docs/SECURITE_ROTATION_API_KEY.md` (nouveau) : procédure de rotation, transition douce HMAC, purge historique git.
- `docs/changelog/README.md` (nouveau) : stratégie rolling window, commandes `composer changelog:check/rotate`, hook pre-commit.
- `.gitignore` (serveur) : ajout `analyse-ffp3/`, `ameliorations-visuelles-iot-serveur/`, `.php-cs-fixer.cache`, `.phpstan.cache/`.

#### Rétro-compatibilité
- L'API actuelle reste **compatible** : firmwares FFP5CS, N3PP, MSP1 et ESP32-CAM continuent à fonctionner sans modification.
- Les nouveaux modes (HMAC_STRICT_MODE, HMAC_NONCE_REQUIRED, OTA_REQUIRE_AUTH) sont **opt-in** via `.env`.

#### Rotation CHANGELOG
- 67 entrées (5.0.201 → 5.0.267) déplacées vers `docs/changelog/archive/CHANGELOG_5.0.201-5.0.267.md`.
- `CHANGELOG.md` court (~40 entrées, taille cible < 300 KB) conforme à la politique « rolling window » documentée dans `docs/changelog/README.md`.

---

## [5.0.307] - 2026-05-19

### Ajout - module Poissonglouton (recyclage plastique)
- **Nouvelles routes** : `POST /pgl/post-data` (ingestion batch firmware) et `GET /pgl/{secret}` (page stats cachée, accès URL secrète).
- **Nouveau repository** : `PglRepository` (insert événements + agrégations horaires/journalières + validation token).
- **Schéma SQL** : nouvelles tables `pglEvents` et `pglBoards` (Docker local + migration prod).
- **Tests** : `PglPostDataControllerTest` (auth/lot) et `PglStatsControllerTest` (404/200).

---

## [5.0.306] - 2026-05-17

### Audit serveur API + UI — sécurité, cohérence, robustesse
- **ENV par requête** : `TableConfig` n’écrit plus `$_ENV['ENV']` ; surcharge scoped + `RequestEnvironmentMiddleware` (fin des fuites inter-requêtes PHP-FPM).
- **Auth device** : `DeviceApiKeyValidator` unifié (header `X-Api-Key` ou `api_key`) pour galeries upload, état outputs et version firmware.
- **FFP3 post-data** : HMAC valide = plus d’`api_key` obligatoire ; extraction JSON/form via `RequestHelper`.
- **Admin** : cookie `admin_token` HttpOnly/Secure/SameSite ; token URL non persisté en cookie.
- **UI** : manifest/PWA racine `/`, jQuery unique (local), pages d’erreur HTML, OTA en streaming.
- **Tests** : `TableConfigEnvironmentTest`, `DeviceApiKeyValidatorTest`, `AbstractPostDataAuthTest`.

---

## [5.0.305] - 2026-05-17

### FFP3 post-data (ffp5cs) — cohérence contrat & persistance
- **INSERT** : écriture des champs optionnels (durées, `WakeUp`, `Pression`, `configSynced`, etc.) si la colonne existe en base.
- **ACK** : requêtes `ack_command` sans INSERT ligne capteur (mise à jour `Boards` uniquement).
- **Déduplication** `post_id` : exécutée après authentification.
- **Heartbeat** : réponse succès via `textClose` (comme post-data).
- **Schéma Docker** : colonnes alignées sur le contrat firmware (voir `docker/mysql/init/00-schema.sql`).

---

## [5.0.304] - 2026-05-09

### Correctif - FFP3 : declencheur OTA non consommé par le polling web
- **Résumé** : les polls `GET /ffp3/api/outputs*/state?fresh=1` de la page de contrôle restent désormais en lecture seule pour `triggerOtaCheck`. Le flag OTA persistant n'est consommé que par le GET firmware sans `fresh=1`, évitant qu'une page ouverte intercepte la commande avant l'ESP32.

---

## [5.0.303] - 2026-04-14

### Correctif - N3PP (serre) : tendance humidité sol en entiers
- **Résumé** : la série « Tendance humid. moy. » (régression linéaire sur l’humidité du sol) arrondit désormais les valeurs à l’entier le plus proche (UA), avec tooltip sans décimales, aligné sur les cartes statistiques.

---

## [5.0.302] - 2026-04-14

### Modifié - accueil IoT : texte intro aéré et liens olution
- **Résumé** : sur `home.twig`, le texte d'introduction reprend le contenu pédagogique (salle n³, projets FFP3/MSP1/N3PP, plateforme olution, contacts) avec des paragraphes plus courts ; liens explicites vers `https://olution.info` pour la marque olution ; correction « Pour toute question ».

---

## [5.0.301] - 2026-04-07

### Correctif - UI : bascule thème visible sur mobile
- **Résumé** : en vue ≤980px la barre `#nav` est masquée et le bouton thème ne se voyait qu’en bas du menu latéral (souvent hors champ ou peu évident). Ajout d’un bouton thème dans la barre fixe à côté du hamburger ; le doublon dans le tiroir est masqué. `theme-toggle.js` reconnaît tout `.theme-toggle-btn` (y compris les clics sur les icônes).

---

## [5.0.300] - 2026-04-06

### Correctif - FFP3 : déclenchement OTA distant fiable (PHP-FPM multi-workers)
- **Résumé** : le flag `triggerOtaCheck` n’était plus stocké dans le cache outputs (v5.x) mais dans un **tableau statique PHP** : le POST « Vérifier OTA » et le GET `/api/outputs(-*)/state` pouvaient être traités par **des workers FPM différents**, donc l’ESP32 ne recevait jamais `triggerOtaCheck`. Désormais persistance dans la table **`ffp3OtaTrigger`** (une ligne par environnement) : consommation atomique au premier GET qui matche. Migration : `migrations/CREATE_FFP3_OTA_TRIGGER_TABLE.sql`.

---

## [5.0.299] - 2026-04-06

### Documentation - FFP3 : outputs vs mesures capteurs (GPIO 16 / etatPompeAqua)
- **Résumé** : dans `docs/ENDPOINTS_ESP32_SERVEUR.md`, précision sur la distinction entre la table **outputs** (état lu par le GET du firmware) et la colonne **etatPompeAqua** des insertions capteurs (dernier POST), pour le diagnostic pompe aquarium / poll.

---

## [5.0.298] - 2026-04-06

### Correctif - smoke local : HttpWebRequest pour GET et POST form
- **Résumé** : `local-smoke-test.ps1` — `Invoke-RequestStatus` utilise `HttpWebRequest` pour les GET simples et les POST `application/x-www-form-urlencoded` sans fichier (cookies de session, pas de redirection automatique), avec lecture du corps sur 200/302 ; helper `Read-HttpWebResponseBody` ; repli sur le code de statut via `InnerException` pour Invoke-WebRequest.

---

## [5.0.297] - 2026-04-06

### Correctif - forçage pompe aquarium : persistance GPIO 16 en BDD
- **Résumé** : après activation du switch « Forcer pompe aquarium ON » (GPIO 117), la mise à jour miroir de la pompe (GPIO 16) passait par `updateState` sans `requestTime` / `lastModifiedBy = web`, donc le POST firmware pouvait immédiatement réécrire l’état physique avec `etatPompeAqua = 0`. Désormais la même sémantique que `updateStateById` (horodatage + source web). Détection du forçage : toute ligne GPIO 117 valide à `state = 1` compte (évite un faux « désactivé » si plusieurs lignes sans ordre déterministe).

---

## [5.0.296] - 2026-04-04

### Correctif - smoke local : login session (cookie jar)
- **Resume** : le POST login nominal dans `Assert-SessionAuth` repasse par `Invoke-WebRequest` avec redirections suivies (`-MaximumRedirection 10`) pour que le `WebRequestSession` recoive les cookies ; le POST form via `HttpWebRequest` seul laissait le GET page protegee en 302.

---

## [5.0.295] - 2026-04-04

### Correctif - smoke local : HttpWebRequest (GET / POST form sans redirection)
- **Resume** : `Invoke-WebRequest` avec `-MaximumRedirection 0` provoquait des erreurs sur les reponses 302 (pages protegees, checks negatifs, POST login). Les GET simples et les POST `application/x-www-form-urlencoded` sans fichier utilisent `HttpWebRequest` (`AllowAutoRedirect = false`), avec lecture du corps pour les cookies de session ; upload multipart reste sur `Invoke-WebRequest`.

---

## [5.0.294] - 2026-04-04

### Correctif - aquaponie : mise en �uvre Highcharts #15 (timestamps + s�ries)
- **R�sum�** : alignement `Ffp3RealtimeDataProvider` sur le parsing `Europe/Paris` (comme `ChartDataService`) ; `chart-updater.js` : normalisation des X avant redraw, repli `addPoint` ; `local-smoke-test.ps1` : interpolation explicite des URLs token.

---

## [5.0.293] - 2026-04-04

### Correctif - mode sombre : libelles des liens-boutons lisibles
- **Resume** : la surcharge `#wrapper a { color: accent }` en dark mode ecrasait le texte blanc des liens a fond plein (`button primary`, `home-link-btn`, `project-link`). Exceptions explicites pour un contraste correct.

---

## [5.0.292] - 2026-04-04

### Correctif - aquaponie : Highcharts warning #15 (donn�es X non tri�es)
- **R�sum�** : alignement des timestamps de l'API temps r�el FFP3 sur le m�me parsing `Europe/Paris` que `ChartDataService` (�vite des abscisses incoh�rentes avec les s�ries initiales). C�t� client, normalisation des s�ries touch�es avant `redraw` et repli `addPoint` si `xData` est absent.

---

## [5.0.291] - 2026-04-04

### Correctif - contr�le FFP3 : mapping param�tres, logs et doc d'audit
- **R�sum�** : `parameter_gpio_map` pour la page `/control` provient de `OutputRepository::getParameterGpioMap()` (source unique avec la persistance BDD). Journalisation des exceptions dans `OutputController::updateParameters` (r�f�rence `[n3 500]`). Mise � jour de `docs/AUDIT_PAGE_CONTROL_DISTANT.md` (constats 500 / variable Twig obsol�tes).

---

## [5.0.290] - 2026-04-04

### Correctif - aquaponie : min / moy / max coh�rents avec la p�riode affich�e
- **R�sum�** : le polling temps r�el appelait `StatsUpdater.updateAllStats()`, qui recalculait min, moyenne, max et �cart-type uniquement sur les lectures re�ues en live (souvent une seule au premier poll), �crasant les agr�gats SQL corrects du serveur pour la fen�tre choisie. Option `updateDetailStats: false` sur les pages aquaponie : le live met � jour la valeur courante et les graphiques, les lignes Min/Moy/Max restent celles du rendu serveur jusqu?au prochain rechargement ou changement de p�riode.

---

## [5.0.289] - 2026-04-04

### Correctif - GPIO 117 for�age pompe : cr�ation fiable et exposition API
- **R�sum�** : appel `ensureAquariumPumpForceRowExists` aussi au POST donn�es et avant le GET �tat outputs ; ajout du GPIO `117` dans la liste renvoy�e par `getOutputsState` (sinon la page contr�le / le polling ne voyaient pas l'�tat) ; d�faut `OutputCacheService` pour `117` ; r�paration des lignes `gpio=117` avec `name` vide (invisibles dans `findAll`) ; journalisation `error_log` si INSERT/UPDATE �choue (PDO ne l�ve souvent pas d'exception).

---

## [5.0.288] - 2026-04-04

### Ajout - smoke local : auth par token, session ou les deux
- **R�sum�** : `tools/local-smoke-test.ps1` accepte `AuthMode` (token / session / both), identifiants admin et cl� API param�trables, session HTTP pour les pages prot�g�es, et option `-RunNegativeAuthChecks` pour valider les refus d?acc�s. Align� avec les tests d?int�gration firmware `wroom-beta-local`.

---

## [5.0.287] - 2026-04-04

### Correctif - pages de contr�le : conteneurs en mode sombre
- **R�sum�** : `control-styles.css` red�finissait dans `:root` les variables `--panel-bg`, `--control-bg`, `--border-color` et `--text-muted` apr�s `theme-variables.css`, ce qui �crasait le th�me sombre (panneaux restant blancs). Suppression de ces doublons ; les couleurs suivent � nouveau les jetons du th�me. Remplacement de `var(--accent)` par `var(--accent-primary)`.

---

## [5.0.286] - 2026-04-04

### Ajout - for�age pompe aquarium ON persistant c�t� contr�le aquaponie
- **R�sum�** : for�age pompe aquarium ON persistant c�t� contr�le aquaponie.

---
## [5.0.285] - 2026-04-04

### Ajout - contr�le aquaponie : for�age persistant pompe aquarium ON
- **R�sum�** : ajout d'un switch de contr�le serveur `Forcer pompe aquarium ON` (GPIO virtuel `117`) sur la page de contr�le aquaponie. Quand l'option est active, la synchronisation POST firmware ignore l'�tat `etatPompeAqua` renvoy� par l'ESP32 et maintient la BDD � `1` pour `GPIO 16` (pompe aquarium). Le mode est persistant en BDD (seed Docker + scripts d'initialisation GPIO mis � jour).

---

## [5.0.284] - 2026-04-04

### Correctif - contr�le aquaponie : anti-�crasement des commandes web sur actionneurs physiques
- **R�sum�** : ajout d'une fen�tre de priorit� web de 12 secondes sur la synchronisation des GPIO physiques (`2`, `15`, `16`, `18`) lors des POST firmware, pour �viter qu'un �tat ancien renvoy� juste apr�s un clic UI ne r��crase la commande en base (`ON` affich� mais BDD revenue � `0`). Le comportement reste inchang� pour les autres r�gles d�j� en place (`reset` 20 s, nourrissage one-shot).

---

## [5.0.283] - 2026-04-04

### Correctif - aquaponie : niveaux d�??eau mm �?? cm et format d�cimal fran�ais
- **R�sum�** : les colonnes `EauAquarium`, `EauReserve`, `EauPotager` sont stock�es en **millim�tres** ; la page aquaponie (et le bilan hydrique) les affichaient comme des cm sans conversion. Conversion �10 c�t� serveur (`Ffp3WaterLevelUnit`), m�me logique pour dashboard FFP3, API temps r�el capteurs, `WaterBalanceService`, `TideAnalysisService`. Affichage avec **virgule** d�cimale (Twig `number_format`, JS `toLocaleString('fr-FR')`, `stats-updater.js`). Documentation `docs/ENDPOINTS_ESP32_SERVEUR.md`, tests `tests/Util/Ffp3WaterLevelUnitTest.php`.

---

## [5.0.282] - 2026-03-31

### Ajout� - tests d'int�gration repositories + suites PHPUnit Unit / Integration
- **R�sum�** : `IntegrationDbTestCase` (`#[BackupGlobals(false)]`, PDO, seuil snapshot, `TableConfig` prod) ; `SensorRepositoriesSnapshotIntegrationTest` (SensorReadRepository, MspSensorRepository, N3ppSensorRepository, BoardRepository, fen�tres 10 min ; comparaisons dates en `strcmp` format SQL). `phpunit.xml` : suites **Unit** et **Integration** ; scripts Composer `test:unit`, `test:integration`. Documentation `README.md` et skill PHPUnit.

### Correctif - `SensorReadRepository::getLastReadings` (ORDER BY + LIMIT sous MySQL)
- **R�sum�** : le `LIMIT` li� (`:limit`) pouvait retourner des lignes dans un ordre incorrect selon le driver PDO MySQL ; passage � une limite enti�re dans la requ�te apr�s validation de la table (`TableValidator`) et plafond 10 000.
- Fichiers : `src/Repository/SensorReadRepository.php`, `tests/Integration/`, `phpunit.xml`, `composer.json`, `README.md`, `.cursor/skills/tests-phpunit-serveur/SKILL.md`, `VERSION`

---

## [5.0.281] - 2026-03-31

### Modifi� - footer galeries : version firmware uploadphotosserver
- **R�sum�** : les pages timelapse et grille admin (`/gallery/{slug}`, `/admin/gallery/{slug}`) passent la m�me version que la page contr�le cam�ra (GPIO 100, POST `post-uploadphotoserver-version.php`) au pied de page unifi� (`_footer.twig`). Page d�??index des galeries : pas de badge firmware unique (trois appareils). `GalleryControlController` normalise `firmware_version` en cha�ne vide si absente pour le `footer_config` Twig.
- Fichiers : `src/Controller/Gallery/GalleryViewController.php`, `GalleryControlController.php`, `config/dependencies.php`, `VERSION`

---

## [5.0.280] - 2026-03-31

### Ajout� - import dump production vers BDD Docker locale (tests etendus)
- **R�sum�** : script `tools/import-mysql-dump-to-local-docker.ps1` (import dans `iot_n3_import_staging` puis synchro vers `iot_n3_local`) ; SQL `docker/mysql/sync-import-staging-to-local.sql` avec mappage des colonnes (Boards, ffp3Data/post_id, Heartbeat timestamp �?? reading_time, sorties board en varchar, etc.). Tests `tests/Integration/RealDatasetDockerDbTest.php` ; documentation `README.md`, regle Cursor `serveur-validation-locale-docker.mdc`, skill PHPUnit. Correctif whitelist JS : `n3-stock-chart-bootstrap.js` dans `config/routes_config.php`.
- Fichiers : outils et SQL ci-dessus, `tests/Integration/`, `config/routes_config.php`, `README.md`, `VERSION`

---

## [5.0.279] - 2026-03-30

### Modifi� - remplissage sous courbe (areaspline) MSP1/N3PP align� aquaponie
- **R�sum�** : ajout de `n3AreaGradientFill` dans `chart-helpers.js` ; s�ries continues en `areaspline` avec d�grad� vertical sur m�t�o et serre (temp�ratures, humidit�s, luminosit�, humidit� sol, cycles avec opacit� plus l�g�re) ; `plotOptions.areaspline` par d�faut dans `n3-stock-chart-bootstrap.js` (`connectNulls: false`, marqueurs d�sactiv�s). Les s�ries en colonnes et la tendance lin�aire N3PP restent inchang�es.
- Fichiers modifi�s : `public/assets/js/chart-helpers.js`, `public/assets/js/n3-stock-chart-bootstrap.js`, `templates/msp1_data.twig`, `templates/n3pp_data.twig`, `docs/AUDIT_GRAPHIQUES_HIGHCHARTS.md`, `VERSION`

---

## [5.0.278] - 2026-03-30

### Modifi� - unification affichage Highcharts MSP1/N3PP sur mod�le aquaponie
- **R�sum�** : ajout d�??un bootstrap partag� (`n3-stock-chart-bootstrap.js`) pour cr�er les graphiques uniquement quand les conteneurs sont r�ellement dimensionn�s (retry born� + reflow AOS), factorisation de l�??initialisation des charts MSP1/N3PP, alignement du layout Stock (`navigator.xAxis.ordinal = false`, l�gende dense), et fiabilisation du live update via `ChartUpdaterGeneric` (d�doublonnage timestamp, insertion tri�e hors ordre, `redraw(false)`).
- Fichiers modifi�s : `public/assets/js/chart-updater-generic.js`, `public/assets/js/n3-stock-chart-layout.js`, `public/assets/js/n3-stock-chart-bootstrap.js`, `templates/msp1_data.twig`, `templates/n3pp_data.twig`, `templates/data_page.twig`, `docs/AUDIT_GRAPHIQUES_HIGHCHARTS.md`, `VERSION`

---

## [5.0.277] - 2026-03-30

### Modifi� - seuil de rupture Highcharts (gap) port� � 6 h
- **R�sum�** : `gapSize` global (`gapUnit: 'value'`) pass� de 1 h � **6 h** (21600000 ms) pour ne couper les courbes qu�??apr�s une absence de relev�s plus longue.
- Fichiers modifi�s : `public/assets/js/highcharts-defaults.js`, `VERSION`

---
## [5.0.276] - 2026-03-30

### Correctif - courbes Highcharts invisibles (aquaponie, s�ries temps r�el)
- **R�sum�** : le `gapSize` global avec `gapUnit: 'relative'` fragmentait les s�ries d�s qu�??existait une paire de timestamps tr�s rapproch�s ; les segments disparaissaient (marqueurs d�sactiv�s) tout en restant actifs au survol. Passage � `gapUnit: 'value'` avec un seuil de 1 h (3600000 ms) sur l�??axe datetime pour ne couper la courbe qu�??apr�s une vraie coupure de relev�s.
- Fichiers modifi�s : `public/assets/js/highcharts-defaults.js`, `VERSION`

---
## [5.0.275] - 2026-03-30

### Correctif - smoke test local et diagnostic environnements sous Docker
- **R�sum�** : d�lai HTTP du smoke test param�trable (`-TimeoutSec`, d�faut 60 s) pour limiter les timeouts sur pages lourdes ; `verify_environments.php` utilise `Database::getConnection()` et le `.env` (`DB_HOST=db` en stack Docker) au lieu d�??identifiants MySQL cod�s en dur.
- Fichiers modifi�s : `tools/local-smoke-test.ps1`, `tools/verify_environments.php`, `README.md`, `VERSION`

---
## [5.0.274] - 2026-03-30

### Modifi� - unification du layout Highcharts et robustesse th�me syst�me
- **R�sum�** : reconstruction de `theme-toggle.js` et `aquaponie-chart-layout.js` apr�s corruption locale, ajout d'un module partag� `n3-stock-chart-layout.js` (hauteurs/options/load/resize) r�utilis� par MSP1/N3PP et compos� c�t� aquaponie, avec mise � jour du th�me Highcharts lors d'un changement de pr�f�rence syst�me sans th�me stock�.
- Fichiers modifi�s : `public/assets/js/theme-toggle.js`, `public/assets/js/aquaponie-chart-layout.js`, `public/assets/js/n3-stock-chart-layout.js`, `templates/msp1_data.twig`, `templates/n3pp_data.twig`, `templates/aquaponie.twig`, `templates/aquaponie_alt.twig`, `config/routes_config.php`, `public/assets/css/common-data.css`, `VERSION`

## [5.0.273] - 2026-03-30

### Correctif - coh�rence VERSION et CHANGELOG apr�s r�gression
- **R�sum�** : r�int�gration de l�??entr�e **[5.0.272]** (ordre `highcharts-theme.js` / `head_scripts` dans `layout.twig`) supprim�e par erreur lors d�??un commit ult�rieur ; incr�ment **5.0.273** pour reprendre la suite s�mantique sans r��crire l�??historique Git.
- Fichiers modifi�s : `CHANGELOG.md`, `VERSION`

---
## [5.0.272] - 2026-03-30

### Correctif - ordre de chargement Highcharts theme/defaults (MSP1/N3PP)
- **R�sum�** : dans `layout.twig`, `highcharts-theme.js` est charg� avant `{% block head_scripts %}` afin que `n3HighchartsBuildThemeOptions()` soit disponible lorsque `highcharts-defaults.js` appelle `Highcharts.setOptions()`. Cela aligne l�??initialisation du th�me au premier rendu et corrige les incoh�rences visuelles observ�es sur le graphique � Param�tres physiques � du potager.
- Fichiers modifi�s : `templates/layout.twig`, `VERSION`

---
## [5.0.271] - 2026-03-30

### Modifi� - alignement chargement Highcharts MSP1/N3PP sur Aquaponie
- **R�sum�** : d�placement de `highcharts-defaults.js` et `chart-helpers.js` de `{% block scripts %}` vers `{% block head_scripts %}` dans les vues MSP1 et N3PP, pour harmoniser l�??ordre de chargement avec Aquaponie et r�duire le risque de r�gression li� � l�??ordre des scripts inline.
- Fichiers modifi�s : `templates/msp1_data.twig`, `templates/n3pp_data.twig`, `VERSION`

---
## [5.0.270] - 2026-03-30

### Correctif - favicon n3 orange versionn� et cache PHPUnit ignor�
- **R�sum�** : ajout du fichier `public/assets/icons/favicon-n3-orange.png` r�f�renc� par les layouts et `routes_config.php` ; ajout de `.phpunit.cache/` au `.gitignore` (PHPUnit 10).
- Fichiers modifi�s : `.gitignore`, `public/assets/icons/favicon-n3-orange.png`, `VERSION`

---
## [5.0.269] - 2026-03-30

### Correctif - graphique aquaponie : corde droite sur les niveaux d'eau
- **R�sum�** : `afterSetExtremes` �tait invoqu� au premier redraw Highcharts avec `e.trigger` non d�fini ; le `setData` des tendances utilisait des indices de s�ries fixes (vue alt sans contr�le du nom), ce qui pouvait �craser les s�ries `areaspline` avec les points de r�gression lin�aire �?? ligne droite du premier au dernier point. Ignorer ces appels sans `trigger`, ne mettre � jour que les s�ries `type: 'line'` identifi�es par le libell�, `setData` sans animation, et `connectNulls: false` sur les aires.
- Fichiers modifi�s : `templates/aquaponie.twig`, `templates/aquaponie_alt.twig`, `VERSION`

---

> Les entrées antérieures à `[5.0.268]` (5.0.201 → 5.0.267) sont archivées :
> [`docs/changelog/archive/CHANGELOG_5.0.201-5.0.267.md`](docs/changelog/archive/CHANGELOG_5.0.201-5.0.267.md).
