# Activer le connecteur MCP — tuto simple (toutes les IA)

Guide **pas à pas** pour brancher n³ IoT sur Claude, Cursor, ChatGPT ou Gemini Enterprise.

URL du connecteur : **`https://iot.olution.info/mcp`**

> Détail technique : [`MCP.md`](MCP.md). Pilotage / productivité : [`MCP_PILOTAGE.md`](MCP_PILOTAGE.md).

**Script d’aide (Windows)** :

```powershell
c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1                # menu
c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action check  # découverte OAuth / 401
c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action secrets
c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action env
c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action cursor
c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action gemini
c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action guide
```

**Vérification discovery (Windows / Linux / cPanel / Docker)** :

```powershell
php c:\projets_code\IOT_n3\serveur\tools\mcp-check-discovery.php --base=https://iot.olution.info
```

```bash
php /chemin/vers/serveur/tools/mcp-check-discovery.php --base=https://iot.olution.info
```

---

## Partie A — Serveur (une seule fois, toutes les IA)

Ces étapes sont **communes**. Sans elles, aucun client ne pourra s’authentifier.

### A1. Sauvegarder

phpMyAdmin → base `oliviera_iot` → **Exporter**.

### A2. Déployer le code

Le cron `git pull` déploie déjà ; sinon pull manuel. Puis :

```bash
composer install --no-dev --optimize-autoloader
# si besoin : php bin/clear-cache.php
```

### A3. Migrations SQL (ordre, idempotentes)

1. `migrations/2026_11_mcp_oauth.sql`
2. `migrations/2026_11_mcp_exploitation.sql`
3. `migrations/2026_11_mcp_productivite.sql`

Contrôle : `SELECT COUNT(*) FROM mcp_memory;` → au moins 6 lignes.

### A4. (Recommandé) Utilisateur MySQL lecture seule

Créer `oliviera_mcpro` avec le **seul** droit `SELECT` sur la base, puis dans `.env` :

```ini
DB_MCP_USER=oliviera_mcpro
DB_MCP_PASS=…
```

### A5. Activer dans `.env`

Générer des secrets : `c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action secrets`  
ou `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`

Bloc minimal (coller / adapter) — ou `c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action env` :

```ini
MCP_ENABLED=true
MCP_PUBLIC_BASE_URL=https://iot.olution.info
MCP_ALLOWED_ENVS=prod,s3
MCP_CONTROL_ENABLED=false
MCP_CREDENTIAL_SECRET=<64 hex>
DB_MCP_USER=oliviera_mcpro
DB_MCP_PASS=…
```

