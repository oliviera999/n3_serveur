# Changelog FFP3 Datas

Toutes les modifications notables de ce projet sont documentees dans ce fichier.
Le format est base sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et ce projet adhere a [Semantic Versioning](https://semver.org/lang/fr/).

## Politique de maintenance

- Ce fichier reste volontairement court (fenetre recente).
- Les entrees anciennes sont archivees dans `docs/changelog/archive/`.
- Les garde-fous automatiques sont assures par `tools/changelog-maintenance.ps1`.
- Rotation recommandee : conserver les 40 dernieres entrees, taille cible <= 300KB.

## [6.24.0] - 2026-07-19

### OTA — rollback serveur + durcissement (audit contrat firmware↔serveur, phase 1)

Phase 1 **non bloquante** de l'audit bout-en-bout du contrat firmware↔serveur (voir `docs/AUDIT_CONTRAT_FIRMWARE_SERVEUR_2026-07.md`). Aucune activation d'enforcement de signature ni de retrait du chemin legacy (flotte non re-flashable).

#### Ajouts
- **Système de rollback OTA serveur** (`src/Service/OtaRollbackService.php`, CLI `bin/ota-rollback.php`) : capture (snapshot), liste et **restauration atomique** d'une version OTA servie, avec auto-sauvegarde de l'état courant avant écrasement. Prérequis de récupération avant tout futur enforcement de signature. Doc : `docs/OTA_ROLLBACK.md`. Cibles : n3pp, n3pp-test, msp, msp-test, cam (ffp5cs : rollback Git documenté).

#### Sécurité
- **OTA handler — défense en profondeur (L4)** : au-delà du blocage `..`, vérification `realpath()` que le fichier résolu reste sous la base `ota/` (neutralise un lien symbolique pointant hors base). `src/Controller/Ffp3/OtaFileController.php`.

#### Documentation
- Rapport d'audit complet + **séquence de déploiement sûre** de l'enforcement de signature OTA (`docs/AUDIT_CONTRAT_FIRMWARE_SERVEUR_2026-07.md`) : points OTA (signature P‑521, TLS), HMAC anti-rejeu (legacy epoch-only), config distante (C1/C2 nested vs plat), contrat de champs, versions.

## [6.23.0] - 2026-07-15

### Audit général — sécurité, bugs, performance, code mort, UI/UX

Lot de remédiation issu d'un audit transversal du dépôt. Aucun changement de rupture (l'auth par `?token=` reste fonctionnelle).

#### Sécurité
- **Rate-limiting anti-usurpation** : `X-Forwarded-For` n'est plus fait confiance que si `REMOTE_ADDR` est un proxy déclaré (`TRUSTED_PROXIES`, CSV IP/CIDR, vide par défaut) ; sinon `REMOTE_ADDR`. `src/Middleware/RateLimitMiddleware.php`, `.env.example`.
- **RBAC** : les écritures de réglages opérationnels et de politique d'audit HMAC exigent désormais `isAdmin()` (403 sinon), au lieu du niveau OPERATOR. `OperationalSettingsController`, `HmacAuditController`.
- **Anti-énumération d'utilisateurs (timing)** : `password_verify` factice quand l'utilisateur est absent, côté `.env` (`AuthService`) et table (`UserRepository`).
- **Durcissement session** centralisé (`AuthService::hardenSessionCookie()`), appliqué aussi par `CsrfService` (indépendant de l'ordre d'init).
- **Suppression** des scripts de purge de cache HTTP non authentifiés `public/maintenance/clear-cache.php` et `clear-di-cache.php` (wipe accessible sans auth). Remplacés par la route admin `/admin/clear-cache*` et `bin/clear-cache.php`. Docs mises à jour (dont correction : la variable réelle est `ADMIN_TOKEN`, sans valeur par défaut).
- Support additif du token admin par en-tête sûr (`Authorization: Bearer` / `X-Admin-Token`) et cookie httpOnly. Le token en `?token=` reste accepté (front de contrôle) mais est marqué à migrer (secret en URL — cf. M4, `docs/AUTHENTICATION.md`).

#### Corrections de bugs
- **Export CSV d'une plage vide** ne renvoie plus une 500 : CSV valide (en-têtes seuls). `CsvExportService`, `SensorReadRepository`.
- **Isolation d'environnement** : l'env `test` n'utilise plus l'ID de board `1` (prod) mais `2`. `TableConfig::getPostDataBoardId()`.
- **Digest de notifications** : les entrées ne sont purgées qu'après un envoi mail réussi (plus de perte d'alertes P3/P4 sur panne SMTP). `NotificationDigest`, `NotificationService`.
- **Alertes de transition FFP3** (chauffage / pompe réserve) : l'état n'est latché qu'après un envoi réussi. `Ffp3DerivedAlertService`.
- **Anti-doublon `post_id`** : violation d'unicité traitée en skip idempotent (`SensorRepository`) + migration d'index UNIQUE.
- `SensorStatisticsService::aggregateMany` : accès de clés gardés (`?? null`), fin des warnings PHP 8.

