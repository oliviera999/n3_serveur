# Connecteur MCP — iot.olution.info

> Version serveur : **6.54.5**. Pilotage : voir [`MCP_PILOTAGE.md`](MCP_PILOTAGE.md).

Ce serveur expose un **serveur MCP distant** (Model Context Protocol) utilisable comme
connecteur par **Claude**, **Cursor**, **ChatGPT**, et **Gemini Enterprise** (client
confidentiel). Les modèles peuvent alors lire l'état des modules IoT (aquaponie FFP3,
serre N3PP, météo MSP1, banc énergie, Poissonglouton), leurs séries, les alertes et les
photos. Le pilotage existe mais est **désactivé par défaut**.

**Tuto simple (toutes les IA) + scripts** : [`MCP_ACTIVATION.md`](MCP_ACTIVATION.md) —
`c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1`,
`php c:\projets_code\IOT_n3\serveur\tools\mcp-check-discovery.php`.

L'authentification passe par un **serveur d'autorisation OAuth 2.1 embarqué** : chaque
personne se connecte avec son compte `n3_users` (lecteur, opérateur, administrateur).

---

## 1. Architecture

```
Claude ──HTTPS──▶ Apache (.htaccess) ──▶ public/index.php (Slim 4)
                                          ├─ POST /mcp ............ McpController ─▶ McpServer (JSON-RPC 2.0)
                                          │                                       └▶ ToolRegistry ─▶ src/Mcp/Tools/*
                                          ├─ /.well-known/oauth-* . OAuthController (RFC 9728 / RFC 8414)
                                          └─ /oauth/* ............. OAuthController ─▶ AuthorizationServer, ClientResolver
```

| Élément | Fichier |
|---|---|
| Routes (actives seulement si `MCP_ENABLED=true`) | `config/routes_mcp.php` |
| Point d'entrée HTTP `/mcp` | `src/Controller/McpController.php` |
| Noyau JSON-RPC MCP (sans état) | `src/Mcp/McpServer.php`, `ToolRegistry.php`, `Tool.php`, `ToolResult.php` |
| Outils | `src/Mcp/Tools/*Tool.php` |
| Garde-fous partagés | `src/Mcp/Support/` (`EnvironmentScope`, `ReadOnlyTransaction`, `TimeWindow`, `FieldCatalog`, `SchemaValidator`, `Redactor`, `GalleryReader`, `ActiveAlertsReader`) |
| Pilotage | `src/Mcp/Control/ControlPolicy.php`, `OutputWriter.php` |
| Serveur OAuth | `src/OAuth/` (`AuthorizationServer`, `ClientResolver`, `RedirectUriValidator`, `Pkce`, `OAuthMetadata`, `CurlHttpFetcher`) |
| Endpoints OAuth + page de consentement | `src/Controller/OAuth/OAuthController.php`, `templates/oauth/*.twig` |
| Accès BDD | `src/Repository/McpReadRepository.php` (lectures), `McpOAuthRepository.php` (jetons, plans) |
| Schéma | `migrations/2026_11_mcp_oauth.sql` (+ `docker/mysql/init/96-mcp-oauth.sql`) |
| Purge CRON | `src/Command/McpOAuthPurgeCommand.php` (tâches horaires de `CronOrchestrator`) |
| Mémoire (§ 8) | `src/Mcp/Memory/MemoryService.php`, `src/Repository/McpMemoryRepository.php`, outils `Recall/Remember/ForgetTool` |
| Santé (§ 9) | `src/Mcp/Health/` (`HealthReportBuilder`, `HealthRules`, `CronStatusReader`, `LogLocator`, `StreamCertificateProbe`), `src/Command/HealthReportCommand.php`, `src/Controller/HealthReportController.php`, `src/Repository/McpHealthRepository.php` |
| Fichiers (§ 10) | `src/Mcp/Files/` (`FileRoots`, `SafeFileReader`), `src/Mcp/Support/SecretScanner.php` |
| Schéma exploitation | `migrations/2026_11_mcp_exploitation.sql` (+ `docker/mysql/init/97-mcp-exploitation.sql`) |
| Supervision externe | `.github/workflows/health-check.yml` |
| Smoke de bout en bout | `tools/mcp-smoke.php` |

### Transport

**Streamable HTTP en mode sans état** : `POST /mcp` reçoit un message JSON-RPC 2.0 (ou un
lot) et répond en `application/json` — pas de SSE, pas de `Mcp-Session-Id`, aucun
processus long (adapté au mutualisé o2switch). `GET /mcp` et `DELETE /mcp` → **405**.
Une requête ne contenant que des notifications → **202** sans corps.

