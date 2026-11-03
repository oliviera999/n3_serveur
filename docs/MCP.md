# Connecteur MCP (Claude) — iot.olution.info

> Version serveur : **6.51.0**. Pilotage : voir [`MCP_PILOTAGE.md`](MCP_PILOTAGE.md).

Ce serveur expose un **serveur MCP distant** (Model Context Protocol) que l'on ajoute à
Claude (claude.ai, Desktop, mobile, Cowork, Claude Code) comme **connecteur personnalisé**.
Claude peut alors lire l'état des modules IoT (aquaponie FFP3, serre N3PP, météo MSP1,
banc énergie, Poissonglouton), leurs séries de mesures, les alertes, et **regarder les
photos** des caméras. Le pilotage existe mais est **désactivé par défaut**.

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
| `iot_latest` | Dernière mesure complète d'une famille (unités converties). |
| `iot_series` | Série agrégée en SQL (`auto|5min|15min|1h|6h|1d`, `avg|min|max`, ≤ 500 points, ≤ 180 j) + stats par champ. |
| `iot_stats` | Min / max / moyenne / écart-type / couverture sur une période. |
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
| AS metadata (RFC 8414) | `/.well-known/oauth-authorization-server` : `S256`, `none`, `client_id_metadata_document_supported: true`, `offline_access`, endpoints register / revoke |
| CIMD | `client_id` = URL HTTPS ; document récupéré en ≤ 3 s, ≤ 64 Kio, sans redirection, `client_id` du document = URL, client public, cache 1 h ; hôtes limités à `MCP_CIMD_ALLOWED_HOSTS` (anti-SSRF) |
| DCR (RFC 7591) | `/oauth/register`, clients publics uniquement, `redirect_uris` strictement validées |
| Redirections | `https://claude.ai/api/mcp/auth_callback` **exacte** ; loopback `http://localhost/…` et `http://127.0.0.1/…` **port ignoré** (Claude Code) ; `MCP_EXTRA_REDIRECT_URIS` |
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
- **Lecture seule** : chaque outil de lecture s'exécute dans une transaction
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
- **Origin** : si l'en-tête est présent, il doit valoir `https://claude.ai`, `https://claude.com`,
  l'origine de `MCP_PUBLIC_BASE_URL` ou une valeur de `MCP_ALLOWED_ORIGINS` (anti DNS-rebinding).
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
| `MCP_FEED_DAILY_MAX` | `2` | Nourrissages MCP / 24 h / environnement |
| `MCP_EXTRA_REDIRECT_URIS` | — | Redirections OAuth exactes supplémentaires (CSV) |
| `MCP_ALLOWED_ORIGINS` | — | Origines navigateur supplémentaires (CSV) |
| `MCP_CIMD_ALLOWED_HOSTS` | `claude.ai,claude.com` | Hôtes CIMD. `*` (tout hôte public) est **déconseillé** : l'IP résolue est vérifiée publique (IPv4/IPv6, mappées, NAT64) et épinglée (`CURLOPT_RESOLVE`), mais cela ouvre des requêtes sortantes vers n'importe quel domaine |
| `MCP_DCR_ENABLED` | `true` | Enregistrement dynamique |
| `MCP_RATE_LIMIT_MAX` / `MCP_RATE_LIMIT_WINDOW` | `300` / `60` | Débit `/mcp` par IP |
| `MCP_CREDENTIAL_SECRET` | *(vide → `API_SIG_SECRET`)* | Clé HMAC de l'empreinte de mot de passe des jetons ; **la changer révoque toutes les connexions MCP** |
| `DB_MCP_USER` / `DB_MCP_PASS` | — | Utilisateur SQL **SELECT seul** (recommandé) |

---

## 5. Mise en production (o2switch)

1. **Sauvegarde** de la base (phpMyAdmin → Exporter).
2. **Déployer** le code (`git pull` + `composer install --no-dev --optimize-autoloader`) ;
   le hook `post-merge` vide `var/cache/di` (sinon `php bin/clear-cache.php`).
3. **Jouer la migration** `migrations/2026_11_mcp_oauth.sql` (phpMyAdmin → SQL, ou
   `mysql -u … oliviera_iot < migrations/2026_11_mcp_oauth.sql`). Idempotente.
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
8. **Ajouter le connecteur** :
   - **claude.ai** (et Desktop / mobile / Cowork) : Réglages → **Connecteurs** →
     **Ajouter un connecteur personnalisé** → URL `https://iot.olution.info/mcp` → Ajouter →
     **Se connecter** : la page « Autoriser l'accès » d'iot.olution.info s'ouvre ; se connecter
     avec un compte `n3_users`. Sur un plan Team/Enterprise, c'est un propriétaire de
     l'organisation qui ajoute le connecteur.
   - **Claude Code** :
     ```bash
     claude mcp add --transport http iot https://iot.olution.info/mcp
     ```
     puis `/mcp` dans une session → **Authenticate** (navigateur, redirection loopback).
9. **Tester** dans une conversation : « Donne-moi l'état de l'aquaponie » (→ `iot_overview`),
   « Montre-moi la dernière photo de la serre » (→ `iot_gallery_latest`).

### Révoquer un accès

Supprimer les jetons d'un utilisateur : `UPDATE mcp_oauth_tokens SET revoked_at = NOW() WHERE user_id = <id>;`
Désactiver le compte dans `/admin/users` coupe aussi l'accès immédiatement. Couper tout le
connecteur : `MCP_ENABLED=false`.

---

## 6. Tests

```bash
composer test:unit        # tests/Mcp, tests/OAuth, tests/Controller/McpControllerTest, câblage, routes
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
| `invalid_grant` au refresh | Refresh déjà utilisé (rotation) ou compte désactivé | Normal après une réutilisation : se reconnecter depuis Claude |
| « Redirection refusée » sur la page OAuth | `redirect_uri` hors liste | Ajouter l'URI exacte à `MCP_EXTRA_REDIRECT_URIS` (claude.ai et loopback sont intégrés) |
| `iot.control` jamais accordé | `MCP_CONTROL_ENABLED=false` ou compte lecteur | Voir `MCP_PILOTAGE.md` |
| Erreurs 500 après déploiement | Cache DI compilé obsolète | `php bin/clear-cache.php` |

Journaux : `cronlog.txt` (ou `LOG_FILE_PATH`), lignes `[mcp]`, `[mcp-oauth]`, `[control-audit]`.

Référence Claude : <https://claude.com/docs/connectors/building/authentication>.