#### Performance
- Index `reading_time` ajouté sur les tables S3 (prod) et variantes non couvertes. `migrations/2026_07_PROD_05_s3_indexes.sql`.
- Cache mémoire request-scoped des réglages serveur (1 requête au lieu de 2×N). `ServerSettingsRepository`.
- `OutputRepository` : lignes GPIO garanties via 1 `SELECT ... IN` au lieu de 6-7 ; résolution de board paresseuse.
- Nettoyage CRON incrémental (fenêtre glissante indexée) et sans COUNT préalable. `SensorDataService`, `CronOrchestrator`.
- Compilation du container DI activée dès que `!TableConfig::isTest()` (couvre l'env s3 de prod). `config/container.php`.
- Décimation LTTB mémoïsée (1 passe au lieu de 2 par page graphique). `ChartDataService`.

#### Code mort
- Suppression de `src/Util/BasePath.php`, `bin/auto-off-gpio.php`, `templates/control_harmonized.twig`, `AuthService::loginLegacy()`. Correction de références mortes dans `migrations/README.md`.

#### Refactoring interne (comportement inchangé)
- **DerivedAlert** : squelette latch/hystérésis mutualisé dans `AbstractVitalsDerivedAlertService::evaluateLatchedLowValue()` (batterie/sol/gel/pluie + canicule via `DIRECTION_HIGH`) ; détecteur de transition booléenne extrait en `BooleanTransitionDetectorTrait` ; `toFloatOrNull()` en `FloatCastTrait` (fin de la double définition Abstract/Ffp3). `N3pp::checkWateringPump()` volontairement non mutualisé (sémantique de latch différente).
- **Realtime** : maths santé/uptime repository-agnostiques extraites en `RealtimeHealthTrait` (partagé base + Ffp3 + Pgl) ; spécificités par famille (seuils online GPIO 116/107, marge, buckets Pgl) préservées.
- **Contrôleurs Output** : blocs identiques N3pp/Msp remontés dans `AbstractOutputController` (`saveNotificationPolicy`, délégations `getStateData`/`updateParameterByName`/`batchUpdateParameters` via hook `outputRepository()`, `getDefaultParams` commun, prédicat actionneur, préambule env). `Ffp3/OutputController` laissé inchangé (n'étend pas la base — re-parentage reporté).

#### UI / UX / PWA
- Accessibilité : filtres de période en `<button>`, `aria-current="page"` sur la nav, `aria-hidden` sur icônes décoratives, skip-link et `base_path` corrigés dans `layout_base.twig`.
- Badge de statut initialisé en état neutre « Vérification… » (plus de « En ligne » optimiste). `dashboard.twig`, `control.twig`.
- Service worker : `RUNTIME_CACHE` versionné + purgé, HTML de navigation en network-first (plus de HTML périmé), éviction bornée. `manifest.json` : champ `id`. Toast d'install harmonisé (`n³ IoT`).

---
## [6.22.3] - 2026-07-12

### Correctif - Galerie : tri par date de capture (dernières photos en tête)
- **Résumé** : la grille et `/api/gallery/{slug}/latest` trient par date embarquée dans le nom (`Y-m-d_H-i-s`), plus par compteur seq. Évite qu'un reset NVS (seq bas) enterre une photo fraîche derrière d'anciens numéros élevés.
- `src/Controller/Gallery/GalleryViewController.php` — `sortNewestFirst($files, $uploadDir)`.

---
## [6.22.2] - 2026-07-12

### Correctif - HMAC galerie optionnel avec fallback api_key
- **Résumé** : sur upload / sync / POST version CAM, une signature `X-Sig-*` invalide (ex. horloge firmware hors fenêtre) ne provoque plus de 401 par défaut : repli sur la clé API déjà validée. Mode strict via `GALLERY_HMAC_STRICT=true`. Firmware uploadphotosserver ≥ 2.65 : HMAC device désactivé par défaut (`CAM_DEVICE_HMAC=0`).
- `src/Security/DeviceSignatureValidator.php` — soft-fail → `null` sauf strict.
- Controllers Gallery (Upload / Sync / Control) — logs info en fallback ; rejet uniquement si `verify() === false`.
- `.env.example` — `GALLERY_HMAC_STRICT=false`.
- Tests unitaires mis à jour (soft + strict).

---
## [6.22.1] - 2026-07-10

### Correctif - GET firmware plat+ack (/api/firmware/outputs/state) et ack one-shot si X-Api-Key sur realtime MSP/N3PP
- **Résumé** : GET firmware plat+ack (/api/firmware/outputs/state) et ack one-shot si X-Api-Key sur realtime MSP/N3PP.

---
## [6.22.0] - 2026-07-08

### Interrupteur « veille infinie sous seuil batterie » télécommandable (N3PP + MSP1)
- **Fonctionnalité** : nouvelle option dans les pages de contrôle serre/élevage (N3PP) et station météo (MSP1), section « Énergie », pour **activer/désactiver la mise en veille infinie** que le firmware déclenche quand la batterie passe sous le seuil du pont diviseur (`SeuilPontDiv`). Désactivée, l'ESP retombe sur son cycle de réveil normal (`FreqWakeUp`) malgré la batterie basse ; l'alerte batterie reste émise.
- **Contrat** : nouveau GPIO virtuel **112 → `veilleInfinie`** (persistant, défaut `1` = comportement historique), ajouté à `N3ppGpioMap` et `MspGpioMap`. Il est renvoyé au firmware par `getStateForFirmware()` (non server-only) ; le firmware (n3pp ≥ 4.55, msp ≥ 2.53) le lit sur la clé `112`.
- `src/Config/N3ppGpioMap.php`, `src/Config/MspGpioMap.php` — ajout de `112 => 'veilleInfinie'`.
- `src/Controller/N3pp/N3ppOutputController.php`, `src/Controller/Msp/MspOutputController.php` — `veilleInfinie` ajouté à `getDefaultParamKeys()` et défaut `'1'` dans `getDefaultParams()`.
- `src/Controller/AbstractOutputController.php` — validation booléenne de `veilleInfinie` (`normalizeAndValidateParameterValue`, comme `WakeUp`/`ServoModeAuto`).
- `templates/n3pp_control.twig`, `templates/msp1_control.twig` — nouveau `modern-switch` « Veille infinie batterie faible » (`saveParamSwitch('veilleInfinie', …)`, `data-parameter-gpio="112"`).
- `migrations/2026_07_veille_infinie_gpio.sql` — insertion des lignes GPIO 112 (`ON DUPLICATE KEY UPDATE`) pour `n3ppOutputs`/`n3ppOutputsTest` (board 3) et `msp1Outputs`/`msp1OutputsTest` (board 2). Seed dev mis à jour (`docker/mysql/init/10-seed.sql`).
- `docs/API_MSP1_N3PP.md` — table du contrat GPIO virtuels enrichie (clé 112).

### Correctif portabilité BDD (dette pré-existante — CI `test:unit` remise au vert)
- `src/Repository/ServerSettingsRepository.php` — `ensureSchema()` émettait une DDL MySQL (`ON UPDATE CURRENT_TIMESTAMP`, `ENGINE=InnoDB DEFAULT CHARSET…`) non parsable par SQLite, faisant échouer les 23 `ContainerWiringTest::testServiceIsResolvable` du suite unitaire (dette signalée « suivie séparément » en 6.21.4). La DDL est désormais **adaptée au driver PDO** (`PDO::ATTR_DRIVER_NAME`, idiome déjà utilisé par `OutputCacheService`) : MySQL conserve sa clause complète, les autres drivers (SQLite en test) reçoivent une DDL portable équivalente (mêmes colonnes / clé primaire). Aucun changement de comportement en production.

## [6.21.4] - 2026-07-07

### Correctif test `HeartbeatControllerTest` (dette pré-existante révélée par la CI verte sur PHPStan)
- **Contexte** : une fois PHPStan au vert (v6.21.3), la CI a enfin atteint `test:unit`, révélant des erreurs pré-existantes sans lien avec la nomenclature.
- `tests/Controller/Ffp3/HeartbeatControllerTest.php` — `makeController()` instanciait `HeartbeatController` avec l'**ancien ordre d'arguments** (3 args), alors que le constructeur a depuis inséré `?HmacAuditLogger` en position 2. Ajout de `null` à cette position (paramètre nullable, `HmacAuditLogger` est `final` donc non mockable, et l'usage dans le contrôleur est null-safe `?->record(...)`). Corrige les 7 `TypeError`.
- **Reste connu (non traité ici)** : ~23 `ContainerWiringTest` échouent encore sur `ServerSettingsRepository::ensureSchema()` (DDL MySQL `ON UPDATE CURRENT_TIMESTAMP`/`ENGINE=InnoDB` non parsable par SQLite du suite Unit) — problème de portabilité BDD pré-existant, suivi séparément.

## [6.21.3] - 2026-07-07

### Correctifs PHPStan (dette pré-existante — quota `Tests & qualité` remis au vert)
- **Contexte** : la CI `analyse` (PHPStan niveau 6) était rouge sur 4 erreurs pré-existantes issues des features supervision 6.19–6.21 (latentes tant que `cs:check` échouait avant PHPStan). Aucun changement de comportement.
- `src/Service/HmacAuditLogger.php:125` — **vrai correctif** : `Logger::log()` (Monolog 3) recevait un entier (`Logger::INFO`/`Logger::WARNING`, 200/300) au lieu d'un niveau typé → utilise l'enum `Monolog\Level::Info` / `Level::Warning`. Les niveaux passés aux **handlers** (lignes 47-48) restent `Logger::INFO` (acceptés en `int`, non signalés).
- `src/Service/HmacAuditLogger.php:194` — comparaison toujours vraie supprimée : `$ext = pathinfo(...) ?: 'log'` est toujours non vide, donc `($ext !== '' ? '.'.$ext : '')` se simplifie en `'.'.$ext` (comportement identique).
- `src/Config/OperationalSettingsCatalog.php:449` — annotation de retour corrigée : `get()` renvoie une entrée du catalogue (`array<string, mixed>|null`) et non un niveau de plus (`array<string, array<string, mixed>>|null`).
- `src/Controller/SupervisionController.php:23` — dépendance injectée mais jamais lue supprimée (`OperationalSettingsService`, propriété promue + import). Contrôleur auto-câblé (autowiring), aucun changement de définition DI ; le panneau « Réglages opérationnels » passe par ses propres endpoints (`/admin/api/operational-settings`).

## [6.21.2] - 2026-07-07

### Nomenclature `ffp3` / `ffp5cs` — clarification (doc + fixtures de test)
- **Problème** : le radical `ffp3` est surchargé (données serveur, galerie caméra `/ffp3gallery/`, sous-module firmware `ffp5cs/ffp3`, contrat HMAC générique), et le nom du firmware (`ffp5cs`) n'apparaît dans aucun identifiant de code serveur. Le champ POST `sensor` était incohérent : le firmware envoyait le type de carte (`esp32-wroom`/`esp32-s3`), les tests serveur utilisaient `ffp5cs`, le test firmware `ffp3`.
- **Convention `sensor`** : unifiée sur **`ffp3`** (identité système). Côté serveur, `sensor` reste **journalisé/stocké sans validation** (l'environnement/table vient de la route) — aucun changement de comportement runtime. Fixtures de test alignées : `PostDataControllerHmacHeaderTest`, `SignatureValidatorTest`, `Ffp3DerivedAlertServiceTest` (`sensor=ffp5cs` → `sensor=ffp3` ; signatures recalculées dynamiquement, pas de vecteur en dur).
- **Documentation** : nouveau glossaire [`docs/NOMENCLATURE_FFP3.md`](docs/NOMENCLATURE_FFP3.md) (les sens de « ffp3 », correspondance firmware↔serveur, chantiers différés dont la validation `sensor↔env` en *log-only*). `CLAUDE.md` + `.cursorrules` : note de nomenclature. `docs/ENDPOINTS_ESP32_SERVEUR.md` : convention `sensor` documentée et correction de la table S3 PROD (`ffp3Data4` → `ffp3DataS3`, cohérent avec la migration juillet 2026).
- **Périmètre** : cœur de la PR = doc + fixtures de test. Contrepartie firmware : n3_firmwires `ffp5cs` v15.09 (`ProjectConfig::SYSTEM_ID="ffp3"`).
- **Déblocage CI** : correction de 3 violations `cs:check` **pré-existantes** sur `master` (sans lien avec la nomenclature, sans changement de comportement) — imports redondants même-namespace `use App\Service\OperationalSettingsService;` dans `SystemHealthService`/`DeviceHealthService` (le type-hint résout via le namespace), et indentation dans `tests/Controller/Ffp3PostDataControllerTest.php` (`php-cs-fixer`).

## [6.21.1] - 2026-07-07

### Modifié — descriptifs des réglages supervision

- Panneau **Réglages opérationnels** : nom `.env` (`code`), libellé et descriptif sur la même ligne pour chaque variable.
- Carte **Sécurité HMAC** : descriptifs pour `HMAC_AUDIT_LOG`, `HMAC_STRICT_MODE` et `HMAC_NONCE_REQUIRED`.
- Complément des textes d’aide (`hint`) pour les seuils `CLEAN_*`.

## [6.21.0] - 2026-07-07

### Ajout — réglages opérationnels pilotables depuis la supervision (~35 variables)

- **`OperationalSettingsCatalog`** + **`OperationalSettingsService`** : override BDD (`op_<ENV_KEY>`) avec repli `.env` pour alertes, notifications, sécurité firmware, logs, qualité données et inondation FFP3.
- **Supervision** : panneau « Réglages opérationnels » (groupes repliables, enregistrement groupé, réinitialisation par groupe ou globale).
- **API** : `GET/POST /admin/api/operational-settings`, `POST /admin/api/operational-settings/reset` (CSRF, admin).
- **Intégration** : CRON, alertes dérivées MSP/FFP3, heartbeat offline, galeries, OTA, rate limits, nettoyage capteurs, politique notif globale, logs.

## [6.20.0] - 2026-07-07

### Ajout — politique HMAC pilotable depuis la supervision

- **`HmacPolicyService`** : centralise `HMAC_STRICT_MODE` et `HMAC_NONCE_REQUIRED` avec priorité BDD (`serverSettings`) puis repli `.env` — **sans modifier le fichier `.env`**.
- **Supervision** et **`/admin/hmac-audit`** : switchs « Mode strict » et « Nonce requis », bouton « Revenir au .env » (supprime l'override BDD).
- **API** : `POST /admin/api/hmac-audit/toggle-policy`, `POST /admin/api/hmac-audit/reset-policy`, `GET /admin/api/hmac-audit/policy`.
- **Contrôleurs** : `HmacAuthTrait`, `PostDataController`, `LegacyHeartbeatHandler` et PGL lisent la politique via `HmacPolicyService` (trait `HmacPolicyTrait`).

## [6.19.0] - 2026-07-07

### Ajout — journal audit HMAC (supervision)

- **`HmacAuditLogger`** : trace structurée des tentatives HMAC firmware (succès `ok` / rejet `reject`) dans un fichier dédié `hmac-audit.log` (préfixe `[hmac-audit]`), activable/désactivable sans redéploiement.
- **`ServerSettingsRepository`** + table `serverSettings` : persistance du réglage `hmac_audit_enabled` (repli `.env` `HMAC_AUDIT_LOG`).
- **Supervision** : switch « Journal audit HMAC » + aperçu des dernières lignes ; lien vers `/admin/hmac-audit`.
- **`Admin\HmacAuditController`** : page de consultation (actualisation auto 30 s), API `GET /admin/api/hmac-audit/entries` et `POST /admin/api/hmac-audit/toggle` (CSRF, admin).
- **Intégration** : enregistrement sur post-data FFP3/MSP/N3PP/PGL, heartbeats FFP3 et legacy, via `HmacAuthTrait` / `PostDataController` / `LegacyHeartbeatHandler`.

## [6.18.0] - 2026-07-07

### Arbitrage des e-mails — remplissage/arrosage/pompe continue migrés + alertes météo MSP1

Vide quasi complètement le SMTP embarqué en régime normal : il ne reste côté ESP que le
nourrissage (non dérivable), les P4 diagnostics, la batterie ffp5cs et le crash/panic.

- **Remplissage démarré / terminé (FFP3)** : transition de `etatPompeTank` entre deux lectures
  (P3/Info, Hydraulique) — reprend les confirmations du firmware ffp5cs.
- **Arrosage effectué (N3PP)** : transition `etatPompe` 0→1 (P3/Info, Hydraulique).
- **Arrosage continu (N3PP)** : `etatPompe = 1` sur ≥ 2 lignes consécutives = pompe maintenue ON
  à travers un cycle de réveil (P1/Critical, latch + ré-armement au relâchement) — reprend
  l'alerte « ATTENTION, arrosage continu » du firmware.
- **Alertes météo MSP1 (serveur-only, opt-in `.env`)** : gel (`MSP_FROST_ALERT_THRESHOLD_C`,
  P2, hystérésis +2 °C), canicule (`MSP_HEAT_ALERT_THRESHOLD_C`, P2, hystérésis -2 °C),
  pluie (`MSP_RAIN_WET_THRESHOLD`, P3, capteur analogique 4095 = sec, garde sonde déconnectée).
  Désactivées tant que la variable n'est pas définie ; le repli DHT (20 °C) est neutre pour des
  seuils raisonnables. `.env.example` + `docs/deployment/CRON.md` documentés.
- **Fix anti-spam transitions** : les mails de transition (chauffage ON/OFF, remplissage,
  arrosage) n'ont plus de clé de throttle — la dédup vient de la détection de transition ;
  le cooldown P3 de 6 h aurait avalé les bascules légitimes suivantes de la journée.
- Tests : transitions remplissage, arrosage + pompe collée, gel (latch/ré-armement), pluie
  (sentinelle déconnectée), météo désactivée sans opt-in.

## [6.17.0] - 2026-07-07

### Arbitrage des e-mails — mail « Firmware mis à jour » dérivé du POST (OTA réussie)

- **Nouveau détecteur** `src/Service/DerivedAlert/FirmwareUpdateDetector.php` : changement de la
  colonne `version` entre deux lignes du même capteur = mise à jour firmware (OTA réussie ou
  reflash). Garde `sensor` (pas de faux positif si un autre appareil poste dans la même table),
  premier passage silencieux.
- **Trois familles couvertes** : FFP3 (seul signal serveur de fin d'OTA — pas de `bootCount` au
  POST ffp5cs), N3PP et MSP1 (socle vitals). Mail P3/Info, catégorie Lifecycle, clé d'anti-spam
  par version cible (`<fam>:fw-update:<version>`).
- **Dédoublonnage reboot** : quand une mise à jour est détectée, le mail « Redémarrage détecté »
  du même cycle est supprimé (le reset de `bootCount` est la conséquence attendue de l'OTA).
- La CAM (uploadphotosserver) reste hors périmètre : sa version transite par un canal distinct
  (POST version, hors tables data) — ses mails OTA restent côté ESP (échec = P2, retenté).
- Tests : mise à jour → un seul mail (pas de doublon reboot), capteur différent → silencieux,
  FFP3 version change → mail (suites `VitalsDerivedAlertServiceTest` / `Ffp3DerivedAlertServiceTest`).

## [6.16.0] - 2026-07-07

### Arbitrage des e-mails, Phases 1+2 — serveur émetteur primaire (CRON 1 min + alertes dérivées du POST)

Implémente les Phases 1 et 2 du plan `docs/ARCHITECTURE_MAILS_ARBITRAGE.md` : le serveur devient
la base d'envoi de toutes les alertes dérivables des données reçues au POST, avec une latence ≤ 1 min.
(La bascule de l'ESP en simple relais — Phase 3 — viendra séparément, après validation.)

**Phase 1 — CRON à 1 minute**
- **Crontab applicative à 1 min** (`docs/deployment/CRON.md` mis à jour, `* * * * *`) ; le verrou
  `flock` non bloquant ignore proprement les runs qui se chevauchent.
- **Réserve basse déplacée dans le bucket fréquent** (chaque minute, comme aquarium bas / marées) ;
  l'horaire garde : online, « appareil silencieux » toutes familles, digest.
- **Correctif tick-based** : `CronOrchestrator::checkTideSystem` est sautée tant qu'un redémarrage
  de pompe est programmé — à 1 min, la pompe coupée maintient l'écart-type bas et chaque tick
  réécrivait le flag (redémarrage perpétuellement repoussé). `RestartPumpCommand` reste horodaté
  (flag = epoch de programmation) : le délai de 5 min est indépendant de la cadence.
- **Anti-spam inchangé mais documenté pour 1 min** : politique + `AlertThrottler` (cooldown par
  sévérité : P1 15 min, P2 1 h, P3 6 h, P4 24 h) + digest P3/P4 + machines d'état à latch.

**Phase 2 — alertes ESP-only migrées côté serveur** (`src/Service/DerivedAlert/`, appelées chaque minute)
- **Trop-plein FFP3** : `EauAquarium < limFlood` (GPIO 114) via `FloodStateMachine`, port fidèle de la
  machine anti-spam du firmware (debounce 5 min, cooldown 60 min, hystérésis de sortie 2 cm / 15 min
  stables — surchargeables par env `FLOOD_*`). Latch sur envoi effectif uniquement (parité Phase 0
  firmware) ; garde de fraîcheur des données (au-delà, l'alerte « appareil silencieux » couvre).
- **Chauffage ON/OFF FFP3** : transition de `etatHeat` entre lectures, message avec `TempEau` et le
  seuil GPIO 104 (P3/Info, digest).
- **Sol sec N3PP** : `HumidMoy < SeuilSec` (les deux au POST) avec latch + hystérésis de ré-armement
  +5 % (parité `seuilRetourNormal()` firmware) et mail de retour à la normale ; garde « au moins une
  sonde Humid1..4 valide » (parité firmware).
- **Batterie faible N3PP + MSP1** : `PontDiv < SeuilPontDiv` (au POST), P1/Critical, latch + ré-armement
  silencieux à +5 % (`AbstractVitalsDerivedAlertService`, socle commun).
- **Redémarrage N3PP + MSP1** : détection par **décrément** de `bootCount` (compteur RTC incrémenté à
  chaque réveil, remis à zéro sur vrai reboot — un incrément est le rythme normal des réveils).
- **État inter-runs** persisté en JSON (`var/cache/derived_alerts_*.json`, `DerivedAlertStateStore`) ;
  curseur par ligne (`lastRowId`) pour ne traiter chaque POST qu'une fois.
- **⚠️ Nourrissage (fait/manqué/plafond) : gap documenté** — non dérivable du POST actuel (contrat
  « compteur monotone » 6.0.0/firmware 15.0 : `bouffePetits/Gros` postés à 0, pas d'ack de consommation,
  seuls les horaires des créneaux sont échangés). Nécessite un nouveau champ au POST (phase firmware) ;
  d'ici là l'ESP reste l'émetteur primaire du nourrissage (règle d'ordonnancement §4 du plan).
- **Tests** : `FloodStateMachineTest` (parité firmware), `LowValueAlertEvaluatorTest`,
  `Ffp3DerivedAlertServiceTest` (debounce, latch sur échec d'envoi, fraîcheur, transitions chauffage),
  `VitalsDerivedAlertServiceTest` (batterie latch/ré-armement, reboot par reset compteur, sol sec,
  curseur de ligne), + 2 cas `CronOrchestratorTest` (réserve en fréquent, garde du flag marées).
- `.cursorrules` / `CLAUDE.md` synchronisés (cadence CRON, buckets, délai horodaté).
- **Phase 4 (documentation)** : nouveau §8 « État d'implémentation » dans
  `docs/ARCHITECTURE_MAILS_ARBITRAGE.md` — statut des phases, liste de référence des alertes
  restant légitimement côté ESP (batterie ffp5cs critique-only, crash/panic, nourrissage,
  diagnostics CAM…), et ordre de déploiement impératif (serveur 6.16.0 + crontab 1 min AVANT
  le flash des firmwares Phase 3).

## [6.15.0] - 2026-07-07

### Supervision — seuils d'alerte pilotés par la BDD de contrôle + seuil « hors ligne » dérivé du temps de veille (facteur nuit)
- **Problème** : le seuil « hors ligne » de la supervision était un **forfait fixe de 3600 s**, sans lien avec le temps de veille réel des modules (`FreqWakeUp`, piloté en BDD et **allongé la nuit** par le firmware ffp5cs, ×3). Résultat : fausses alertes « appareil silencieux » / « système hors ligne » dès que la veille approchait/dépassait l'heure. En parallèle, plusieurs seuils d'alerte FFP3 étaient dupliqués dans `.env` alors que la valeur de référence vivait déjà en BDD.
- **Seuil hors-ligne dérivé** (`src/Service/OfflineThresholdResolver.php`, nouveau) : calcule, **par famille**, `seuil = veille × cycles_tolérés + marge` (borné 60 s–24 h) à partir de `FreqWakeUp` (FFP3 GPIO 116, N3PP/MSP GPIO 107). Pour FFP3, le **facteur nuit** est reflété en BDD par des lignes **server-only** (GPIO 126 multiplicateur, 127 début, 128 fin de nuit) — **miroir des constantes firmware, sans modifier le firmware**.
- **Généralisé aux trois familles** : `DeviceHealthService` (heartbeat) accepte le résolveur et l'applique à FFP3/N3PP/MSP1 ; `SystemHealthService::checkOnlineStatus` reçoit le seuil FFP3 dérivé depuis `CronOrchestrator`.
- **Seuils d'alerte lus en BDD** (repli `.env` conservé) :
  - **Niveau aquarium bas** : lu depuis GPIO 102 (`aqThreshold`, cm) × 10 = mm — aligné firmware — au lieu de `AQUA_LOW_LEVEL_THRESHOLD`.
  - **Écart-type marées** : nouvelle ligne server-only GPIO 129 (repli `TIDE_STDDEV_THRESHOLD`).
  - **Réserve basse** : nouvelle ligne server-only GPIO 130 en mm, vide = désactivé (opt-in préservé, repli `RESERVE_LOW_LEVEL_THRESHOLD`).
- **Lecture par table explicite** (`src/Repository/OutputMonitorRepository.php`, nouveau) : lit un GPIO dans n'importe quelle table outputs (whitelist stricte toutes familles/env), pour reconstituer la veille de N3PP/MSP hors de la table FFP3 courante.
- **Migration** `migrations/2026_07_night_sleep_and_alert_gpio.sql` : seed des lignes server-only 126-130 sur toutes les tables FFP3 (prod + variantes). Réconciliation des défauts `INIT_GPIO_BASE_ROWS.sql` : GPIO 102 `7 → 18` cm et GPIO 116 `300 → 600` s (fresh installs uniquement ; `ON DUPLICATE` ne touche pas `state`, prod préservée).
- **Rétro-compatibilité** : les nouvelles dépendances (résolveur, `OutputRepository`) sont **optionnelles** ; sans elles, le comportement `.env`/forfait historique est conservé (aucune régression des suites existantes).
- **Tests** : `OfflineThresholdResolverTest` (formule jour/nuit, bornes, familles) + deux cas `DeviceHealthServiceTest` (le seuil dérivé prime sur le forfait).
- **Note** : rendre ces lignes server-only éditables depuis l'UI de contrôle (widgets) reste un follow-up ; elles sont pour l'instant seedées avec les défauts firmware et modifiables en BDD.

## [6.14.1] - 2026-07-06

### Fix — « Erreur réseau » sur les réglages de notifications et le test d'envoi mail (pages de contrôle)
- **Symptôme** : sur les pages de contrôle (aquaponie `/aquaponie-control`, aquaponie-test, ainsi que météo/serre et galeries), modifier le niveau/les catégories de notifications ou cliquer sur « Tester l'envoi » renvoyait systématiquement **« Erreur réseau »** dans l'indicateur d'état.
- **Cause** : les handlers de route `saveNotificationPolicy` / `sendTestMail` (et leurs variantes `...BySlug`) déclaraient leurs services (`NotificationPolicySaveService`, `NotificationService`) comme **paramètres de méthode**. L'application utilise la stratégie d'invocation Slim par défaut (`RequestResponse`), **sans pont PHP-DI / ControllerInvoker** : Slim n'injecte que `($request, $response, $routeArguments)`. Le service recevait donc le tableau des arguments de route → `TypeError` → **HTTP 500** au corps non-JSON → le front (`notification-policy.js`) échouait sur `res.json()` et affichait « Erreur réseau ». Les autres écritures (toggle/parameters) n'étaient pas touchées car elles ne prennent que `(Request, Response)`.
- **Correctif** : les services sont désormais fournis par **injection au constructeur** (comme partout ailleurs dans le code) et exposés au trait `HandlesNotificationPolicy` via des accesseurs. Les handlers de route ne prennent plus que `(Request, Response)` (`+ array $args` pour les variantes galerie).
  - `src/Controller/Traits/HandlesNotificationPolicy.php` : accesseurs abstraits + handlers 2-args ; note d'architecture pour éviter la régression.
  - `src/Controller/Ffp3/OutputController.php`, `src/Controller/Msp/MspOutputController.php`, `src/Controller/N3pp/N3ppOutputController.php`, `src/Controller/Gallery/GalleryControlController.php` : injection au constructeur + accesseurs.
- **Tests** : instanciations de contrôleurs mises à jour (nouvelles dépendances) ; suite unitaire verte (830 tests).

## [6.14.0] - 2026-07-06

### Accueil — les tuiles suivent l'état des switchs de supervision
- Les **tuiles projet de la page d'accueil** (Aquaponie, Potager, Élevage, Poissonglouton, Galeries) respectent désormais la visibilité définie par les switchs (table `navPages`) : désactiver un switch masque à la fois le lien du menu **et** la tuile correspondante sur la home.
- **`src/Controller/HomeController.php`** : injecte `NavPageRepository` et passe `nav_states` (map clé → actif) au template (tolérant à l'absence de BDD → tuiles visibles).
- **`templates/home.twig`** : chaque tuile est conditionnée par `{% if nav_states['<clé>'] ?? true %}` (clés `aquaponie`, `potager`, `elevage`, `pgl`, `gallery`). Défaut visible si la clé n'est pas renseignée ; l'opérateur `??` ne masque que sur un état explicitement désactivé.

## [6.13.0] - 2026-07-06

### Supervision & galeries — switch Utilisateurs, galeries pilotées sur la page /gallery
- **Switch « Utilisateurs »** : le lien `/admin/users` du menu est désormais piloté par un switch de supervision (clé `admin-users`, seedée active), tout en restant **filtré par la permission `canManageUsers`** (`src/Service/TemplateRenderer.php` + `src/Controller/NavPageController.php`). Le lien codé en dur a été retiré de `templates/partials/_nav.twig`.
- **Galeries pilotées sur la page `/gallery` (et non le menu)** : les clés `gallery-<slug>` et `gallery-control-<slug>` sont **exclues du menu** (`NavPageRepository::getActivePages` ignore le préfixe `gallery-`) et pilotent l'affichage de chaque galerie et de son lien de contrôle caméra sur la page **`/gallery`** :
  - **`src/Controller/Gallery/GalleryViewController.php`** : `showIndex` filtre les galeries selon `navPages` (masquée si `gallery-<slug>` désactivée) et expose `show_control`/`control_url` par galerie.
  - **`templates/gallery_landing.twig`** : lien « Contrôle caméra » par galerie, affiché si le switch est actif **et** l'utilisateur a l'accès contrôle (`can_access_control`).
- **Menu de navigation** : ne conserve qu'un **lien unique « Galeries »** (`/gallery`) ; plus aucun lien de galerie individuelle.
- **Page d'accueil** (`templates/home.twig`) : les 3 liens de galeries individuelles sont remplacés par un **lien unique « Voir toutes les galeries »** vers `/gallery`.
- **Page de supervision** (`templates/supervision.twig`) : nouvelle **section « Poissonglouton »** dédiée (extensible) regroupant le switch `/pgl` ; section « Galeries photo » reformulée (les switchs pilotent la page `/gallery`) ; ajout du switch `admin-users` dans « Administration ».
- **`src/Repository/NavPageRepository.php`** + **`migrations/2026_07_nav_pages_gallery_users.sql`** : seed étendu (`admin-users`, `gallery-*`) et migration idempotente pour les installations existantes.

## [6.12.1] - 2026-07-06

### Doc — secret HMAC PGL dans `.env.docker.example`
- **`.env.docker.example`** : ajout de `PGL_API_SIG_SECRET` (optionnel, repli sur `API_SIG_SECRET`) pour les tests Docker locaux des endpoints Poissonglouton, aligné sur `firmwires/poissonglouton/include/secrets.h`.

## [6.12.0] - 2026-07-06

### Supervision — switchs du menu de navigation persistés côté serveur (état global)
- **Contexte** : les switchs de la page de supervision (afficher/masquer une page dans la barre de navigation) étaient stockés en `localStorage` (par navigateur), donc invisibles pour les autres visiteurs et « off » par défaut alors que certaines pages figuraient en dur dans le menu. Désormais l'état est **serveur, global et permanent** : un changement s'applique à **tous** les visiteurs (y compris non connectés).
- **`migrations/2026_07_nav_pages.sql`** + **`src/Repository/NavPageRepository.php`** : nouvelle table **globale** `navPages` (`page_key`, `label`, `url`, `active`, `sort_order`, `updated_at`, `updated_by`). Le repository crée/sème la table à la volée si absente (pages historiques du menu activées par défaut). Pas de suffixe d'environnement (menu commun).
- **`src/Controller/NavPageController.php`** + **`public/index.php`** : endpoint `POST /api/nav-pages/toggle` (corps JSON `{key,label,url,active}`) qui active/désactive une page. **Réservé aux administrateurs** (`canAccessControl`) et protégé par **CSRF**.
- **`src/Middleware/CsrfMiddleware.php`** : `/api/nav-pages/toggle` ajouté à la liste positive protégée.
- **`src/Service/TemplateRenderer.php`** + **`config/dependencies.php`** : les pages actives sont injectées (`nav_pages`) dans **chaque** rendu (tolérant à l'absence de BDD → menu vide plutôt que 500).
- **`templates/partials/_nav.twig`** : le menu est désormais rendu **côté serveur** à partir de `nav_pages` (entièrement pilotable par les switchs). Les liens Admin / Utilisateurs restent conditionnés aux permissions (jamais désactivables) pour ne pas verrouiller l'accès à la supervision.
- **`templates/supervision.twig`** + **`src/Controller/SupervisionController.php`** : les switchs reflètent l'état serveur au chargement (`nav_states`) ; ajout des switchs Poissonglouton (`/pgl`) et Galeries (`/gallery`) ; switchs en lecture seule pour les non-admins.
- **`public/assets/js/page-nav-toggles.js`** : réécrit — plus de `localStorage`. POST CSRF vers l'endpoint, reflet optimiste + revert en cas d'échec, mise à jour live du menu (desktop + panneau mobile).
- **Note** : le lien « Aquaponie » du menu n'est plus dépendant de l'environnement courant (pointe vers `/aquaponie`).

## [6.11.0] - 2026-07-06

### Sécurité — validation HMAC-SHA256 des endpoints Poissonglouton
- **`src/Controller/Concerns/PglHmacAuthTrait.php`** (nouveau) : trait d'authentification HMAC pour PGL. Compose `HmacAuthTrait` (contrat commun FFP3/N3PP/MSP : `timestamp`+`signature` dans le body **ou** en-têtes `X-Sig-*` signant le corps complet) et l'adapte à PGL — clé dédiée `PGL_API_SIG_SECRET` avec repli sur le secret commun `API_SIG_SECRET` (miroir de `PGL_API_KEY` / `API_KEY`), extraction du contexte de body-signing (en-têtes `X-Sig-*` + corps brut capté par `RawPostBodyMiddleware`), et `requiresApiKey()`.
- **`src/Controller/Pgl/PglPostDataController.php`** et **`src/Controller/Pgl/PglHeartbeatController.php`** : valident désormais la signature HMAC (prioritaire), avec repli sur `api_key` legacy si le firmware n'envoie pas de signature. Respecte `HMAC_STRICT_MODE`. Comble la lacune structurelle où `/pgl/post-data` et `/pgl/heartbeat` n'acceptaient que `api_key` alors que le firmware pouvait déjà signer (`PGL_API_SIG_SECRET`).
- **`src/Controller/Concerns/HmacAuthTrait.php`** : point d'extension `hmacSecret()` (par défaut `API_SIG_SECRET`) pour permettre à PGL de surcharger la source du secret. Comportement N3PP/MSP inchangé.
- **`.env.example`** : documente `PGL_API_SIG_SECRET` (secret HMAC dédié optionnel, repli `API_SIG_SECRET`).
- **`docs/ENDPOINTS_ESP32_SERVEUR.md`** : PGL passe de « HMAC non validé côté serveur » à « HMAC validé (contrat FFP3/N3PP/MSP), repli api_key ».
- **Tests** : `tests/Controller/Pgl/PglPostDataControllerTest.php` (+6 cas : HMAC valide sans api_key, signature invalide → 401, secret serveur manquant → 500, clé PGL dédiée prioritaire, en-têtes `X-Sig-*`, mode strict) et `tests/Controller/Pgl/PglHeartbeatControllerTest.php` (+2 cas HMAC).
## [6.10.4] - 2026-07-06

### Correctif — restriction admin des variantes clear-cache par environnement (CI rouge)

- **`config/routes_config.php`** : les chemins `/admin/clear-cache-test`, `/admin/clear-cache3`, `/admin/clear-cache3-test` et `/admin/clear-cache-s3-test` n'étaient **pas** réellement réservés aux admins. `RoleAccessService::pathStartsWith()` exige une frontière (`/` ou fin) après le préfixe : `/admin/clear-cache` ne couvre donc pas les suffixes `-test`/`3`/… (caractère suivant `-` ou chiffre). Ces variantes retombaient sur le rôle operator par défaut. Chaque variante d'environnement est désormais listée explicitement (même schéma que les préfixes `reader` pour les dashboards). Corrige l'échec `RoleAccessServiceTest::testSupervisionMaintenanceActionsAreAdminOnly` introduit en 6.10.3 (CI rouge sur `master`). Les pages d'affichage `clear-cache-page*` restent volontairement operator (l'action POST `clear-cache` est, elle, admin + CSRF).

## [6.10.3] - 2026-07-06

### Supervision réservée aux admins + clear-cache en POST/CSRF

- **`/supervision` réservée aux administrateurs** : ajout de `/supervision` (et des actions de maintenance qu'elle expose : `/admin/clear-cache*`, `/admin/api/gallery/auto-sort-all`, `/admin/deploy-script`) à `role_requirements['admin']` dans `config/routes_config.php`. Auparavant accessibles au rôle operator (défaut). Le lien « Admin » de la barre de navigation (`partials/_nav.twig`) est désormais conditionné à `can_manage_users` (admin) au lieu de `is_admin` (operator+).
- **`/admin/clear-cache*` passe en POST + CSRF** : la route était un **GET à effet de bord** (falsifiable en cross-site). Elle est désormais **POST** (`config/routes_helpers.php`) protégée par `CsrfMiddleware` (motif `#/admin/clear-cache(?!-page)[0-9a-z-]*$#`, `clear-cache-page` reste un GET d'affichage). Les trois déclencheurs front envoient l'en-tête `X-CSRF-Token` : page supervision, page `admin/cache_admin.twig` et fallback HTML de `CacheController`. Ajout du `<meta name="csrf-token">` à `layout_base.twig` (utilisé par cache_admin). Le mode token (`?token=`) reste valable sur la requête POST (exempté de CSRF). Doc `docs/CLEAR_CACHE_OPTIONS.md` mise à jour (curl `-X POST`).
- **Grille Live — anti-spam lecteurs d'écran** : `updateCard` (supervision) n'écrit dans le DOM que si la valeur change, pour que l'`aria-live` de la grille ne ré-annonce plus les 8 cartes à chaque poll (15 s).
- **Tests** : `RoleAccessServiceTest` mis à jour (supervision + actions de maintenance = admin only).

## [6.10.2] - 2026-07-06

### Audit page de supervision — perf, fuseau horaire, CSRF

- **Perf `getSystemHealth()` MSP1/N3PP** : `AbstractSensorRealtimeDataProvider::calculateUptime()` faisait `fetchBetween()` (`SELECT *` sur 30 jours) puis `count()` en PHP — soit ~20 000 lignes rapatriées en mémoire à **chaque** appel santé, alors que la grille Live de `/supervision` (et la home) polle ces endpoints toutes les 15 s. Ajout de `AbstractSensorRepository::countReadingsBetween()` (COUNT SQL, même filtre qualité que `fetchBetween`) et bascule de `calculateUptime()` dessus. Aligne le comportement sur FFP3 (qui utilisait déjà un COUNT). Semantique inchangée (le test d'intégration `countReadingsBetween == count(fetchBetween)` reste vrai).
- **Fuseau horaire grille Live** (`templates/supervision.twig`) : `formatDatetime` interprétait `last_reading` (heure murale Europe/Paris, sans offset) dans le fuseau du **navigateur**, incohérent avec le reste du site (affichage Africa/Casablanca via `DisplayTime`). Désormais on privilégie l'epoch serveur `last_reading_ts` formaté en Africa/Casablanca via `Intl` (même pattern que `realtime-updater.js`, gère le DST, sans dépendance), avec repli legacy.
- **CSRF `/admin/api/gallery/auto-sort-all`** : cette écriture d'état (déplacement de photos en corbeille), déclenchée depuis la page supervision, n'était pas couverte par `CsrfMiddleware`. Ajout du motif à la liste positive ; le bouton envoie désormais l'en-tête `X-CSRF-Token` (lu depuis `<meta name="csrf-token">`).
- **Nettoyage / robustesse** : retrait de la variable `admin_cache_token` (passée par `SupervisionController` mais jamais lue par le template) ; gardes de nullité sur les handlers des boutons `clearAllCacheBtn` / `runGallerySortBtn`.

## [6.10.1] - 2026-07-06

### Notifications — bouton « Tester l'envoi » aussi sur les pages galerie
- **`src/Controller/Gallery/GalleryControlController.php`** : handler `sendTestMailBySlug()` (valide le slug, fixe le contexte, délègue au trait `sendTestMail`) — auth par slug identique à la politique de notification galerie.
- **`config/routes_gallery.php`** : route `POST /gallery/{slug}/api/outputs/test-mail`.
- **`templates/gallery_control.twig`** : bouton activé (`test_mail: true`). Le JS (`notification-policy.js`) et `window.CONTROL_API_BASE` (`/gallery/{slug}/api/outputs`) étaient déjà chargés via le layout `_control_base.twig` → l'URL de test se résout correctement par slug.

## [6.10.0] - 2026-07-06

### Notifications — bouton « Tester l'envoi (mail serveur) » sur les pages de supervision
- **`src/Service/NotificationService.php`** : nouvelle méthode `sendTestMail(?string $family)` qui envoie un e-mail de test au destinataire configuré (`NOTIF_EMAIL_RECIPIENT`) en **contournant** la politique, l'anti-spam et le digest — le but est de vérifier la configuration d'envoi (SMTP ou repli `mail()`) quel que soit le mode courant. Ajout d'un getter `recipient()`.
- **`src/Controller/Traits/HandlesNotificationPolicy.php`** : handler `sendTestMail()` (même auth/CSRF que la politique de notification) → JSON `{success, recipient, message}` ou `{error}` (400 si aucun destinataire, 502 si l'envoi échoue).
- **`config/routes_helpers.php`** : routes POST `.../api/outputs/test-mail` pour FFP3, MSP1 et N3PP (parité avec `notification-policy`).
- **`templates/partials/_notification_policy.twig`** + **`public/assets/js/notification-policy.js`** : bouton « ✉️ Tester l'envoi (mail serveur) » (affiché via le flag `test_mail`) avec indicateur d'état ; POST CSRF/token calqué sur la sauvegarde de politique. Activé sur `control.twig`, `msp1_control.twig`, `n3pp_control.twig` (pas les galeries).
- **`tests/Service/NotificationServiceTest.php`** : couverture de `sendTestMail` (envoi + sujet `[FAMILLE][P3]`, contournement du mode `none`, getter `recipient()`).

## [6.9.0] - 2026-07-06

### Notifications — mode gradué poussé au firmware FFP3 (harmonisation flotte)
- **`src/Repository/NotificationPolicyRepository.php`** (`mailNotifValueForFirmware`) : la famille **FFP3** reçoit désormais le **mode de notification gradué** (`none`/`important`/`partial`/`full`) sur le GPIO `mailNotif` (101), au lieu du booléen `'1'/'0'`. Elle s'aligne ainsi sur MSP1/N3PP (qui poussaient déjà le mode). Le firmware ffp5cs (≥ 15.04) parse ce mode via la lib partagée `n3_notify` et filtre chaque mail par sévérité P1-P4.
- **`src/Config/NotificationPolicyGpioMap.php`** : commentaire mis à jour (le GPIO mailNotif porte le mode gradué pour toutes les familles).
- ⚠️ **Déploiement** : mettre à jour le firmware **ffp5cs (OTA) AVANT** ce serveur. Un ffp5cs ≤ 15.03 interprète `important`/`partial`/`full` comme « faux » (booléen) et couperait ses alertes. Aucun impact MSP1/N3PP (déjà en mode gradué) ni sur les galeries.
- Aucun changement de schéma ni de contrat pour les autres familles ; les tests d'`OutputParameters` (fixtures `'1'/'0'` directes) ne passent pas par cette dérivation et restent verts.

## [6.8.7] - 2026-07-06

### Notifications — migrations versionnées des tables auto-créées
- **`migrations/2026_07_notification_log.sql`** + **`migrations/2026_07_notification_digest.sql`** : versionnent explicitement les tables `notification_log` (anti-spam / cooldown de `App\Notification\AlertThrottler`) et `notification_digest` (file du digest P3/P4 de `App\Notification\NotificationDigest`), jusqu'ici seulement créées à la volée par le code (`ensureTableExists()`). Schéma strictement identique, idempotent (`CREATE TABLE IF NOT EXISTS`).
- **`docker/mysql/init/00-schema.sql`** : ajout des deux tables au schéma d'init Docker (à côté de `error_alerts`) pour l'aligner sur la prod / les tests d'intégration.
- **`migrations/README.md`** : référencement des deux nouvelles migrations.
## [6.8.6] - 2026-07-05

### Correctif CI — tri des imports (cs:check)
- **`src/Controller/Concerns/LegacyHeartbeatHandler.php`** : `use App\Middleware\RawPostBodyMiddleware;` remonté avant les `use App\Security\*` pour respecter la règle `ordered_imports` (tri alpha) de `.php-cs-fixer.php`. Débloque le job « Tests & qualité » (`cs:check`) rouge depuis la v6.8.1, qui masquait les étapes PHPStan et PHPUnit.

### Outillage — hook SessionStart (Claude Code web)
- **`.claude/hooks/session-start.sh`** + **`.claude/settings.json`** : lance `composer install` (dépendances dev incluses) au démarrage des sessions Claude Code sur le web, pour que `php-cs-fixer` / `phpstan` / `phpunit` soient disponibles et que le qa-gate soit jouable localement. Web-only, idempotent, non-interactif et tolérant aux échecs réseau (n'empêche jamais le démarrage de la session).

## [6.8.5] - 2026-07-05

### Validation prod — script tolerant aux tables absentes
- **`99_validate_prod.sql`** : plus de `SHOW COLUMNS FROM ffp3Data` ni `SELECT` directs sur tables optionnelles ; inventaire via `INFORMATION_SCHEMA` + requêtes préparées conditionnelles (PGL, GPIO, échantillon données).

## [6.8.4] - 2026-07-05

### Correctif — script élagage N3PP prod (connexion BDD)
- **`tools/run-prod-prune-n3pp.php`** : exécution via PDO + `Env::load()` (Dotenv) au lieu du parsing bash du `.env` ; option `--test-connection`.

## [6.8.3] - 2026-07-05

### Script prod — élagage N3PP automatique
- **`tools/run-prod-prune-n3pp.sh`** : collecte LEAD par fenêtres d'id, boucle DELETE jusqu'à épuisement, niveaux 2a–2b ; options `--with-indexes`, `--with-validate`, `--dry-run`.

## [6.8.2] - 2026-07-05

### Migrations prod — élagage N3PP par lots
- **SQL** : `2026_07_PROD_03b_prune_n3pp_double_post_batched.sql` (double-POST N3PP en fenêtres LEAD + DELETE par lots de 3000) ; `2026_07_PROD_03c_prune_level2.sql` (niveaux 2a–2b).
- **03** : requête monolithique 1a commentée (évite #2006 « MySQL server has gone away » sur ~1,8 M lignes via phpMyAdmin).

## [6.8.1] - 2026-07-05

### Compléments plan BDD — exécution locale et doc
- **SQL** : split S3 (`2026_07_PROD_01a_s3_migrate.sql`) / GPIO (`2026_07_PROD_01`) ; scripts exécutés sur Docker local (GPIO OK).
- **Filtrage affichage** : `N3ppSensorRepository::qualityFilterSql()` (exclut `sensor=msp1`, DHT 0/0) ; `STATS_EXCLUDE_DHT_FALLBACK` pour graphiques FFP3.
- **Doc** : `ENDPOINTS_ESP32_SERVEUR.md` (N3PP/MSP, S3 `*S3`, matrice GPIO 106/107), `INVENTAIRE_APPAREILS_IOT.md`, `ADR_DOUBLE_STOCKAGE_CONFIG.md`, tableau README.

## [6.8.0] - 2026-07-05

### Plan d'action BDD — exécution vagues 0–3 (serveur)
- **Scripts SQL prod** : `2026_07_PROD_01` (migration S3 Option A + GPIO N3PP/MSP + align notifs 101←108), `02` (DROP orphelines), `03` (élagage qualitatif niv. 1–2), `04` (indexes).
- **Contrat firmware** : `N3ppPostDataController` sync `etatPompe` → GPIO 12 ; `mailNotifValueForFirmware()` écrit le mode réel sur GPIO 101 (MSP/N3PP).
- **Heartbeat legacy** : `LegacyHeartbeatHandler` supporte les en-têtes `X-Sig-*` (parité post-data).
- **GPIO maps** : GPIO 110 `resetMode` (MSP/N3PP), GPIO 117 `forcePompeAqua` server-only (FFP3) ; `allowedGpios()` dérivé des `*GpioMap`.
- **Docker** : seed MSP sans GPIO 2 ; init `gallerySyncSessions` ; `sensors_present` sur `pglHeartbeat`.
- **Rapport** : `migrations/reports/diagnostic_2026_07.md`.

### Firmwares alignés (submodule firmwires)
- **n3pp 4.50** : URLs Slim, heartbeat, HTTPS par défaut.
- **msp 2.49** : idem.

## [6.7.4] - 2026-07-05

### Documentation — Élagage qualitatif BDD (rejet rétention temporelle)
- **`docs/PLAN_ACTION_BDD_2026_07.md`** : stratégie de rétention 12/24 mois **annulée** ; remplacée par **Annexe B — Élagage qualitatif** (chronologie intacte, suppression du bruit uniquement).
- **Nouvelles actions** : P0-U07 (DROP `ffp3Data4` post-migration), P0-U08 (élagage niv. 0–1), P0-U09 (comptage double-POST), P2-11 (élagage niv. 2) ; P5-05 → **P5-05bis** (gouvernance élagage).
- **Niveau 3 hors périmètre** : fallback FFP3 `TempAir=20` / `Humidite=50` **conservés** en BDD (filtrage affichage/stats, pas de DELETE). Phase D rétention temporelle marquée **ANNULÉE**.

## [6.7.3] - 2026-07-05

### Documentation — Plan d'action BDD juillet 2026
- **`docs/PLAN_ACTION_BDD_2026_07.md`** : formalisation du plan consolidé (37 actions / 6 phases) issu des audits schéma serveur, firmware et dump prod du 05/07/2026.
- **Arbitrages verrouillés** : migration S3 Option A (`ffp3Data4` → `ffp3DataS3`), GPIO N3PP pompe 12 / MSP sans pompe (GPIO 111 = ServoModeAuto), notifications Option B (GPIO 101 mode réel, 108/109 server-only) ; action P1-09 ajoutée.
- **Annexe volumétrie** : analyse du dump `oliviera_iot` (719 Mo, ~3,74 M lignes) — pertinence pédagogique, ratio signal/bruit, politique de rétention 12/24 mois, tables à exclure de l'import local.

## [6.7.2] - 2026-07-05

### Correctif — Pages meteo-control / serre-control
- **N3PP GPIO actionneurs** : alignement BDD/UI sur GPIO 12 (pompe) et 13 (arrosage manuel) ; whitelist `allowedGpios` étendue ; acquittement one-shot GPIO 13 ; migration `FIX_N3PP_GPIO_ACTUATORS_2026_07.sql` + seed Docker.
- **WakeUp (GPIO 106)** : inversion UI « Mode économie » (coché → `0` = veille) sur MSP et N3PP.
- **Validation paramètres** : bornes `SeuilSec` 0–4095, `HeureArrosage` 0–23, `tempsArrosage` 1–20 s, `FreqWakeUp` 1–86400.
- **UX** : callouts contextualisés MSP/N3PP, hints sync firmware, sorties MSP non pilotées, toasts polling limités aux GPIO actionneurs ; robustesse chargement MSP (`getAllForBoard` dans try/catch).
- **Doc** : section GPIO actionneurs N3PP dans `docs/API_MSP1_N3PP.md`.
- **Tests** : toggle GPIO 12 N3PP, ack GPIO 13, validations paramètres MSP/N3PP.

## [6.7.1] - 2026-07-05

### Documentation & config — Alignement secrets / exemples / doc
Suite à l'audit des fichiers non versionnés (`secrets.h`, `secrets_config.h`, `credentials.h`, `.env`) :
- **`.env.example`** : chemins HMAC corrigés (FFP5CS → `secrets_config.h`, n3pp/msp/CAM → `credentials.h`) ; note auth PGL (`api_key` seule) ; variables optionnelles documentées (`LOG_*`, rate-limit firmware, sync galerie).
- **`.env.docker.example`** : aligné sur les clés critiques (`PGL_API_KEY`, `NOTIF_MODE`, HMAC, CRON, OTA, sécurité HTTP).
- **`env.test.example`** : réécrit en gabarit minimal pointant vers `.env.example` ; retrait de `CACHE_ENABLED` (non lu par le runtime).
- **`docs/SECURITE_ROTATION_API_KEY.md`**, **`docs/GUIDE_ACTIVATION_CONFIG.md`**, **`docs/ETAT_CONFIG_NON_EFFECTIVE.md`**, **`docs/API_MSP1_N3PP.md`**, **`docs/ENDPOINTS_ESP32_SERVEUR.md`** : chemins secrets corrigés ; recensement HMAC n3pp/msp, HMAC PGL non implémenté serveur, variables legacy `REALTIME_*`/`PWA_*`/`PGL_STATS_TOKEN`.
- **Submodule firmwires** (doc alignée) : `poissonglouton/README.md`, `poissonglouton/include/secrets.h.example`, `credentials.h.example`, `firmwires/CLAUDE.md`, `firmwires/docs/ETAT_CONFIG_NON_EFFECTIVE.md`.

## [6.7.0] - 2026-07-05

### Ajout - Alerte réserve basse opt-in (CRON horaire)
- **`SystemHealthService::checkTankLevel()`** : implémentation réelle (remplace le placeholder). Lecture de `EauReserve` via `SensorReadRepository::getLastReadings()` ; alerte si distance > seuil (`RESERVE_LOW_LEVEL_THRESHOLD`, mm). **Dormant par défaut** (variable absente/vide → log informatif uniquement). Sévérité P2/Hydraulic via `sendAlert`, clé `ffp3:reserve-low` ; aucune action pompe.
- **`.env.example`**, **`docs/deployment/CRON.md`**, **`docs/ETAT_CONFIG_NON_EFFECTIVE.md`** : documentation de la variable et statut ⚠️ (implémenté, conditionné). `notifyFloodRisk()` documenté comme code mort volontaire (doublon firmware `limFlood`).
- **Tests** : `tests/Service/SystemHealthServiceTest.php` (seuil absent, alerte, pas d'alerte).

## [6.6.2] - 2026-07-05

### Documentation - Guide d'activation pas-à-pas
- **Nouveau `docs/GUIDE_ACTIVATION_CONFIG.md`** : checklist opérationnelle pour vérifier que les fonctionnalités « workflow / mails / OTA » sont réellement effectives en prod (quoi vérifier → comment → attendu → sinon). Couvre mails/SMTP/`NOTIF_MODE`, crontab + `cronlog`, hook `post-merge`, OTA serveur + pipeline firmware (secrets GitHub, env `prod`), activation dédiée de l'OTA poissonglouton (`pgl`), et la CI des deux dépôts. Complément « comment vérifier » de `docs/ETAT_CONFIG_NON_EFFECTIVE.md`.

## [6.6.1] - 2026-07-05

### Documentation & config - Recensement des configs « non effectives »
Suite de l'audit transverse (workflow / mails) côté serveur et firmware. Aucun changement de comportement runtime.
- **Nouveau `docs/ETAT_CONFIG_NON_EFFECTIVE.md`** : recense les fonctionnalités présentes dans le code mais inactives par défaut (SMTP commenté → repli `mail()`, `NOTIF_MODE=important` qui coupe le digest P3/P4, `checkTankLevel()` placeholder, `notifyFloodRisk()` jamais appelé, CRON/hook à installer à la main, auth OTA off) avec, pour chacune, l'action d'activation.
- **`.env.docker.example`** : suppression de la variable morte `ALERT_EMAIL` (jamais lue par le code, qui utilise `NOTIF_EMAIL_RECIPIENT`) + note explicative.

## [6.6.0] - 2026-07-05

### Sécurité & contrat - Audit `uploadphotosserver` (galeries msp1/n3pp/ffp3), côté serveur
Volet serveur de l'audit firmware `uploadphotosserver` 2026-07-05 (findings A2, A4). Additif et **rétro-compatible** : un firmware qui n'envoie pas de signature reste authentifié par la seule clé API.
- **A4 — Signature HMAC additive des endpoints galerie** (`DeviceSignatureValidator`) : si le firmware envoie `X-Sig-Timestamp` / `X-Sig-Nonce` / `X-Sig-Hmac`, la signature est validée (`SignatureValidator::isValidForBody`) sur l'upload multipart (corps signé = clé API, le JPEG n'étant pas signable en streaming), sur les POST sync `start`/`finish` et sur le POST version (corps signé = corps brut form-urlencoded). Présente et invalide → 401 ; absente ou `API_SIG_SECRET` non configuré → repli sur la clé API (inchangé). Appliqué à `GalleryUploadController`, `GallerySyncController`, `GalleryControlController`.
- **A2 — Récap de transfert galerie déclenché uniquement à la clôture finale** : le firmware annonce désormais le VRAI backlog comme `total` et pose `final=1` quand le backlog est réellement vidé. `GallerySyncController::finishInternal` n'envoie le mail récapitulatif que si `final` (ou `received >= total`) — supprime le spam d'un récap par réveil pour un gros backlog drainé en plusieurs passes. La clôture de session en base reste inchangée.

## [6.5.0] - 2026-07-04

### Sécurité - Durcissement contrat d'ingestion N3PP/MSP1 (additif, rétro-compatible)
Tous les ajouts ci-dessous sont **détectés par présence** ou **désactivés par défaut** : le contrat existant (api_key, timestamp+signature dans le body) continue de fonctionner tel quel tant que la flotte n'est pas flashée / les flags activés.
- **Signature HMAC couvrant le corps** : si le firmware envoie les en-têtes `X-Sig-Timestamp` / `X-Sig-Nonce` / `X-Sig-Hmac`, l'auth (`HmacAuthTrait`) valide via `SignatureValidator::isValidForBody` (`HMAC(ts . "\n" . nonce . "\n" . body)`) → intégrité de **tout le corps** (plus seulement le timestamp) + fenêtre temporelle. En l'absence des en-têtes, comportement inchangé. `AbstractHmacPostDataController::prepareParamsForAuth` expose le corps brut + en-têtes.
- **Auth optionnelle du GET d'état firmware** : flag `FIRMWARE_STATE_REQUIRE_KEY` (défaut `false`). Activé, `AbstractOutputController::getState` exige un `X-Api-Key` (ou `?api_key=`) valide — ce GET écrit en base (ack one-shot GPIO 110, `updateLastRequest`), un tiers pouvait donc consommer une commande de reset. Le firmware envoie déjà l'en-tête.
- **Rate-limiting optionnel** des endpoints firmware (`/post-data`, `/heartbeat`) : flag `FIRMWARE_RATE_LIMIT_MAX` (défaut `0` = désactivé), fenêtre `FIRMWARE_RATE_LIMIT_WINDOW` (défaut 60 s), par IP (`RateLimiter`, fail-open).

## [6.4.0] - 2026-07-04

### Sécurité & robustesse - Audit ingestion N3PP / MSP1
- **CSRF (toggle GET → POST)** : la route `/{n3pp|msp1}/api/outputs/toggle` acceptait `GET` ; `CsrfMiddleware` traite `GET` comme méthode sûre, donc un `GET` inter-site (ex. `<img src=".../api/outputs/toggle?gpio=16&state=1">`) pouvait basculer un GPIO sur une session admin. Route restreinte à **POST** (aligné FFP3 ; l'UI `control-actions.js` émet déjà un POST + jeton CSRF).
- **Fuite d'information** : les endpoints temps réel publics (`AbstractRealtimeApiController`) renvoyaient `detail => $e->getMessage()` en 500 (texte d'exception SQL exposé). Message générique désormais ; détail journalisé côté serveur uniquement.
- **Perte de mesure** : `mail`/`mailNotif` reçus du firmware n'étaient pas tronqués (N3PP/MSP1), contrairement à FFP3 (255). Une valeur trop longue faisait échouer l'INSERT → 500 → **toute la ligne de mesure perdue**. Troncature à 255 sur les deux champs (`sanitizeFirmwareEmail` + nouveau `sanitizeFirmwareMailNotif`).
- **Parité auth heartbeat** : `LegacyHeartbeatHandler` ignorait `HMAC_STRICT_MODE` (heartbeat restait laxiste même quand `/post-data` était en mode strict). Le mode strict rejette désormais l'absence de signature HMAC, comme `HmacAuthTrait`.

## [6.3.2] - 2026-06-29

### Correctif - Erreur 500 sur /meteo et /serre (filtre Twig `localdt`)
- **Symptôme** : `GET /meteo` et `GET /serre` renvoyaient une erreur 500 après le déploiement de l'affichage Casablanca (6.3.0). `/aquaponie` restait fonctionnelle.
- **Cause** : le filtre Twig `localdt` (`DisplayTime::toDisplay`) n'acceptait que des chaînes, alors que `AbstractDataController` (MSP1/N3PP) passe `start_date`/`end_date` en `DateTimeImmutable`. PHP levait une `TypeError` au rendu du template. Aquaponie passait des chaînes → pas d'erreur.
- **Correctif** : `DisplayTime::toDisplay()` accepte désormais `string|DateTimeInterface|null` ; le filtre Twig tolère les deux types.
- **Tests** : `DisplayTimeTest` couvre le cas `DateTimeImmutable`.

## [6.3.1] - 2026-06-29

### Durcissement - Verrouillage du fuseau de session MySQL (heure serveur garantie)
- **Problème** : la connexion PDO (`App\Config\Database`) ne fixait pas `time_zone` ; la session MySQL valait donc souvent `SYSTEM` (dépendant de l'OS du serveur DB). Les horodatages écrits par MySQL (`reading_time`, `last_request` via `CURRENT_TIMESTAMP`/`NOW()`) reposaient sur une hypothèse non garantie (« le serveur DB est à l'heure de Paris »), fragile en cas de migration d'hébergement.
- **Correctif** : la connexion force désormais `SET time_zone = '<offset>'` (via `MYSQL_ATTR_INIT_COMMAND`), où `<offset>` est l'offset UTC courant d'`APP_TIMEZONE` (ex. `+02:00` en été, `+01:00` en hiver). Recalculé à chaque connexion → correct été comme hiver (DST), sans dépendre des tables de fuseaux MySQL. Repli prudent `+00:00` si le fuseau est invalide.
- **Impact** : aucun changement sur un hébergement déjà à l'heure de Paris (cas actuel) ; rend l'hypothèse explicite et corrige les hébergements dont l'OS serait en UTC ou autre. Complète l'affichage Casablanca (6.3.0).

## [6.3.0] - 2026-06-29

### Amélioration - Affichage de l'heure unifié en heure de Casablanca (cohérence des décalages)
- **Contexte** : le projet est physiquement à **Casablanca** mais les horodatages sont stockés en heure serveur (`APP_TIMEZONE=Europe/Paris`, `NOW()`/`CURRENT_TIMESTAMP`). L'UI mélangeait deux conventions : les graphiques Highcharts et les stats temps réel affichaient correctement l'heure de **Casablanca**, tandis que les en-têtes de période, les champs `datetime-local`, la « dernière réception » du live et le `last_request` des boards affichaient l'heure de **Paris** (décalage **+1 h en été**, nul en hiver — d'où un bug invisible la moitié de l'année).
- **Correctif (centralisé)** :
  - Nouveau `App\Util\DisplayTime` : `toDisplay()` (serveur→Casablanca) et `toServer()` (Casablanca→serveur), source unique du fuseau d'affichage (`Africa/Casablanca`).
  - Nouveau filtre Twig `localdt` (enregistré dans `TemplateRenderer`) : tous les `start_date|date(...)`/`end_date|date(...)` (en-têtes de synthèse, périodes, champs `datetime-local`) des pages aquaponie, MSP1, N3PP, données génériques, marées et PGL passent à `|localdt(...)` → affichage en heure de Casablanca.
  - `DateRangeExtractor` : les plages saisies (en heure de Casablanca, via `setPeriod`/`datetime-local`) sont reconverties en heure serveur avant le requêtage SQL → la fenêtre interrogée n'est plus décalée d'1 h.
  - `BoardRepository::formatTimestamp` : `last_request` affiché en heure de Casablanca.
  - API temps réel `system/health` : nouveau champ `last_reading_ts` (epoch Unix) ; `realtime-updater.js` formate la « dernière réception » en heure de Casablanca via `Intl` (robuste, sans dépendance moment, gère le DST) sur toutes les pages (y compris `control`/`dashboard` qui ne chargent pas moment-timezone).
- **Tests** : nouveau `DisplayTimeTest` (conversions été/hiver, round-trip, formats, valeurs nulles) ; `BoardRepositoryTest` mis à jour pour l'affichage Casablanca.
- **Note** : `reading_time` reste écrit par MySQL (`CURRENT_TIMESTAMP`) en heure de session (souvent `SYSTEM`) ; la connexion PDO ne verrouille pas `time_zone`. La justesse repose donc sur l'OS du serveur DB (= Paris aujourd'hui, confirmé via `SELECT @@session.time_zone, NOW();`). À vérifier après toute migration d'hébergement (cf. `docs/TIMEZONE_MANAGEMENT.md`).
## [6.2.6] - 2026-06-29

### Ajout - Capteurs heartbeat PGL (post-data)
- **API PGL** : extension `PglPostDataController` et `PglHeartbeatController` pour champs capteurs additionnels ; migration `2026_06_pgl_heartbeat_sensors.sql` ; tests PHPUnit associés.

## [6.2.5] - 2026-06-29

### Correctif - GET `outputs_state` caméra FFP3 (301 Apache sur `/ffp3/*`)
- **Symptôme** : firmware `uploadphotosserver` env `ffp3` recevait HTTP 301 sur `GET /ffp3/ffp3gallery/uploadphotoserver-outputs-action.php` (page HTML « Moved Permanently ») alors que le `POST` version sur le même préfixe passait (rewrite interne POST).
- **Cause** : `.htaccess` racine — GET `/ffp3/*` → 301 vers `/*` ; POST `/ffp3/*` → rewrite interne sans redirection.
- **Correctif** : exception GET pour `uploadphotoserver-outputs-action.php` (rewrite interne, comme POST) ; rétrocompatibilité firmwares encore sur l’ancien chemin.
- **Firmware** : chemins canoniques `/ffp3gallery/...` sans préfixe `/ffp3/` (`uploadphotosserver` v2.43).

## [6.2.4] - 2026-06-27

### Correctif - CSP bloquant la feuille de style Google Fonts + avertissement Highcharts #15 (potager)
- **CSP / Google Fonts** : la `Content-Security-Policy` par défaut (`SecurityHeadersMiddleware`) n'autorisait que `style-src 'self' 'unsafe-inline'` et `font-src 'self' data:`, ce qui bloquait la feuille de style Google Fonts (`https://fonts.googleapis.com/css2?...`) chargée par `layout.twig` (erreur navigateur « Loading the stylesheet ... violates the following Content Security Policy directive »). Ajout de `https://fonts.googleapis.com` à `style-src` et de `https://fonts.gstatic.com` (fichiers de police) à `font-src`. La directive `SECURITY_CSP` (`.env`) reste prioritaire ; le `.env.example` rappelle les origines à conserver en cas d'override.
- **Highcharts #15 (pages données N3PP / MSP1)** : `AbstractDataController` appliquait `array_reverse()` aux lectures avant `ChartDataService::prepareGenericSeries()`, alors que `AbstractSensorRepository::fetchBetween()` les renvoie déjà en ordre chronologique (ASC) et que `prepareGenericSeries()` préserve l'ordre. Résultat : timestamps décroissants transmis à Highstock → avertissement #15 (« data not sorted in ascending X order ») sur la page de données du potager (et de la station météo). Suppression du `array_reverse()` superflu : l'axe X redevient strictement croissant.
- **Tests** : `SecurityHeadersMiddlewareTest` couvre désormais les origines Google Fonts (`style-src`/`font-src`) ; nouveau `N3ppDataControllerTest` garde-fou sur l'ordre croissant des timestamps transmis au template (anti-régression Highcharts #15).

## [6.2.3] - 2026-06-27

### Correctif - Token CSRF manquant sur les interrupteurs de contrôle (mise en veille, etc.) + durcissement du système de mailing
- **Symptôme** : sur `/serre-control` (et `/meteo-control`), basculer un interrupteur — notamment la **mise en veille** (WakeUp), le mode servo ou l'éco d'énergie — échouait avec « Token CSRF invalide ou manquant » (403). Les commandes à distance étaient refusées de manière générale dès qu'on agissait via un interrupteur.
- **Cause racine** : la fonction JS `saveParamSwitch()` (partagée par les pages de contrôle) faisait un `POST .../parameters` **sans** l'en-tête `X-CSRF-Token`, alors que cet endpoint est protégé par `CsrfMiddleware` pour les sessions cookie. Les autres écritures (toggles via `control-actions.js`, champs texte via `control-auto-save.js`) envoyaient déjà le token ; seuls les interrupteurs ne le faisaient pas. Même bug dans la version galerie de `saveParamSwitch()`.
- **Correctif (front)** :
  - `templates/partials/_control_init_js.twig` (MSP1/N3PP) et `templates/gallery_control.twig` : `saveParamSwitch()` envoie désormais l'en-tête `X-CSRF-Token` (lu depuis `<meta name="csrf-token">`).
  - `public/assets/js/notification-policy.js` (nouveau système de mailing) : envoie l'en-tête `X-CSRF-Token` **et** propage le `?token=` de l'URL (parité avec les autres écritures), corrigeant aussi l'échec d'authentification (401) en accès par token.
- **Durcissement (back)** : l'endpoint `.../notification-policy` (sauvegarde de la politique de notifications) était authentifié par session mais **absent** de la liste CSRF → faille CSRF. Ajout du motif `#/api/outputs[0-9]*(-test)?/notification-policy$#` à `CsrfMiddleware`. Le mailing reste fonctionnel (la politique enregistrée est bien relue et respectée par `NotificationService` / `NotificationPolicyResolver`).
- **Audit** : les pages FFP3 (`/aquaponie-control`) étaient déjà correctes (toggles + auto-save envoient le token). Aucune autre page (PGL) concernée.
- **Tests** : `tests/Middleware/CsrfMiddlewareTest.php` étendu (protection des endpoints `notification-policy` + passage avec token valide).

## [6.2.2] - 2026-06-27

### Correctif - Erreur 500 sur /meteo-control (et /serre-control)
- **Symptôme** : `GET /meteo-control` (station météo MSP1) renvoyait une **erreur 500**. La page `/serre-control` (N3PP) était touchée de la même façon.
- **Cause racine** : la whitelist `App\Util\TableValidator::OUTPUT_TABLES` ne contenait que les tables FFP3. Au chargement de la page de contrôle, `MspOutputController::buildControlPageData()` appelle `notificationPolicyTwigData()` → `NotificationPolicyRepository::ensurePolicyRows()` → `validateTable()` → `validateOutputsTable('msp1Outputs')`, qui levait une `InvalidArgumentException` (`msp1Outputs` absent de la whitelist). Cet appel n'étant pas protégé par le `try/catch` de `buildControlPageData()`, l'exception remontait jusqu'à `AbstractOutputController::showControlPage()` → 500. Même chemin pour N3PP (`n3ppOutputs`).
- **Correctif** : ajout des tables d'outputs MSP1 et N3PP (`msp1Outputs`, `msp1OutputsTest`, `n3ppOutputs`, `n3ppOutputsTest`) à la whitelist `OUTPUT_TABLES`. La sauvegarde de la politique de notifications de ces familles fonctionne désormais également (même validation).
- **Tests** : nouveau `tests/Util/TableValidatorTest.php` (tables autorisées FFP3/MSP1/N3PP + rejet des noms inconnus/injection).

## [6.2.1] - 2026-06-27

### Correctif - Fichier VERSION malformé après merge
- Le merge de la PR #52 a résolu un conflit sur `VERSION` en **conservant les deux lignes** (`6.2.0` issu de #52 et `6.0.1` issu de #51), produisant un fichier `VERSION` à deux lignes. `App\Config\Version::get()` applique `trim()` mais pas sur le saut de ligne interne → la version exposée devenait `6.2.0\n6.0.1` (affichée `v6.2.0\n6.0.1` sur les pages).
- `VERSION` ramené à une seule valeur propre. Aucune modification de code applicatif.

## [6.2.0] - 2026-06-27

### Fonctionnalité - Horodatage de capture des photos de galerie (mode offline) + classement N-first
- **Problème** : le serveur horodatait chaque photo avec l'**heure de réception** (`date('Y-m-d_H-i-s')`), et ce nom de fichier sert de clé de tri et d'heure affichée. Conséquence du mode offline (v6.1.0) : une photo capturée hors-ligne et drainée plus tard était classée/datée à l'heure d'upload, pas de capture.
- **Nouveau nom de fichier** : `<seq10>_<Y-m-d_H-i-s>_<hex>.jpg` quand le firmware fournit les en-têtes, sinon legacy `<Y-m-d_H-i-s>_<hex>.jpg`.
  - `X-Capture-Seq` : compteur monotone du firmware, placé **en tête** (zéro-paddé sur 10) → **classement robuste même si l'heure est fausse/inconnue**.
  - `X-Captured-At` : heure de **capture** (format `Y-m-d_H-i-s`, heure locale appareil), validée + bornée (2020–2100) ; utilisée pour le segment date à la place de l'heure de réception. Fallback réception si absente/invalide.
- **`GalleryUploadController`** : lecture/validation des en-têtes (`buildFilename` / `resolveCaptureDate` / `resolveCaptureSeq`).
- **`GalleryViewController`** : `extractTimestampFromFilename` tolère le préfixe `<N>_` ; nouveau tri `sortNewestFirst` (par compteur décroissant, N-first avant legacy) en remplacement du `rsort` brut → ordre de capture respecté, transition douce ancien/nouveau format sans renommage.
- **Compat** : rétro-compatible (sans en-têtes = comportement historique) ; aucune migration des photos existantes requise. Contrat couplé au firmware uploadphotosserver ≥ 2.41.

## [6.1.0] - 2026-06-26

### Fonctionnalité - Synchronisation hors-ligne des galeries photo (uploadphotosserver)
- **Contexte** : renforcement du mode hors-ligne des firmwares ESP32-CAM (msp1/n3pp/ffp3). La carte SD de la caméra sert de file d'attente locale ; au retour du WiFi, le firmware pousse au serveur les photos qui n'y sont pas encore. Côté serveur : indicateur de connexion, jauge de progression (X/Y) et mail récapitulatif de fin de transfert.
- **Nouveau contrat firmware ↔ serveur (sessions de sync)** :
  - `POST .../sync/start` (auth `api_key` device) : ouvre une session en annonçant `total` photos en attente ; renvoie l'identifiant `session`.
  - `POST upload.php` avec en-tête `X-Sync-Session: <id>` : chaque photo reçue incrémente le compteur de la session (y compris les mises en corbeille auto, code 202).
  - `POST .../sync/finish` (auth `api_key` device) : clôture la session (`sent`/`failed`/`bytes`), déclenche le mail récap.
  - `GET .../sync/status` (auth utilisateur) : état JSON pour l'indicateur + la jauge (poll navigateur).
  - Routes unifiées `/gallery/{slug}/api/sync/*` + alias legacy `.php` par cible (compat firmware).
- **Stockage** : nouvelle table `gallerySyncSessions` (migration `migrations/CREATE_GALLERY_SYNC_SESSIONS_TABLE.sql`), une ligne par session, idempotente sur `(slug, device_session)`. Accès via `GallerySyncRepository`.
- **Mail récap** : `NotificationService::sendGalleryTransferReport()` — envoi immédiat (jamais en digest), catégorie **Camera**, sévérité P3/Info si complet, P2/Alerte en cas d'échecs/transfert incomplet ; anti-spam par session. Reste soumis à la politique de notification de la famille `GALLERY_*`.
- **UI** : page de contrôle de galerie (`/gallery/{slug}/control`) enrichie d'un panneau « Transfert hors-ligne » (point de connexion + jauge X/Y + compteur d'échecs), rafraîchi par poll (`gallery_control.twig`).
- **Config** : `GALLERY_SYNC_ONLINE_WINDOW_SECONDS` (défaut 1500 s) pour la fenêtre « en ligne ».
## [6.0.1] - 2026-06-25

### Correctif - Tests obsolètes et défaut `sleepTime` galerie (suites de la politique de notifications v5.9.1)
- **Régression `GalleryControlRepository`** : la refonte v5.9.1 avait collapsé les défauts par paramètre en un `'0'` générique, perdant `sleepTime → '600'` (défaut firmware FreqWakeSec). Rétabli via une table `PARAM_DEFAULTS` explicite — la page contrôle galerie réaffiche 600 s quand le paramètre est absent en base.
- **Tests de caractérisation one-shot** mis à jour pour le contrat « GPIO 108/109 server-only MSP/N3PP » introduit en v5.9.1 (`notifMode` / `notifCategories`, exclus des réponses firmware) :
  - `MspOutputRepositoryTest::testGetStateForFirmwareAcksOneShotGpio` : utilise le GPIO **110** (reset, one-shot non server-only) au lieu de 108.
  - `OutputFirmwareStateTest` : le double générique surcharge `getServerOnlyGpios()` → `[]` pour tester le mécanisme one-shot lui-même (GPIO 108) sans la politique de notifications.
- Ces 4 tests échouaient déjà sur `master` (sans rapport avec le nourrissage) ; la CI Unit repasse au vert.

## [6.0.0] - 2026-06-25

### Changement de contrat (BREAKING) - Nourrissage manuel : compteur monotone simple et robuste
- **Problème** : le nourrissage manuel (v5.10.x) reposait sur un front montant 0→1 détecté par le firmware sur un *niveau* sondé toutes les 6 s. Pour fabriquer ce front, le navigateur enchaînait `reset(0)` → pause 800 ms → `trigger(1)`, puis attendait l'acquittement (retour à 0) avec timeout 45 s ; le firmware renvoyait un POST de reset, protégé par une fenêtre de priorité de 20 s. Beaucoup de pièces mobiles → flags bloqués à `1`, commandes perdues, faux « Timeout ».
- **Nouveau contrat « compteur monotone »** (aligné firmware ffp5cs **15.0**) :
  - Le `state` des GPIO **108** (`bouffePetits`) / **109** (`bouffeGros`) est désormais un **entier croissant** = nombre total de repas demandés sur le canal. Le serveur ne le remet **jamais** à zéro.
  - `POST /api/outputs*/trigger-feed` prend `{ id, gpio }` (plus de `step`) et fait simplement `state = state + 1` ; réponse `{ success, gpio, counter, feed_cmd_id }`. Cliquer N fois = nourrir N fois.
  - Le firmware mémorise son propre compteur exécuté en NVS et rattrape l'écart (un repas par poll, plafonné à 5 côté firmware pour la sécurité des poissons). Web n'écrit que le compteur, le firmware ne l'écrit jamais → **aucune course bidirectionnelle**, robuste aux reboots et aux polls manqués.
- **Sécurité allégée (assumé)** : suppression de l'acquittement firmware (`ack_command` nourrissage → no-op défensif), de la séquence reset/trigger, du front 0→1 et de la fenêtre de priorité 20 s sur 108/109. Le bouton web reste protégé (session + CSRF) ; le GET d'état firmware reste public.
- **`StateNormalizer`** : 108/109 retirés des GPIO booléens (préservés en entier, plus de réduction 0/1) ; 110 (reset) reste booléen.
- **`PostDataController` / `OutputRepository`** : le POST firmware n'écrit plus 108/109 (ni via `configSynced`, ni via ack) — sinon les repas en attente seraient effacés.
- **UI contrôle** (`control.twig`, `control-actions.js`, `control-sync.js`) : bouton **« Nourrir »** à impulsion unique (un seul POST), toast `Repas demandé (#N)`, affichage du compteur ; suppression de la timeline live, du chronomètre, des phases d'acquittement, du timeout 45 s et du polling accéléré.
- **Docs** : `docs/SYNCHRONISATION_BIDIRECTIONNELLE.md` et `docs/ENDPOINTS_ESP32_SERVEUR.md` mis à jour (section nourrissage = compteur monotone).

## [5.10.1] - 2026-06-24

### Amélioration - Suivi en direct du nourrissage manuel (page contrôle FFP3)
- **Panneau live** par sortie (GPIO 108/109) : statut, chronomètre, timeline horodatée (reset → impulsion → lecture ESP32 → trace capteur → acquittement).
- **`control-sync.js`** : polling accéléré (~2 s) pendant un cycle en cours ; notification unifiée `onFeedStatesPolled` (GPIO + `dataStates`).
- **`control-actions.js`** : détection fine des phases (lecture commande, distribution enregistrée, ACK GPIO→0) ; correction faux acquittement prématuré.

## [5.10.0] - 2026-06-24

### Ajout - Nourrissage manuel FFP3 : bouton impulsion, suivi UI et traçabilité
- **Page contrôle aquaponie** : les interrupteurs GPIO 108/109 sont remplacés par un bouton **« Nourrir »** (impulsion, plus un état ON/OFF trompeur).
- **`POST /api/outputs*/trigger-feed`** : étapes `reset` (GPIO→0) puis `trigger` (GPIO→1) ; génération d'un **`feed_cmd_id`** journalisé dans `[control-audit]`.
- **`control-actions.js` / `control-sync.js`** : statuts `En attente ESP32…`, `Exécuté` (GPIO repasse à 0 au poll), `Timeout — réessayer` (~45 s).
- **`docs/SYNCHRONISATION_BIDIRECTIONNELLE.md`** : section **Nourrissage manuel** — différences FFP3 (edge firmware) vs MSP/N3PP (ack au GET, GPIO homonymes).

## [5.9.1] - 2026-06-24

### Correctif - Migration GPIO politique notifications (prod)
- **`migrations/2026_06_notification_policy_gpio.sql`** : retrait de la colonne `description` (absente du schéma prod `ffp3Outputs`) ; INSERT explicites sans `SELECT *` pour les tables test.

## [5.9.0] - 2026-06-24

### Ajout - Politique de notifications configurable par page de contrôle
- **Pages contrôle** (FFP3, MSP1, N3PP, galeries) : remplacement de l'interrupteur booléen par un **menu à choix multiples** (Aucun / Important / Partiel / Complet) et des **cases à cocher par catégorie** d'alerte.
- **Couplage firmware + serveur** : le mode « Aucun » coupe `mailNotif` côté ESP32 ; les autres modes l'activent et appliquent `NOTIF_MODE` équivalent côté serveur.
- **`NotificationPolicyResolver`** : politique par famille (BDD, GPIO server-only 124-125 FFP3 / 108-109 MSP-N3PP / 107-108 galeries) avec repli sur `.env` global.
- **API** : `POST …/api/outputs/notification-policy` (auth requise).
- **Migration** : `migrations/2026_06_notification_policy_gpio.sql` ; seed Docker aligné.

## [5.8.0] - 2026-06-24

### Ajout - GPIO 117 : sélecteur 3 modes de pilotage de la pompe aquarium
- **Auto / Forcer ON / Forcer OFF** (encodage `0` / `1` / `2`, rétro-compatible : 0 et 1 inchangés).
  - **Forcer OFF** (mode maintenance) : le serveur épingle GPIO 16 à 0 à chaque POST, exactement
    comme Forcer ON épingle 16 à 1. **Aucune modification firmware** : la pompe aquarium suit
    strictement le GPIO 16 du serveur (vérifié — pas de logique autonome côté ESP32).
- **`StateNormalizer`** : GPIO 117 traité en tri-état (0/1/2) au lieu de booléen.
- **`OutputRepository::getAquariumPumpForceMode()`** + `syncStatesFromSensorData` : épinglage 16=1
  (ON) / 16=0 (OFF) / écho `etatPompeAqua` (Auto).
- **`OutputService::updatePumpForceMode()`** ; **`OutputController`** : toggle 117 accepte 0/1/2,
  blocage symétrique transparent (Forcer ON bloque l'arrêt, Forcer OFF bloque le démarrage).
- **`control.twig`** : sélecteur déroulant ; **`control-actions.js` / `control-sync.js`** : envoi du
  mode + reflet du mode serveur au polling.

> ⚠️ À valider sur l'environnement **TEST** (firmware réel) avant bascule prod.

### Correctif - Arrêt pompe aquarium « rejeté » : transparence du forçage GPIO 117
- **`OutputController::toggleOutput`** : quand le forçage « pompe aquarium ON » (GPIO 117) est
  actif, une demande d'arrêt (GPIO 16 → 0) ne renvoie plus un faux « Commande envoyée ». La
  réponse JSON porte désormais `blocked: true` + `message` explicite ; l'audit trace la raison.
- **`public/assets/js/control-actions.js`** : affiche un avertissement clair (toast) au lieu d'un
  succès trompeur lorsque le serveur signale `blocked`.

### Correctif - Nourrissage manuel non pris : handshake d'acquittement fermé côté serveur
- **`PostDataController`** : les POST d'acquittement firmware (`ack_command=bouffePetits`/`bouffeGros`,
  `sendCommandAck`) remettent immédiatement le flag GPIO 108/109 à 0 (priorité 0, hors fenêtre de
  protection web), sans attendre le POST périodique `configSynced`. Le firmware déclenche sur front
  montant 0→1 et dépend du serveur pour revoir un 0 : sans ce reset, le flag pouvait rester à 1
  (cooldown long voire blocage) et les commandes de nourrissage manuel n'étaient plus prises.
- Aucun changement firmware requis : le serveur exploite un signal déjà émis par FFP5CS.

> ⚠️ À valider sur l'environnement **TEST** (firmware réel) avant bascule prod.

### Correctif - Purge des lignes fantômes/doublons GPIO + contrainte anti-récidive
- **`migrations/FIX_GPIO16_NULL_DUPLICATES_2026_06.sql`** : migration idempotente qui
  (1) sauvegarde la table, (2) supprime les lignes `ffp3Outputs*` sans nom (fantômes),
  (3) déduplique en conservant une ligne par `gpio`, (4) ajoute `UNIQUE(gpio)`.
- **Contexte** : la prod présentait ~403 lignes fantômes `gpio=16` (`name`/`board` NULL,
  `state='1'`, toutes datées 2025-10-16) issues de l'ancien `PumpService`
  (`INSERT ... ON DUPLICATE KEY UPDATE` sans contrainte UNIQUE → un INSERT par écriture).
  La fuite est déjà stoppée depuis v11.38 (PumpService en UPDATE seul) ; ce script purge
  le résidu et empêche toute récidive via la contrainte `UNIQUE(gpio)`.
- **`migrations/README.md`** : entrée ajoutée dans la checklist prod.

## [5.7.0] - 2026-06-24

### Ajout - Supervision « appareil silencieux » (heartbeat) généralisée à toutes les familles
- **Avant** : seul FFP3 alertait quand un appareil cessait d'émettre (via `SystemHealthService`, sur la table de données). N3PP et MSP1 n'étaient pas couverts.
- **`src/Repository/HeartbeatMonitorRepository`** : lecture transverse de la dernière date de heartbeat (`MAX(reading_time)`) d'une table donnée, avec **whitelist stricte** de toutes les tables heartbeat (FFP3 + N3PP + MSP1, variantes de test incluses) — garde-fou anti-injection (le nom vient de `TableConfig`).
- **`src/Service/DeviceHealthService`** : logique **paramétrique par famille** (factorisée, non dupliquée). Interroge les tables `ffp3Heartbeat` / `n3ppHeartbeat` / `msp1Heartbeat` (résolues via `TableConfig::getHeartbeatTable()` / `getN3ppHeartbeatTable()` / `getMspHeartbeatTable()`). Si le dernier battement dépasse le seuil d'inactivité, route une alerte **P1 / Disponibilité** via `NotificationService::sendAlert()`.
  - **Anti-spam** : une clé de throttle par famille (`heartbeat:offline:<family>`) ⇒ l'`AlertThrottler` dé-duplique, jamais de spam à chaque cycle CRON.
  - **Anti-bruit** : une table sans aucun heartbeat (famille non déployée) est ignorée ; on n'alerte que sur un historique devenu obsolète. Lecture en échec = fail-safe (log, pas d'alerte).
  - Seuil configurable via `HEARTBEAT_OFFLINE_THRESHOLD_SECONDS` (défaut 3600 s).
- **`CronOrchestrator`** : `DeviceHealthService::checkAllFamilies()` câblé dans les tâches horaires (exécuté à chaque cycle dû), routant via le `NotificationService` (transport SMTP/digest de la 5.6.0). Câblage explicite dans `config/dependencies.php`.
- **Tests** : `DeviceHealthServiceTest` (silencieux/en ligne/jamais vu, 3 familles, fail-safe, seuil env) + `HeartbeatMonitorRepositoryTest` (lecture SQLite, whitelist) ; `CronOrchestratorTest` aligné sur la nouvelle dépendance.

## [5.6.0] - 2026-06-24

### Ajout - Transport e-mail fiable (SMTP via symfony/mailer) + e-mails HTML Twig + digest P3/P4
- **`src/Notification/MailTransport`** : abstraction de transport e-mail (découple `NotificationService` du mécanisme d'envoi).
  - `SymfonyMailTransport` : envoi SMTP via **symfony/mailer** (DSN issu de l'environnement), corps multipart HTML + texte ; échec capturé et journalisé.
  - `NativeMailTransport` : **repli gracieux** sur la fonction PHP `mail()` (multipart) quand aucun SMTP n'est configuré — dev / CI / hôtes mutualisés continuent de fonctionner.
  - `MailTransportFactory::fromEnv()` : choisit le transport (SMTP si `SMTP_DSN` ou `SMTP_HOST…`, sinon `mail()`) et reconstruit un DSN depuis `SMTP_HOST/PORT/USER/PASS/ENCRYPTION` (identifiants percent-encodés, jamais loggés).
- **E-mails HTML via Twig** : `EmailRenderer` rend `templates/emails/alert.html.twig` (alerte) et `templates/emails/digest.html.twig` (synthèse) avec **repli texte brut**, en remplacement de la concaténation de chaînes.
- **Digest des alertes de faible sévérité (P3/P4)** : `NotificationDigest` (interface `DigestQueue`) accumule les alertes mineures issues de `sendAlert()` dans la table auto-créée `notification_digest`, puis `NotificationService::flushDigest()` envoie **un unique e-mail groupé** (déclenché sur le tick horaire CRON). Les **P1/P2 restent immédiates**. Repli en envoi direct si la file est indisponible.
- **`NotificationService`** : refactor du transport (plus de `mail()` direct), API publique conservée (`sendAlert`, `sendCustomAlert`, `notify*`) ; nouvelle méthode `flushDigest()`. Dépendances injectables (transport, renderer, digest) pour la testabilité.
- **`CronOrchestrator`** : appel de `flushDigest()` dans les tâches horaires.
- **`.env.example`** : documentation des variables `SMTP_DSN` / `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` / `SMTP_ENCRYPTION` (repli `mail()` si absentes).
- **`config/dependencies.php`** : câblage explicite du transport (via factory), du renderer et de la file de synthèse.
- **Dépendance** : ajout de `symfony/mailer:^6.4` (LTS, PHP 8.1+) — `composer audit` reste propre.
- **Tests** : `tests/Notification/` (`MailTransportFactoryTest`, `NativeMailTransportTest`, `EmailRendererTest`, doubles `FakeMailTransport` / `FakeDigestQueue`) ; `NotificationServiceTest` réécrit sur transport factice (rendu HTML/texte, routage digest, flush groupé).

## [5.5.0] - 2026-06-24

### Ajout - Système de notifications par sévérité + mode de verbosité configurable (Phase 0)
- **`src/Notification/`** : nouveau socle de notification.
  - `Severity` (P1 Critique / P2 Alerte / P3 Info / P4 Diagnostic) avec code, priorité et cooldown anti-spam par défaut (P1 = 15 min, P2 = 1 h, P3 = 6 h, P4 = 24 h).
  - `NotificationCategory` (domaines : hydraulic, energy, environment, feeding, availability, lifecycle, camera, system).
  - `NotificationMode` (`none` / `important` / `partial` / `full`) : seuil de verbosité par sévérité.
  - `NotificationPolicy` : combine le mode et les catégories coupées ; construite depuis `NOTIF_MODE` + `NOTIF_DISABLED_CATEGORIES`.
  - `AlertThrottler` : anti-spam transversal + **historique unifié** (table auto-créée `notification_log`), généralise le cooldown de `ErrorAlertService` à toutes les alertes (fail-open si base indisponible).
- **`NotificationService`** : chaque envoi passe désormais par la politique (mode + catégorie) puis l'anti-spam ; sujets préfixés `[FAMILLE][Pn]` (tri/filtre côté boîte mail). Nouvelle méthode `sendAlert(severity, category, family, subject, message, throttleKey)`. API existante conservée.
- **Correctif anti-spam CRON** : les alertes « niveau d'eau bas » (`ffp3:water-low`) et « problème de marées » (`ffp3:tide-problem`) étaient envoyées **à chaque passe CRON (toutes les 5 min)** tant que la cause persistait → désormais throttlées (cooldown P1 = 15 min). Idem pour « hors ligne » et « aucune donnée capteur ».
- **`.env.example`** : ajout de `NOTIF_MODE` (défaut recommandé `important`) et `NOTIF_DISABLED_CATEGORIES` ; suppression de la variable morte `ALERT_EMAIL`.
- **`config/dependencies.php`** : câblage explicite de `NotificationService` (politique depuis l'env + throttler).
- **Tests** : `tests/Notification/` (Severity, NotificationMode, NotificationPolicy, AlertThrottler) + `NotificationServiceTest` étendu (modes, catégories coupées, anti-spam). `CronOrchestratorTest` aligné sur `sendAlert`.

## [5.4.2] - 2026-06-24

### Correctif - Auto-création outputs FFP3 résistante aux requêtes concurrentes
- **`OutputRepository`** : les violations de doublon attendues pendant l'INSERT concurrent des lignes GPIO 117 et 118-123 sont ignorées, ce qui évite un `500` et un rollback du POST capteur lors d'un premier déploiement multi-workers.
- **Tests** : garde-fou unitaire sur le doublon SQLSTATE `23000`, vérification de l'appel `ensureServoAngleRowsExist()` dans le flux POST FFP3 et budget JSON complet `outputs/state` sous 2048 o.

## [5.4.1] - 2026-06-24

### Vérifié - Contrat GPIO firmware↔serveur (angles servo 118-123)
- Cross-check de `GPIOMap::ALL_MAPPINGS` (`n3_firmwires`, `ffp5cs/include/gpio_mapping.h`) contre le contrat gelé `OutputSyncServiceTest::EXPECTED` : **27/27 entrées concordantes** (GPIO → `serverPostName` firmware == GPIO → propriété serveur), `MAPPING_COUNT` firmware = 27.
- Les six angles servo **118-123** sont bien présents dans `ALL_MAPPINGS` (`angleReposGros`, `angleDistribGros`, `angleInterGros`, `angleReposPetits`, `angleDistribPetits`, `angleInterPetits`) ; défauts firmware 88/140/45 (`GPIODefaults::SERVO_REST/FEED/INTER_ANGLE`) dans la plage `FeedingServoAngleValidator` 0–180.
- **GPIO 117** confirmé absent de `ALL_MAPPINGS` côté firmware (extension serveur uniquement) — cohérent avec `OutputSyncServiceTest::testGpio117IsNotInContract`.
- Lève le ⚠️ ouvert en 5.4.0 : le contrat firmware↔serveur sur les angles servo est désormais vérifié des deux côtés.

## [5.4.0] - 2026-06-24

### Modifié - Gestionnaire d'utilisateurs toujours actif + compte admin initial visible
- **`templates/partials/_nav.twig`** : lien permanent « Utilisateurs » dans la barre de navigation pour les administrateurs (`can_manage_users`). Le gestionnaire est désormais accessible en permanence, sans interrupteur.
- **`templates/supervision.twig`** : suppression de l'interrupteur d'affichage (`page-nav-toggle` `admin-users`) qui se remettait à 0 (état stocké en localStorage, par appareil) — la carte « Gestion des utilisateurs » reste présente.
- **`public/assets/js/page-nav-toggles.js`** : purge de la clé localStorage obsolète `admin-users` pour éviter un doublon dans le menu chez les utilisateurs ayant activé l'ancien toggle.
- **`UserService::ensureLegacyAdminMaterialized()`** : matérialise (de façon idempotente) le compte admin historique du `.env` (`ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH`) dans la table `n3_users` s'il n'y figure pas — il apparaît et devient gérable dans le gestionnaire. Appelé depuis `UserAdminController::index()`.
- **`UserAdminController`** : `nav_active = 'users'` sur les pages de gestion (liste, création, édition) pour surligner l'entrée de navigation.
- Étape transitoire vers le retrait du système d'authentification mono-compte (`.env`) une fois le gestionnaire pleinement opérationnel.

### Correctif - Tests préexistants désynchronisés (feature angles servo v5.3.8-5.3.10)
- **`tests/Service/OutputSyncServiceTest`** : contrat GPIO canonique mis à jour avec les angles servo 118-123 (déjà présents dans `Ffp3GpioMap` et exposés au firmware via `getOutputsState()`) — comptage 21 → 27.
- **`tests/Controller/Ffp3PostDataControllerTest`** : le mock `OutputRepository` stubbe désormais `ensureServoAngleRowsExist()` (appelée dans `insertData()` depuis v5.3.10) — sans ce stub, la vraie méthode s'exécutait sans connexion PDO (constructeur désactivé) → 500.
- ✅ Côté contrat firmware↔serveur : **vérifié en 5.4.1** — `GPIOMap::ALL_MAPPINGS` (`n3_firmwires`) porte bien les GPIO 118-123 (27/27 entrées concordantes).

## [5.3.10] - 2026-06-23

### Correctif - Erreur 500 sur GET outputs/state et page contrôle (prod sans colonne `description`)
- **`ensureServoAngleRowsExist()`** : INSERT/UPDATE alignés sur GPIO 117 (`board`, `gpio`, `name`, `state` uniquement) — la prod n'a pas toujours la colonne `description` sur `ffp3Outputs*`.

## [5.3.9] - 2026-06-23

### Correctif - Auto-création des lignes GPIO 118-123 (angles servo) en BDD
- **`OutputRepository::ensureServoAngleRowsExist()`** : crée à la volée les six lignes `ffp3Outputs*` manquantes (comme GPIO 117), sans migration SQL manuelle.
- Appels au chargement page contrôle, GET état outputs, POST données et enregistrement des paramètres.
- **`tools/sql/migrate-gpio118-123-servo-angles-ffp3.sql`** : reste utilisable pour seed initial ou phpMyAdmin.

## [5.3.8] - 2026-06-23

### Ajout - Angles servo nourrissage FFP3 (GPIO 118-123)
- **`src/Config/Ffp3GpioMap.php`** : six paramètres `angleReposGros`, `angleDistribGros`, `angleInterGros`, `angleReposPetits`, `angleDistribPetits`, `angleInterPetits` (défauts 88/140/45).
- **`templates/control.twig`** : section « Angles servos » dans le bloc Nourrissage (auto-save existant).
- **`src/Util/FeedingServoAngleValidator.php`** : validation 0–180° à l'enregistrement des paramètres.
- **`tools/sql/migrate-gpio118-123-servo-angles-ffp3.sql`** : seed idempotent pour toutes les tables `ffp3Outputs*`.
- **`OutputController::getOutputsState()`** : exposition des GPIO 118–123 au firmware.

## [5.3.7] - 2026-06-23

### Sécurité - Protection des pages de contrôle (collision préfixes public/protected)
- **`src/Middleware/AuthGuardMiddleware.php`** : le middleware global ne s'appuie plus sur `public_paths` pour court-circuiter `protected_paths` — seuls les préfixes `protected_paths` exigent l'authentification (corrige l'accès anonyme à `/aquaponie-control`, `/meteo-control`, `/serre-control`, etc. lorsque le chemin commençait par un préfixe public `/aquaponie`, `/meteo` ou `/serre`). En mode `session`, `$applyAuth` redirige désormais vers `/login` si l'utilisateur n'est pas connecté (au lieu de ne vérifier que le rôle).
- **`config/routes_config.php`** : ajout de `/msp1-test/msp1control` et `/n3pp-test/n3ppcontrol` aux chemins protégés.
- **`src/Controller/AbstractOutputController.php`** : vérification d'authentification inline dans `showControlPage()` (alignement sur les galeries).
- **Tests** : `tests/Middleware/AuthGuardMiddlewareTest.php`, extension de `RoutesConfigSecurityTest` ; smoke `-RunNegativeAuthChecks` couvre les pages `*-control` MSP/N3PP test.

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