Un lot JSON-RPC est limité à **20 messages** (au-delà : erreur `-32600`, rien n'est exécuté).
Versions de protocole acceptées : `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`
(la version demandée est renvoyée si supportée, sinon la plus récente). L'en-tête
`MCP-Protocol-Version`, s'il est présent, doit être l'une d'elles (sinon 400).

### Choix : noyau maison plutôt que le SDK officiel

Le SDK PHP officiel [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk) (Apache-2.0,
compatible PHP 8.1) a été évalué : v0.9 (octobre 2026, encore 0.x, API mouvante), ~15
dépendances supplémentaires (opis/json-schema, php-http/discovery, symfony/uid,
phpdocumentor/reflection-docblock…), conçu d'abord pour le mode avec session. Notre surface
est petite (5 méthodes JSON-RPC, outils fermés) : un noyau de ~300 lignes, testé, sans
dépendance, est plus sûr à déployer par `git pull` sur mutualisé. Le protocole suit la
[spécification MCP 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25).

Pour OAuth, [`league/oauth2-server`](https://github.com/thephpleague/oauth2-server) (MIT)
exige PHP ≥ 8.2 depuis la v9, émet des JWT signés (clé privée à gérer) et ne gère ni CIMD
ni la détection de réutilisation des refresh tokens sans code conséquent : implémentation
maison, inspirée de ses comportements et d'OAuth 2.1, **sans code repris**.

---

## 2. Outils

Tous prennent `env` (défaut `prod`, restreint à `MCP_ALLOWED_ENVS`) quand c'est pertinent
et renvoient `env` + `is_production`. Sorties en JSON compact (`content` texte +
`structuredContent`), images en contenu MCP `image`.

| Outil | Rôle |
|---|---|
| `iot_overview` | Par module : en ligne / hors ligne (âge mesure + heartbeat vs seuil dérivé de la veille), firmware, dernière mesure résumée, alertes actives. **Point de départ.** |
| `iot_server_health` | Version, BDD (latence), dernier passage du CRON horaire, galeries (nb photos, plus récente, à normaliser). |
| `iot_describe` | Dictionnaire des champs (unité, sens, plage, type) et des GPIO, par famille. |
| `iot_latest` | Dernière mesure complète d'une famille (unités converties). Un capteur physique vide est listé dans `missing_fields` (dernière valeur non nulle, depuis quand, nb de mesures ; recherche ≤ 30 j). |
| `iot_series` | Série agrégée en SQL (`auto|5min|15min|1h|6h|1d`, `avg|min|max`, ≤ 500 points, ≤ 180 j) + stats par champ. |
| `iot_stats` | Min / max / moyenne / écart-type / couverture sur une période. |
| `iot_sensor_health` | Santé **par capteur** sur la fin d'une période (défaut 48 h, ≤ 30 j) : `ok` / `intermittent` / `absent` (≥ 3 mesures vides d'affilée) / `frozen` (même valeur ≥ `frozen_minutes`, défaut 120, et ≥ 5 mesures) / `no_data` ; dernière valeur non nulle, début et durée du silence et du figement, couverture. Par défaut : capteurs physiques (`FieldCatalog::sensorFields`). |
| `iot_tides` | Marées FFP3 via `TideAnalysisService` (cycles, marnage, fréquence, réserve, diffMaree, événements firmware), ≤ 14 j. |
| `iot_energy` | Bilan du banc INA226 (Wh produits / consommés, puissances, tension et SoC batterie). |
| `iot_pgl_stats` | Compteur Poissonglouton (total, jour, historiques). |
| `iot_outputs_state` | Consignes GPIO enregistrées (libellés, dernier changement, source), **sans** les GPIO e-mail. |
| `iot_alerts` | Alertes en cours, journal des notifications (sans destinataire), erreurs serveur regroupées. |
| `iot_contract_check` | Alignement contrats firmware ↔ serveur (`Service/Contract`) + versions firmware vues vs publiées en OTA. |
| `iot_gallery_list` | Photos d'une galerie, paginées, filtrables par date. |
| `iot_gallery_latest` | Renvoie une photo en **image** JPEG ≤ 800 px, < 1 Mo (GD), éventuellement avec la précédente. |
| `iot_plan_output_change` | Pilotage étape 1 (désactivé par défaut) — voir `MCP_PILOTAGE.md`. |
| `iot_apply_plan` | Pilotage étape 2 (désactivé par défaut). |
| `iot_set_output` | **Productivité / admin** — consigne directe si `MCP_CONTROL_REQUIRE_PLAN=false`. § 12 |
| `iot_device_action` | **Productivité / admin** — reset / OTA (`MCP_DEVICE_ACTIONS_ENABLED`). § 12 |
| `iot_gallery_delete` | **Productivité / admin** — corbeille / purge photos. § 12 |
| `iot_sql` | **Productivité / admin** — SQL libre (`MCP_SQL_ENABLED`). § 12 |
| `iot_accounts` | **Productivité / admin** — comptes et destinataires (`MCP_PRIVACY_BYPASS`). § 12 |
| `iot_confirm_unlock` | **Productivité / admin** — fenêtre sans codes (`MCP_CONFIRM_UNLOCK_MINUTES`). § 12 |
| `iot_recall` | **Mémoire** : notes du site et notes privées (recherche sujet / texte / étiquettes, « à revérifier »). À appeler en début de tâche. § 8 |
| `iot_remember` | **Mémoire** : crée ou met à jour (même sujet) une note, rafraîchit `verified_at`. Refuse les secrets. § 8 |
| `iot_forget` | **Mémoire** : supprime une note (auteur ou administrateur). § 8 |
| `iot_health_report` | **Santé** : rapport complet construit à la demande, statut vert / orange / rouge + raisons. § 9 |
| `iot_health_history` | **Santé** : N derniers rapports stockés par le CRON + évolution du statut. § 9 |
| `iot_cron_status` | **Admin** — dernier passage de `CronOrchestrator` et de chaque sous-tâche, retards. § 9 |
| `iot_list_files` | **Admin** — racines autorisées, contenu d'un dossier (lecture seule). § 10 |
| `iot_read_file` | **Admin** — lecture par `offset`/`limit` ou `tail`, secrets masqués, `.env` réduit aux noms. § 10 |
| `iot_grep_files` | **Admin** — recherche littérale ou regex bornée, résultats plafonnés. § 10 |

Les niveaux d'eau FFP3 (`EauAquarium`, `EauReserve`, `EauPotager`) sont des **distances
capteur → surface**, stockées en mm et exposées en **cm** : plus la valeur est grande,
moins il y a d'eau. `iot_describe` l'explique au modèle.

---

## 3. Sécurité

### Authentification

- `POST /mcp` sans jeton valide → **401** +
  `WWW-Authenticate: Bearer resource_metadata="<base>/.well-known/oauth-protected-resource/mcp", scope="iot.read"`.
- Le jeton est lu **uniquement** dans l'en-tête `Authorization: Bearer` (jamais dans l'URL).
- **Seuls les jetons MCP** sont acceptés : la session web, le cookie `admin_token` et
  `ADMIN_TOKEN` ne donnent aucun accès à `/mcp` ; inversement un jeton MCP ne vaut rien
  ailleurs (aucun autre middleware ne le lit).
- Le rôle est **relu en base à chaque requête** : un compte désactivé ou rétrogradé perd
  immédiatement l'accès / le scope `iot.control`.
- **Changement de mot de passe** : chaque jeton est lié à une empreinte du mot de passe
  (`credential_epoch`, HMAC du hachage, jamais renvoyée) ; dès que le mot de passe change —
  par l'admin web ou par SQL direct — tous les jetons d'accès et de rafraîchissement émis
  avant sont refusés. `UserRepository` révoque en plus explicitement les jetons MCP sur
  changement de mot de passe, de rôle, désactivation et suppression du compte.