Optionnel (santé / exploitation) : `MCP_HEALTH_TOKEN`, `MCP_HEALTH_REPORT_ENABLED=true` — voir [`MCP.md` § 11](MCP.md#11-activer-en-production).

**Gemini Enterprise seulement** (en plus) :

```ini
MCP_OAUTH_CONFIDENTIAL_CLIENT_ID=gemini-n3-iot
MCP_OAUTH_CONFIDENTIAL_CLIENT_SECRET=<secret long>
```

(`c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action gemini` génère le couple.)

### A6. Cron applicatif

Déjà en place idéalement (chaque minute) :

```cron
* * * * * cd /home4/oliviera/iot.olution.info && /usr/bin/php run-cron.php >> /dev/null 2>&1
```

### A7. Vérifier que le serveur répond

```powershell
c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action check -BaseUrl https://iot.olution.info
php c:\projets_code\IOT_n3\serveur\tools\mcp-check-discovery.php --base=https://iot.olution.info
```

Attendu :

- `/.well-known/oauth-protected-resource/mcp` → JSON avec `resource` = `…/mcp`
- `/.well-known/oauth-authorization-server` → JSON (PKCE S256, `iss`)
- `POST /mcp` sans jeton → **401** + `WWW-Authenticate` / `resource_metadata`

| Code | Signification |
|------|----------------|
| **200** / **401** comme ci-dessus | OK |
| **404** | `MCP_ENABLED` encore `false`, ou rewrite `/.well-known` manquant ([`MCP.md` § 7](MCP.md#7-dépannage)) |
| **HTTP 0** (surtout via `php …mcp-check-discovery.php`) | TLS/CA PHP sous Windows — utiliser plutôt `mcp-activate.ps1 -Action check` (`curl.exe`) |

> Sur la prod actuelle, un **404** tant que le `.env` distant n’a pas `MCP_ENABLED=true` est normal.

### A8. Smoke OAuth complet (conseillé)

Compte **lecteur** de test dans `n3_users` :

```powershell
$env:MCP_SMOKE_PASSWORD='…'
php c:\projets_code\IOT_n3\serveur\tools\mcp-smoke.php --base=https://iot.olution.info --user=<compte>
```

Attendu : `SMOKE OK`.

### A9. Compte utilisateur

Chaque personne qui connecte une IA doit avoir un compte sur
[iot.olution.info](https://iot.olution.info) (`n3_users`).  
Lecture seule = rôle **lecteur** ; fichiers / CRON / productivité = **admin**.

---

## Partie B — Selon l’IA (après A)

| IA | Auth | Redirect déjà prévu ? |
|----|------|------------------------|
| Claude (web, Desktop, mobile, Cowork) | Public | Oui |
| Claude Code | Public (loopback) | Oui |
| Cursor desktop | Public (`localhost:8787`) | Oui |
| Cursor web / Agents | Public | Oui |
| ChatGPT | Public | Oui (URI stable) |
| Gemini Enterprise | **Secret** `.env` | Oui |
| Gemini grand public | — | **Non supporté** |

### B1. Claude.ai / Desktop / Cowork

1. Réglages → **Connecteurs** → **Ajouter un connecteur personnalisé**
2. URL : `https://iot.olution.info/mcp`
3. **Se connecter** → page iot.olution.info → login `n3_users` → Autoriser
4. Test : *« Donne-moi l’état de l’aquaponie »*

Plan Team / Enterprise : un **propriétaire** d’organisation ajoute le connecteur.

### B2. Claude Code

```bash
claude mcp add --transport http iot https://iot.olution.info/mcp
```

Puis dans une session : `/mcp` → **Authenticate** (navigateur, loopback).

### B3. Cursor

1. Ouvrir la config MCP utilisateur (`mcp.json`), ou :

   ```powershell
   c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action cursor
   ```

2. Contenu minimal :

   ```json
   {
     "mcpServers": {
       "n3-iot": {
         "url": "https://iot.olution.info/mcp"
       }
     }
   }
   ```

3. Relancer / reconnecter MCP dans Cursor → navigateur OAuth → login iot.olution.info.

Desktop utilise souvent `http://localhost:8787/callback` ; Agents :
`https://www.cursor.com/agents/mcp/oauth/callback` — les deux sont allowlistés.

### B4. ChatGPT

1. Créer / ajouter un serveur MCP personnalisé (connecteur) avec l’URL
   `https://iot.olution.info/mcp`, auth **OAuth**.
2. Si ChatGPT affiche une URI de redirection **différente** de
   `https://chatgpt.com/connector_platform_oauth_redirect`, l’ajouter dans
   `.env` : `MCP_EXTRA_REDIRECT_URIS=…`
3. Se connecter → consentement iot.olution.info.

### B5. Gemini Enterprise (Custom MCP Server)

1. Générer ID/secret : `c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action gemini`
2. Les mettre dans le `.env` du serveur (A5) et redéployer / recharger la config.
3. Dans la console Gemini Enterprise :

   | Champ | Valeur |
   |-------|--------|
   | MCP URL | `https://iot.olution.info/mcp` |
   | Authorization URL | `https://iot.olution.info/oauth/authorize` |
   | Token URL | `https://iot.olution.info/oauth/token` |
   | Client ID / Secret | ceux du `.env` |
   | Scopes | `iot.read offline_access` (+ `iot.control` si besoin) |
   | PKCE | activé |
   | Redirect | `https://vertexaisearch.cloud.google.com/oauth-redirect` (déjà intégré) |

4. Lancer le flux OAuth depuis Gemini.

**Gemini app grand public** : pas de connecteur MCP custom équivalent — hors portée.

---

## Partie C — Checklist rapide

- [ ] Migrations jouées
- [ ] `MCP_ENABLED=true` + `MCP_PUBLIC_BASE_URL`
- [ ] `mcp-activate check` / `mcp-check-discovery` OK
- [ ] Smoke OK (optionnel mais recommandé)
- [ ] Compte `n3_users` prêt
- [ ] Client configuré (B1–B5) + 1er appel outil

**Couper tout** : `MCP_ENABLED=false`  
**Révoquer un user** : désactiver le compte dans `/admin/users`, ou
`UPDATE mcp_oauth_tokens SET revoked_at = NOW() WHERE user_id = <id>;`

---

## Aide & dépannage

| Symptôme | Piste |
|----------|--------|
| `.well-known` = 404 HTML | Rewrite Apache → `MCP.md` § 7 |
| OAuth OK puis `initialize` 401 | En-tête `Authorization` retiré (FPM/CGI) → § 7 |
| Redirect refusé | URI hors allowlist → `MCP_EXTRA_REDIRECT_URIS` |
| Gemini `invalid_client` | ID/secret `.env` manquants ou faux |
| 403 ModSecurity | Exception `/mcp` et `/oauth/*` chez l’hébergeur |

Pare-feu Claude (sortie Anthropic) : plage `160.79.104.0/21` — détail § 11 de `MCP.md`.