- **Clé de l'empreinte** (`App\Security\McpCredentialEpoch`) : dérivée d'un secret serveur,
  `HMAC-SHA256('mcp-credential-epoch', MCP_CREDENTIAL_SECRET)`, à défaut `API_SIG_SECRET`. Une
  fuite de la base seule ne permet donc pas de recalculer les empreintes. Si les deux secrets
  sont vides, une clé fixe de repli est utilisée et un avertissement est journalisé à chaque
  connexion (`[mcp-oauth] MCP_CREDENTIAL_SECRET et API_SIG_SECRET vides…`).
  ⚠️ **Tourner ce secret** (ou définir `MCP_CREDENTIAL_SECRET` alors que la clé venait
  d'`API_SIG_SECRET`) **révoque toutes les connexions MCP** : chaque utilisateur doit se
  reconnecter une fois depuis Claude. Aucune autre donnée n'est touchée. Un secret dédié
  évite que la rotation du secret HMAC des ESP32 déconnecte Claude.
- Un jeton sans ressource (`resource` NULL) est refusé : l'audience est obligatoire.

### Serveur d'autorisation

| Exigence | Implémentation |
|---|---|
| Protected Resource Metadata (RFC 9728) | `/.well-known/oauth-protected-resource` et `/.well-known/oauth-protected-resource/mcp` ; `resource` = **exactement** `<MCP_PUBLIC_BASE_URL>/mcp`, un seul issuer |
| AS metadata (RFC 8414) | `/.well-known/oauth-authorization-server` : `S256`, `none` + `client_secret_post` / `client_secret_basic`, `authorization_response_iss_parameter_supported`, CIMD, DCR, revoke |
| CIMD | `client_id` = URL HTTPS ; hôtes : Claude, ChatGPT, Cursor (défaut) ; `MCP_CIMD_ALLOWED_HOSTS` |
| DCR (RFC 7591) | `/oauth/register`, clients **publics** uniquement ; redirects allowlistés |
| Redirections | Builtin : Claude, Cursor web, Cursor legacy `cursor://…`, ChatGPT, Gemini Enterprise ; loopback HTTP (port ignoré) ; `MCP_EXTRA_REDIRECT_URIS` |
| PKCE | S256 **obligatoire** (`plain` refusé) |
| Code d'autorisation | aléatoire, usage unique (atomique), 60 s, lié au client et à la `redirect_uri` ; rejeu → révocation des jetons émis |
| Refresh token | **rotation** à chaque usage ; réutilisation d'un refresh déjà tourné → **révocation de toute la famille** |
| Erreurs | RFC 6749 (`invalid_grant`, `invalid_request`, `unsupported_grant_type`, `invalid_scope`, `invalid_target`…) |
| Stockage | jetons opaques (256 bits) **hachés sha256** ; jamais en clair |
| `resource` (RFC 8707) | validé s'il est fourni ; le jeton est lié à `<base>/mcp` (audience vérifiée) |
| Consentement | page Twig autonome : nom d'hôte de redirection affiché, avertissement loopback, scopes, CSRF dédié, limitation 10 essais / 10 min / IP **et 5 échecs / 15 min / compte** (clé du compte résolu `id:<id>` + clé du nom normalisé NFKC, sans accents ni caractères invisibles, en minuscules : « ádmin », « ADMIN », « admin␠ » partagent le compteur d'`admin`, comme la collation `utf8mb4_unicode_ci`), anti-énumération (vérification `UserRepository::verifyPassword`), non encadrable (`X-Frame-Options: DENY` + `Content-Security-Policy: frame-ancestors 'none'`) |

Scopes : `iot.read` (tout compte actif), `iot.control` (opérateur ou administrateur **et**
`MCP_CONTROL_ENABLED=true`), `offline_access` (délivre un refresh token).

Le compte de secours `.env` (`ADMIN_USERNAME`) **n'est pas** accepté par la page OAuth (pas
d'identifiant stable à lier au jeton) : il faut un compte `n3_users`.

### Garde-fous des outils

- **Environnement** : énumération validée, restreinte à `MCP_ALLOWED_ENVS` ; bascule par
  `TableConfig::setEnvironment()` et remise à zéro **garantie** dans un `finally`.
- **Lecture seule** : `iot_remember` et `iot_forget` sont les seuls outils non-pilotage qui écrivent,
  et uniquement dans `mcp_memory` (déclarés `isReadOnly(): false`). Chaque autre outil de lecture s'exécute dans une transaction
  `SET SESSION TRANSACTION READ ONLY` toujours **annulée** (MariaDB refuse toute écriture,
  testé). Avec `DB_MCP_USER` / `DB_MCP_PASS`, les lectures passent en plus par un
  utilisateur SQL n'ayant que `SELECT`.
- **Aucun secret** : colonnes `mail`/`mailNotif` et GPIO 100/101 jamais lus ; destinataires
  de notification jamais renvoyés ; e-mails, jetons longs, `password=`… masqués
  (`Redactor`) dans les messages d'erreur ; exceptions internes → message générique +
  référence journalisée.
- **Performance mutualisé** : fenêtre ≤ 180 j, agrégation `GROUP BY` sur un bucket de
  `reading_time` (index existant), ≤ 500 points, `LIMIT` partout, ≤ 20 000 lignes brutes
  pour les marées.
- **Journal** : chaque appel est journalisé (`[mcp] tool=… user=… env=… duration_ms=… status=…`) ;
  le pilotage passe par `ControlAuditLogger` (`[control-audit] … module=mcp:<famille>`).
- **Origin** : si l'en-tête est présent, il doit valoir Claude / ChatGPT / Cursor, l'origine de
  `MCP_PUBLIC_BASE_URL` ou une valeur de `MCP_ALLOWED_ORIGINS` (anti DNS-rebinding).
- **Débit** : `POST /mcp` limité par IP (`MCP_RATE_LIMIT_MAX` / `_WINDOW`), endpoints OAuth à
  60 requêtes / 10 min / IP.

---

### ⚠️ Effet de bord du `.htaccess` : `ADMIN_TOKEN` en en-tête

La règle `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`, nécessaire à `/mcp`
sous PHP-FPM/CGI, s'applique à **tout le site**. Or `AuthService::hasValidHeaderToken()`
accepte depuis longtemps `Authorization: Bearer <ADMIN_TOKEN>` comme authentification
**administrateur** (et comme exemption CSRF) — canal qui était jusqu'ici **inatteignable** sous
CGI (l'en-tête n'arrivait pas à PHP). Après déploiement, quiconque connaît `ADMIN_TOKEN` peut
l'utiliser en en-tête sur toutes les pages et API d'administration.

**Recommandation** : `ADMIN_TOKEN` long et aléatoire (ex. `php -r 'echo bin2hex(random_bytes(32));'`),
jamais réutilisé ailleurs, et vide s'il n'est pas utilisé (un `ADMIN_TOKEN` vide désactive ce
canal). Les jetons MCP, eux, ne valent que pour `/mcp`.

## 4. Variables d'environnement

| Variable | Défaut | Rôle |
|---|---|---|
| `MCP_ENABLED` | `false` | Active toutes les routes MCP / OAuth |
| `MCP_PUBLIC_BASE_URL` | (requête) | URL publique **exacte**, sans `/` final : `https://iot.olution.info` |
| `MCP_ACCESS_TOKEN_TTL` | `3600` | Jeton d'accès (s) |
| `MCP_REFRESH_TOKEN_TTL` | `2592000` | Refresh (30 j) |
| `MCP_AUTH_CODE_TTL` | `60` | Code d'autorisation (s) |
| `MCP_ALLOWED_ENVS` | `prod,s3` | Environnements lisibles |
| `MCP_CONTROL_ENABLED` | `false` | Expose les outils de pilotage |
| `MCP_CONTROL_ALLOWED_TARGETS` | `ffp3:test,ffp3:test3,ffp3:s3test,n3pp:n3pp_test,msp1:msp_test` | Cibles `famille:env` pilotables (jugées sur la table réellement écrite) |
| `MCP_CONTROL_ALLOW_PRODUCTION` | `false` | ⛔ Autorise une cible listée qui vise une table de production (voir `MCP_PILOTAGE.md`) |
| `MCP_CONTROL_REQUIRE_PLAN` | `true` | `true` = seul plan→apply ; `false` = expose `iot_set_output` (admin) |
| `MCP_CONTROL_BIO_SAFEGUARDS` | `true` | Garde-fous biologiques (pompe, chauffage, nourrissage) |
| `MCP_CONTROL_HEATING_MIN` / `MAX` | `18` / `28` | Plage consigne chauffage (°C), bornée 10–35 |
| `MCP_CONTROL_PUMP_STOP_MAX_MINUTES` | `0` | Arrêt pompe avec rétablissement auto (0 = interdit) |
| `MCP_DEVICE_ACTIONS_ENABLED` | `false` | Reset, OTA, suppression photos |
| `MCP_SQL_ENABLED` / `MCP_SQL_DDL_ENABLED` | `false` | SQL libre / DDL + GRANT |
| `MCP_SQL_MAX_AFFECTED_ROWS` | `100` | Plafond DML |
| `MCP_SQL_MAX_STATEMENT_SECONDS` | `10` | Timeout instruction |
| `MCP_SQL_MAX_ROWS` | `200` | Plafond lignes SELECT |
| `MCP_PRIVACY_BYPASS` | `false` | Admin voit noms / e-mails (jamais les secrets) |
| `MCP_CONFIRM_SKIP` | — | Catégories dispensées de code (CSV) ou `all` |
| `MCP_CONFIRM_UNLOCK_MINUTES` | `0` | Fenêtre après `iot_confirm_unlock` (0 = off) |
| `MCP_FEED_DAILY_MAX` | `2` | Nourrissages MCP / 24 h / environnement |
| `MCP_EXTRA_REDIRECT_URIS` | — | Redirections OAuth exactes supplémentaires (CSV) ; Claude/Cursor/ChatGPT/Gemini Enterprise sont déjà intégrés |
| `MCP_ALLOWED_ORIGINS` | — | Origines navigateur supplémentaires (CSV ; Claude, ChatGPT, Cursor et la base le sont) |
| `MCP_CIMD_ALLOWED_HOSTS` | `claude.ai,claude.com,chatgpt.com,cursor.com,www.cursor.com` | Hôtes CIMD. `*` = tout hôte public (**déconseillé**) |
| `MCP_OAUTH_CONFIDENTIAL_CLIENT_ID` / `_SECRET` | — | Client confidentiel statique (Gemini Enterprise) ; les deux requis |
| `MCP_DCR_ENABLED` | `true` | Enregistrement dynamique |
| `MCP_RATE_LIMIT_MAX` / `MCP_RATE_LIMIT_WINDOW` | `300` / `60` | Débit `/mcp` par IP |
| `MCP_CREDENTIAL_SECRET` | *(vide → `API_SIG_SECRET`)* | Clé HMAC de l'empreinte de mot de passe des jetons ; **la changer révoque toutes les connexions MCP** |
| `DB_MCP_USER` / `DB_MCP_PASS` | — | Utilisateur SQL **SELECT seul** (recommandé) |
| `MCP_MEMORY_STALE_DAYS` | `90` | Au-delà, une note non revérifiée est signalée « à revérifier » (`stale: true`) |
| `MCP_MEMORY_MAX_CHARS` | `2000` | Taille maximale du contenu d'une note |
| `MCP_MEMORY_MAX_NOTES` | `500` | Notes maximum par propriétaire (le site, ou chaque compte) |
| `MCP_HEALTH_TOKEN` | *(vide → 404)* | Jeton de `GET /health/report.json` (en-tête `X-Health-Token`) ; indépendant de `MCP_ENABLED` |
| `MCP_HEALTH_REPORT_ENABLED` | `false` | Rapport régulier par le CRON (`HealthReportCommand`) |
| `MCP_HEALTH_REPORT_INTERVAL_SECONDS` | `86400` | Intervalle du rapport régulier (3 600 à 604 800 s) |
| `MCP_HEALTH_REPORT_HOUR` | `7` | Heure locale du rapport quotidien (vide : dès que l'intervalle est écoulé) |
| `MCP_HEALTH_REPORT_EMAIL` | `alert` | `alert` = e-mail si orange/rouge ; `daily` = chaque rapport ; `never` |
| `MCP_HEALTH_REPORT_RETENTION_DAYS` | `90` | Conservation des rapports stockés |
| `MCP_HEALTH_TLS_HOST` | *(hôte de `MCP_PUBLIC_BASE_URL`)* | Hôte dont le certificat est surveillé |
| `MCP_FILE_ROOTS` | *(liste par défaut, § 10)* | Racines consultables (CSV, `nom=chemin` possible, `defaults` = liste par défaut) |
| `MCP_FILE_MAX_BYTES` | `65536` | Octets maximum renvoyés par appel de lecture (4 096 à 262 144) |

---

## 5. Mise en production (o2switch)

> Parcours condensé multi-clients : **[`MCP_ACTIVATION.md`](MCP_ACTIVATION.md)**
> (`c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action all`).

1. **Sauvegarde** de la base (phpMyAdmin → Exporter).
2. **Déployer** le code (`git pull` + `composer install --no-dev --optimize-autoloader`) ;
   le hook `post-merge` vide `var/cache/di` (sinon `php bin/clear-cache.php`).
3. **Jouer la migration** `migrations/2026_11_mcp_oauth.sql` (phpMyAdmin → SQL, ou
   `mysql --default-character-set=utf8mb4 -h localhost -u oliviera_iot -p oliviera_iot < migrations/2026_11_mcp_oauth.sql`).
   Idempotente. (Les deux autres : voir § 11 / [`MCP_ACTIVATION.md`](MCP_ACTIVATION.md).)
4. **(Recommandé) Utilisateur SQL lecture seule** : cPanel → Bases de données MySQL →
   créer `oliviera_mcpro`, l'ajouter à la base avec le **seul** privilège `SELECT`.
5. **`.env`** :
   ```ini
   MCP_ENABLED=true
   MCP_PUBLIC_BASE_URL=https://iot.olution.info
   MCP_ALLOWED_ENVS=prod,s3
   MCP_CONTROL_ENABLED=false
   DB_MCP_USER=oliviera_mcpro
   DB_MCP_PASS=…
   ```
6. **Vérifier la découverte** :
   ```bash
   curl -s https://iot.olution.info/.well-known/oauth-protected-resource/mcp
   curl -s https://iot.olution.info/.well-known/oauth-authorization-server
   curl -si -X POST https://iot.olution.info/mcp -H 'Content-Type: application/json' \
        -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' | grep -i www-authenticate
   ```
   Attendu : deux JSON (`resource` = `https://iot.olution.info/mcp`) puis un `401` avec
   `WWW-Authenticate: Bearer resource_metadata="…"`. Si les `.well-known` renvoient 404 ou la
   page d'Apache, voir § 7.
7. **Vérifier la transmission de `Authorization`** (cas FPM/CGI) : lancer le smoke (§ 6)
   depuis un poste ; l'étape `initialize` doit répondre 200. Un 401 à cette étape alors que
   `/oauth/token` a réussi = en-tête retiré par Apache (voir § 7).
8. **Ajouter le connecteur** (détail multi-clients § 5.1) :
   - Claude / Cursor / ChatGPT / Gemini Enterprise selon le client.
9. **Tester** dans une conversation : « Donne-moi l'état de l'aquaponie » (→ `iot_overview`),
   « Montre-moi la dernière photo de la serre » (→ `iot_gallery_latest`).

### 5.1 Autres clients (Cursor, ChatGPT, Gemini)

| Client | Redirect intégré | Auth client | Notes |
|---|---|---|---|
| Claude (web, Desktop, mobile, Cowork) | `https://claude.ai/api/mcp/auth_callback` | Public (CIMD / DCR) | Client d'origine |
| Claude Code | loopback `http://localhost\|127.0.0.1/…` (port libre) | Public (CIMD) | — |
| Cursor desktop | `http://localhost:8787/callback` (loopback) | Public (DCR) | Ancien : `cursor://anysphere.cursor-mcp/oauth/callback` |
| Cursor web / Agents | `https://www.cursor.com/agents/mcp/oauth/callback` | Public (DCR) | — |
| ChatGPT | `https://chatgpt.com/connector_platform_oauth_redirect` | Public (CIMD / DCR) | Exige `iss` (déjà renvoyé) |
| Gemini Enterprise | `https://vertexaisearch.cloud.google.com/oauth-redirect` | **Confidentiel** (`.env`) | Pas le Gemini grand public |
| Gemini app grand public | — | — | Pas de connecteur MCP custom équivalent |

**Cursor** — dans la config MCP utilisateur (`mcp.json`) :

```json
{
  "mcpServers": {
    "n3-iot": {
      "url": "https://iot.olution.info/mcp"
    }
  }
}
```

Puis authentifier quand Cursor le propose (navigateur → page OAuth iot.olution.info).

**ChatGPT** — ajouter un serveur MCP / plugin personnalisé avec l'URL
`https://iot.olution.info/mcp`, auth OAuth. Copier l'URI de redirection affichée dans
ChatGPT si elle diffère de l'URI stable (sinon elle est déjà allowlistée).

**Gemini Enterprise** — créer un couple ID/secret et les coller dans le connecteur Google :

```env
MCP_OAUTH_CONFIDENTIAL_CLIENT_ID=gemini-n3-iot
MCP_OAUTH_CONFIDENTIAL_CLIENT_SECRET=<secret long aléatoire>
```

Dans la console Gemini Enterprise (Custom MCP Server) : URL MCP = `https://iot.olution.info/mcp`,
Authorization URL = `https://iot.olution.info/oauth/authorize`, Token URL =
`https://iot.olution.info/oauth/token`, Client ID / Secret = les valeurs `.env`, scopes =
`iot.read offline_access` (et `iot.control` si besoin), PKCE activé, redirect Google déjà
intégré.

### Révoquer un accès

Supprimer les jetons d'un utilisateur : `UPDATE mcp_oauth_tokens SET revoked_at = NOW() WHERE user_id = <id>;`
Désactiver le compte dans `/admin/users` coupe aussi l'accès immédiatement. Couper tout le
connecteur : `MCP_ENABLED=false`.

---

## 6. Tests

```bash
composer test:unit        # tests/Mcp (dont Exploitation/ : mémoire, santé, fichiers), tests/OAuth, McpControllerTest, câblage, routes
# Intégration MariaDB (schéma docker/mysql/init/*.sql chargé dans la base cible) :
DB_HOST=127.0.0.1 DB_NAME=… DB_USER=… DB_PASS=… composer test:integration
```

Smoke réel (flux OAuth complet : 401 → métadonnées → DCR → autorisation → jeton → `initialize`
→ `tools/list` → `tools/call iot_overview` → rotation → réutilisation refusée → révocation) :

```bash
# serveur local (le routeur fait passer /.well-known/* par Slim, comme le .htaccess)
php -S 127.0.0.1:8099 -t public tests/Support/dev-router.php
MCP_SMOKE_PASSWORD='…' php tools/mcp-smoke.php --base=http://127.0.0.1:8099 --user=admin
# production (compte de test lecteur conseillé)
MCP_SMOKE_PASSWORD='…' php tools/mcp-smoke.php --base=https://iot.olution.info --user=<compte>
```

**MCP Inspector** : `npx @modelcontextprotocol/inspector` → Transport « Streamable HTTP »,
URL `https://iot.olution.info/mcp`, « Open Auth Settings » → flux OAuth guidé. Si
l'inspecteur utilise une redirection non loopback (ex. `http://localhost:6274/oauth/callback`
est loopback : acceptée), ajoutez-la sinon à `MCP_EXTRA_REDIRECT_URIS`.

---

## 7. Dépannage

| Symptôme | Cause probable | Correction |
|---|---|---|
| « Couldn't reach the MCP server », aucune trace OAuth | `/.well-known/oauth-*` non routé vers Slim (dossier `.well-known/` d'AutoSSL) | Vérifier la règle `RewriteRule ^\.well-known/oauth-…` en tête du `.htaccess` racine ; le 401 porte aussi `resource_metadata` (Claude le lit en priorité) |
| 401 en boucle après une connexion réussie | En-tête `Authorization` retiré par Apache (PHP-FPM / CGI) | La règle `E=HTTP_AUTHORIZATION` du `.htaccess` doit être présente ; si l'hébergeur l'ignore, essayer `CGIPassAuth On` (Apache ≥ 2.4.13, nécessite `AllowOverride AuthConfig` — sinon erreur 500 globale : tester d'abord) |
| 403 / page HTML ModSecurity sur `/oauth/token` ou `/mcp` | WAF o2switch (règles OWASP sur JSON / jetons) | cPanel → **ModSecurity** : désactiver pour le domaine le temps du test, puis demander au support o2switch une exception ciblée sur `/mcp` et `/oauth/*` |
| Blocage par IP | Pare-feu / géoblocage | Autoriser la plage de sortie Anthropic **`160.79.104.0/21`** (découverte, jeton et `/mcp` viennent de là) |
| `Identifiants incorrects` sur `/oauth/authorize` alors que `/login` OK | Mot de passe `.env` (fallback web) ≠ hash `n3_users` ; compte inactif ; verrouillage 5 essais ; POST sans `password` (WAF) | Réinit MDP dans `/admin/users` ; attendre 15 min ; retaper le MDP ; si « Mot de passe non reçu » → exception ModSecurity `/oauth/*` |
| Console : `form-action 'self'` bloque le POST `/oauth/authorize` | Chrome applique `form-action` aussi au **302** vers Claude/Cursor ; ancienne CSP trop stricte ; SW qui interceptait `/oauth/*` | Déployer ≥ 6.54.5 (CSP `form-action` élargi + SW bypass) ; hard-refresh / désinscrire le SW une fois |
| `invalid_grant` au refresh | Refresh déjà utilisé (rotation) ou compte désactivé | Normal après une réutilisation : se reconnecter depuis Claude |
| « Redirection refusée » sur la page OAuth | `redirect_uri` hors liste | Ajouter l'URI exacte à `MCP_EXTRA_REDIRECT_URIS` (claude.ai et loopback sont intégrés) |
| `iot.control` jamais accordé | `MCP_CONTROL_ENABLED=false` ou compte lecteur | Voir `MCP_PILOTAGE.md` |
| Erreurs 500 après déploiement | Cache DI compilé obsolète | `php bin/clear-cache.php` |

Journaux : `cronlog.txt` (ou `LOG_FILE_PATH`), lignes `[mcp]`, `[mcp-oauth]`, `[control-audit]`, `[mcp-health]`.

Référence Claude : <https://claude.com/docs/connectors/building/authentication>.

---

## 8. Mémoire persistante

Comme le connecteur Moodle « olution », le connecteur garde une **mémoire** que Claude consulte
en début de tâche et enrichit des faits stables. Les instructions renvoyées par `initialize`
le lui demandent explicitement (« consulte `iot_recall` en début de tâche ; enregistre avec
`iot_remember` ce qui est stable »).

| Point | Comportement |
|---|---|
| Table | `mcp_memory` : `id`, `scope` (`site` / `user`), `user_id` (NULL pour `site`), `owner_key`, `subject`, `content`, `tags`, `created_by`, `created_at`, `updated_at`, `verified_at` |
| Portée | `site` = visible par tous les comptes (défaut) ; `user` = privée au compte |
| Unicité | un sujet par propriétaire (`scope`, `owner_key`, `subject`) : `iot_remember` sur un sujet existant **met à jour** la note et rafraîchit `verified_at` (c'est aussi la façon de « revérifier ») |
| Recherche | `iot_recall` : mots (tous présents dans le sujet, le contenu ou les étiquettes) et/ou étiquettes (toutes portées) ; sujet exact en tête |
| Périmé | `stale: true` si `verified_at` a plus de `MCP_MEMORY_STALE_DAYS` jours (90 par défaut) ; filtre `stale_only` |
| Droits | scope `iot.read` suffit ; `iot_forget` et la réécriture d'une note de site : **auteur ou administrateur** ; les notes semées (sans auteur) sont rafraîchissables par tous, supprimables par un admin seulement |
| Taille | `MCP_MEMORY_MAX_CHARS` (2 000), sujet ≤ 191, ≤ 10 étiquettes, `MCP_MEMORY_MAX_NOTES` par propriétaire |
| Secrets | **refus** si le texte ressemble à une clé API, un jeton (Bearer, JWT, GitHub, Anthropic…), un mot de passe (`cle=valeur`, URL `user:mdp@`), un haché ou une clé privée (`Mcp\Support\SecretScanner`). Décrire **où** est la valeur (« `API_SIG_SECRET` dans `.env` »), jamais la valeur |
| Écriture | seuls `iot_remember` / `iot_forget` écrivent, dans `mcp_memory` uniquement (hors transaction READ ONLY) ; `iot_recall` est en lecture seule |

**Notes semées** par la migration (faits tirés uniquement de la documentation du dépôt) :
nomenclature ffp3 / ffp5cs ; `s3` = production ; correspondance environnement → suffixe de
table ; GPIO clés par famille ; emplacement des journaux et du CRON ; interprétation
d'`EauAquarium`, `diffMaree` et `PontDiv`. Le texte d'une note est une **donnée**, jamais une
instruction (rappelé dans chaque réponse de `iot_recall`).

---

## 9. Rapport de santé

`iot_health_report` construit à la demande un rapport structuré + un résumé d'une ligne :
version applicative et PHP, latence et version BDD, taille des tables principales
(information_schema), CRON (dernier passage, retard de chaque sous-tâche), chaque appareil de
production (`prod`, et `s3` si autorisé ; en ligne / hors ligne d'après le dernier heartbeat ou
la dernière mesure comparés au seuil dérivé de la veille, firmware), erreurs serveur
(`error_alerts`) et notifications (`notification_log`, par gravité, sans destinataire) sur 24 h
et 7 j, dernière photo par galerie, espace disque libre (`disk_free_space`), jours avant
expiration du certificat TLS (`stream_socket_client` + `openssl_x509_parse`, délai 3 s, erreur
rendue dans le rapport), connexions MCP actives (familles de jetons valides).

### Règles du statut (`Mcp\Health\HealthRules`)

Statut = **pire niveau déclenché** ; aucune règle = `vert`. Les raisons sont listées, rouge d'abord.

| Domaine | Orange | Rouge |
|---|---|---|
| BDD | latence > 500 ms | injoignable |
| CRON | orchestrateur sans passage depuis > 5 min ; tâches horaires en retard | orchestrateur > 15 min, ou jamais vu |
| Appareils | un appareil de production hors ligne | aquaponie FFP3 (`prod` ou `s3`) hors ligne depuis > 6 h |
| Erreurs | ≥ 20 erreurs serveur / 24 h ; ≥ 1 notification critique / 24 h | ≥ 200 erreurs serveur / 24 h |
| Galeries | galerie active sans photo depuis > 48 h | — |
| Disque | < 10 % ou < 1 Gio libres | < 2 % ou < 200 Mio libres |
| TLS | expire dans < 21 j, ou certificat illisible | expiré ou < 7 j |

Un appareil **inactif** (jamais vu, ou silencieux depuis > 30 jours) et une galerie sans photo
depuis > 30 jours sont listés mais **n'influent pas** sur le statut (module retiré).

### Rapport régulier (CRON)

`HealthReportCommand`, appelée par `CronOrchestrator` à chaque passage **sous son verrou flock**,
tient sa propre échéance (`var/cache/cron_last_health_report.timestamp`) : active si
`MCP_HEALTH_REPORT_ENABLED=true`, quotidienne par défaut à partir de 7 h
(`MCP_HEALTH_REPORT_HOUR`). Elle stocke le rapport dans `mcp_health_reports`, purge ceux de plus
de `MCP_HEALTH_REPORT_RETENTION_DAYS` jours, puis envoie un e-mail **via `NotificationService`** :

- `MCP_HEALTH_REPORT_EMAIL=alert` (défaut) : orange (P2) ou rouge (P1) seulement, politique de
  notification appliquée ;
- `daily` : chaque rapport, vert compris (le plancher du mode `important` est franchi puisque
  l'envoi est demandé ; le mode `none` et la catégorie Système désactivée restent respectés) ;
- `never` : jamais.

Anti-spam : clé `mcp:health-report:<jour>:<statut>`. L'orchestrateur écrit aussi
`var/cache/cron_last_run.json` (début, fin, durée, statut) à chaque passage.

`iot_health_history` renvoie les N derniers rapports et l'évolution (statut courant et depuis
quand, changements, nombre de rapports par statut, tendance). `iot_cron_status` (admin) détaille
le dernier passage de l'orchestrateur (ou, à défaut, le dernier marqueur « Fin orchestrateur
CRON » du journal), le run en cours (verrou tenu), chaque sous-tâche (horaires, normalisation
galerie, rapport de santé) avec son retard, et le redémarrage de pompe programmé.

### `GET /health/report.json`

Pour une supervision externe. Jeton dédié `MCP_HEALTH_TOKEN` dans l'en-tête
**`X-Health-Token`** (jamais dans l'URL), comparé en temps constant (`hash_equals`).
Jeton non configuré → **404** ; absent ou faux → **401** ; bon → **200** + `{status, summary,
reasons, report}`, `Cache-Control: no-store`, quel que soit le statut. Limité à 30 requêtes /
10 min / IP. `?tls=0` saute le contrôle du certificat. Indépendant de `MCP_ENABLED`.

```bash
curl -s -H "X-Health-Token: $MCP_HEALTH_TOKEN" https://iot.olution.info/health/report.json | jq '.status, .summary'
```

Le workflow `.github/workflows/health-check.yml` (quotidien à 06:23 UTC + `workflow_dispatch`)
appelle cet endpoint avec les secrets de dépôt `IOT_HEALTH_URL` et `IOT_HEALTH_TOKEN` : échec si
le statut est `rouge` ou si l'endpoint ne répond pas 200, avertissement si `orange`, et
**succès expliqué** si les secrets manquent.

---

## 10. Consultation des journaux et fichiers (lecture seule)

`iot_list_files`, `iot_read_file`, `iot_grep_files` et `iot_cron_status` : scope `iot.read`
**et rôle administrateur** (relu en base à chaque requête). Aucune écriture, exécution ni suppression.

**Racines par défaut** (`MCP_FILE_ROOTS` vide) — un fichier se désigne par `<racine>/<chemin>` :

| Racine | Contenu |
|---|---|
| `logs` | dossier de `LOG_FILE_PATH` (racine du dépôt pour `cronlog.txt`) : **fichiers de journaux seulement** — `cronlog*.txt` (rotations datées), `hmac-audit*.log`, `error_log` — ni sous-dossier ni autre fichier du dépôt |
| `logs-public` | même filtre, résolu depuis `public/` (contexte web de Monolog), s'il existe |
| `var` | `var/` (journaux locaux, états du CRON) ; `var/cache/` exclu |
| `php-errors` | journal d'erreurs PHP (`ini_get('error_log')`), s'il est localisable |
| `config`, `docs`, `migrations` | dossiers du dépôt |
| `src`, `templates` | code applicatif et vues Twig (depuis 6.55.0) : lire la logique CRON / POST / alertes à distance. Le dépôt est public ; mêmes garde-fous que les autres racines (admin, secrets masqués, `.env` réduit aux noms, binaires refusés, plafonds). Pour les retirer : `MCP_FILE_ROOTS` explicite sans `defaults` |
| `.htaccess`, `run-cron.php` | fichiers uniques |

La sortie du CRON n'a pas de fichier propre (crontab `>> /dev/null`, `docs/deployment/CRON.md`) :
elle passe par le journal applicatif. Personnaliser : `MCP_FILE_ROOTS=defaults,erreurs=/home4/oliviera/logs`
(nom `=` chemin absolu ou relatif au dépôt).

**Garde-fous** (`Mcp\Files\SafeFileReader`) :

- chemin absolu et octet nul refusés ; `realpath()` résout `..` et les **liens symboliques** : la
  cible réelle doit rester dans **sa** racine (un lien vers `.env` ou hors racine est refusé et
  n'apparaît pas dans les listes) ;
- `.git`, `vendor`, `node_modules`, `var/cache`, clés et magasins de secrets (`*.pem`, `*.key`,
  `.htpasswd`, `id_rsa`…) jamais servis ; fichiers **binaires** refusés ;
- `.env*` : **seuls les noms** de variables (`CLE=****`, `CLE=` si vide) ; partout ailleurs, les
  secrets reconnaissables sont masqués (clés API, `Authorization`/`Bearer`, `X-Health-Token`,
  mots de passe dans les URL, `password=`/`token=`/`secret=`…, JWT, jetons GitHub/Anthropic,
  hachés, valeurs aléatoires longues, e-mails) ;
- `iot_grep_files` cherche sur le texte **déjà masqué** (un motif ne permet pas de deviner un
  secret) : littéral par défaut ; regex bornée (ni récursion, ni référence arrière, ni
  conditionnelle, ni verbe de contrôle, quantificateur ≤ 999, `pcre.backtrack_limit` réduit) ;
- plafonds : `MCP_FILE_MAX_BYTES` par lecture (64 Kio), 2 000 lignes, 500 entrées listées,
  200 fichiers et 200 résultats par recherche, fichiers > 20 Mio ignorés par la recherche.

---

## 11. Activer en production

> Tuto simple + script : **[`MCP_ACTIVATION.md`](MCP_ACTIVATION.md)**.
> Liste ordonnée et exhaustive ci-dessous (connecteur + exploitation). Les étapes 1 à 4 reprennent § 5.

1. **Sauvegarder** la base (phpMyAdmin → Exporter).
2. **Déployer** le code : `git pull` (cron de déploiement) puis
   `composer install --no-dev --optimize-autoloader` ; le hook `post-merge` vide `var/cache/di`
   (sinon `php bin/clear-cache.php`).
3. **Jouer les trois migrations**, dans cet ordre (idempotentes, rejouables) —
   sur o2switch (`DB_HOST=localhost`), depuis la racine du dépôt :
   ```bash
   mysql --default-character-set=utf8mb4 -h localhost -u oliviera_iot -p oliviera_iot < migrations/2026_11_mcp_oauth.sql
   mysql --default-character-set=utf8mb4 -h localhost -u oliviera_iot -p oliviera_iot < migrations/2026_11_mcp_exploitation.sql
   mysql --default-character-set=utf8mb4 -h localhost -u oliviera_iot -p oliviera_iot < migrations/2026_11_mcp_productivite.sql
   ```
   (jetons/OAuth ; mémoire + santé ; productivité). phpMyAdmin → SQL possible à la place.
   Vérifier : `SELECT COUNT(*) FROM mcp_memory;` → 6.
4. **(Recommandé) Utilisateur SQL lecture seule** `oliviera_mcpro` (privilège `SELECT` seul).
5. **`.env`** (valeurs longues et aléatoires : `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`) :
   ```ini
   MCP_ENABLED=true
   MCP_PUBLIC_BASE_URL=https://iot.olution.info
   MCP_ALLOWED_ENVS=prod,s3
   MCP_CONTROL_ENABLED=false
   MCP_CREDENTIAL_SECRET=<64 caractères hexadécimaux>
   DB_MCP_USER=oliviera_mcpro
   DB_MCP_PASS=…
   # Exploitation
   MCP_HEALTH_TOKEN=<64 caractères hexadécimaux, distinct des autres secrets>
   MCP_HEALTH_REPORT_ENABLED=true
   MCP_HEALTH_REPORT_HOUR=7
   MCP_HEALTH_REPORT_EMAIL=alert
   # Facultatif : MCP_MEMORY_STALE_DAYS=90, MCP_HEALTH_REPORT_RETENTION_DAYS=90,
   # MCP_FILE_ROOTS=defaults[,nom=/chemin/absolu], MCP_FILE_MAX_BYTES=65536
   ```
   `NOTIF_EMAIL_RECIPIENT` / SMTP doivent déjà fonctionner (bouton « Tester l'envoi » de `/supervision`).
6. **Cron cPanel** (cPanel → Tâches Cron), chaque minute — le rapport de santé n'a **pas** de cron
   propre, il tourne dans l'orchestrateur :
   ```cron
   * * * * * cd /home4/oliviera/iot.olution.info && /usr/bin/php run-cron.php >> /dev/null 2>&1
   ```
   Vérifier une minute plus tard : `var/cache/cron_last_run.json` existe et `status` vaut `ok`
   (ou `iot_cron_status` depuis Claude).
7. **Vérifier l'en-tête `Authorization` et `/.well-known`** :
   ```bash
   curl -s https://iot.olution.info/.well-known/oauth-protected-resource/mcp     # JSON, resource = …/mcp
   curl -s https://iot.olution.info/.well-known/oauth-authorization-server       # JSON, PKCE S256
   curl -si -X POST https://iot.olution.info/mcp -H 'Content-Type: application/json' \
        -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' | grep -i www-authenticate   # 401 + resource_metadata
   curl -s -o /dev/null -w '%{http_code}\n' https://iot.olution.info/health/report.json            # 401 (jeton configuré)
   curl -s -H "X-Health-Token: …" https://iot.olution.info/health/report.json | jq .status        # vert / orange / rouge
   ```
   Un 404 ou une page Apache sur `.well-known` : § 7. La transmission de `Authorization` sous
   PHP-FPM/CGI se confirme à l'étape 9 (`initialize` → 200).
8. **Pare-feu o2switch** : autoriser la plage de sortie Anthropic **`160.79.104.0/21`**
   (découverte, `/oauth/token` et `/mcp` viennent de là) ; si ModSecurity bloque `/mcp` ou
   `/oauth/*` (403 HTML), demander une exception ciblée au support (§ 7). Le workflow GitHub
   vient d'IP GitHub variables : ne pas restreindre `/health/report.json` à la plage Anthropic.
9. **Smoke en production** (compte de test **lecteur** `n3_users` conseillé) :
   ```bash
   MCP_SMOKE_PASSWORD='…' php tools/mcp-smoke.php --base=https://iot.olution.info --user=<compte>
   ```
   Attendu : `SMOKE OK`, dont `initialize → 200` et `tools/call iot_health_report → statut …`.
10. **Ajouter le connecteur dans claude.ai** (et Desktop / mobile / Cowork) : Réglages →
    **Connecteurs** → **Ajouter un connecteur personnalisé** → URL `https://iot.olution.info/mcp`
    → **Se connecter** → page « Autoriser l'accès » d'iot.olution.info → compte `n3_users`
    (administrateur pour les outils de fichiers et de CRON). Plan Team / Enterprise : c'est un
    propriétaire de l'organisation qui ajoute le connecteur.
11. **Ajouter le connecteur dans Claude Code** :
    ```bash
    claude mcp add --transport http iot https://iot.olution.info/mcp
    ```
    puis `/mcp` dans une session → **Authenticate** (navigateur, redirection loopback).
12. **Secrets du workflow** (GitHub → dépôt → Settings → Secrets and variables → Actions →
    New repository secret) : `IOT_HEALTH_URL` = `https://iot.olution.info/health/report.json`,
    `IOT_HEALTH_TOKEN` = la valeur de `MCP_HEALTH_TOKEN`. Lancer une fois
    **Actions → Santé du site → Run workflow** : vert / orange = succès, rouge = échec.
13. **Tester dans une conversation** : « Consulte ta mémoire puis donne-moi l'état de
    l'aquaponie » (→ `iot_recall`, `iot_overview`) ; « Fais un bilan de santé du serveur »
    (→ `iot_health_report`) ; « Montre les 50 dernières lignes du journal » (admin →
    `iot_read_file` `tail`).

**Couper** : `MCP_ENABLED=false` coupe le connecteur (le rapport régulier et
`/health/report.json` continuent tant que `MCP_HEALTH_REPORT_ENABLED` / `MCP_HEALTH_TOKEN` sont
définis) ; vider `MCP_HEALTH_TOKEN` rend l'endpoint 404.

---

## 12. Mode productivité (admin, défauts protecteurs)

Réservé au compte **admin**. Tous les drapeaux ci-dessous valent `false` / `true` protecteur
par défaut dans le code : un `.env` oublié ne dérive pas. Détail du pilotage et des garde-fous :
[`MCP_PILOTAGE.md`](MCP_PILOTAGE.md).

### Confirmation par code (hors Claude)

Quand une opération l'exige (`bio_override`, `pump_maintenance`, `reset`, `ota`, `photo_delete`,
`sql_ddl`, `sql_unbounded_write`, `sql_prod_outputs`, `sql_account_admin`, `unlock`), l'outil
**n'exécute rien** : il envoie un code à 6 chiffres via `NotificationService` (e-mail du compte
`n3_users`, à défaut `NOTIF_EMAIL_RECIPIENT`) et renvoie `confirmation_id` + résumé. On rappelle
l'outil avec les **mêmes** arguments, `confirmation_id` et `confirmation_code`. Code à usage
unique, 10 min, 3 essais, lié à l'utilisateur / client / outil / empreinte des arguments.

Contournements (productivité) : `MCP_CONFIRM_SKIP` (CSV de catégories, ou `all` — jamais
`unlock`) ; `iot_confirm_unlock` ouvre une fenêtre de `MCP_CONFIRM_UNLOCK_MINUTES` après un code.

### Bloc `.env` — mode productivité (à coller en production)

```ini
MCP_CONTROL_ENABLED=true
MCP_CONTROL_ALLOWED_TARGETS=ffp3:prod,ffp3:s3,n3pp:prod,msp1:prod,ffp3:test,n3pp:n3pp_test,msp1:msp_test
MCP_CONTROL_ALLOW_PRODUCTION=true
MCP_CONTROL_REQUIRE_PLAN=false
MCP_CONTROL_BIO_SAFEGUARDS=true
MCP_CONTROL_HEATING_MIN=18
MCP_CONTROL_HEATING_MAX=28
MCP_CONTROL_PUMP_STOP_MAX_MINUTES=15
MCP_FEED_DAILY_MAX=3
MCP_DEVICE_ACTIONS_ENABLED=true
MCP_SQL_ENABLED=true
MCP_SQL_DDL_ENABLED=true
MCP_SQL_MAX_AFFECTED_ROWS=100
MCP_SQL_MAX_STATEMENT_SECONDS=10
MCP_SQL_MAX_ROWS=200
MCP_PRIVACY_BYPASS=true
MCP_CONFIRM_SKIP=
MCP_CONFIRM_UNLOCK_MINUTES=30
```

### Bloc `.env` — revenir au mode protégé

```ini
MCP_CONTROL_ENABLED=false
MCP_CONTROL_ALLOW_PRODUCTION=false
MCP_CONTROL_REQUIRE_PLAN=true
MCP_CONTROL_BIO_SAFEGUARDS=true
MCP_CONTROL_PUMP_STOP_MAX_MINUTES=0
MCP_DEVICE_ACTIONS_ENABLED=false
MCP_SQL_ENABLED=false
MCP_SQL_DDL_ENABLED=false
MCP_PRIVACY_BYPASS=false
MCP_CONFIRM_SKIP=
MCP_CONFIRM_UNLOCK_MINUTES=0
```
