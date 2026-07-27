# Audit de bugs — n3_serveur (2026-07)

Audit de code transverse (sécurité, logique métier, robustesse, cohérence
firmware ↔ serveur). Chaque constat a été **vérifié dans le code** (chemin
d'appel remonté jusqu'à un déclencheur réel) avant d'être qualifié de bug ; les
constats non déclenchables aujourd'hui sont explicitement marqués **latent**.

Pour chaque constat, ce document propose **une ou plusieurs options de correction**
avec leurs compromis, et indique lesquelles ont été **appliquées**.

**État au 2026-07-27** : **tous les constats sont corrigés** — S1-S3 en 6.31.0, S4-S5 en
6.32.0, S6-S7 en 6.33.0, S8-S10 en 6.34.0. Les options non retenues restent documentées
ci-dessous : plusieurs (notamment S1 options B/C) demeurent pertinentes comme correctif de
fond, à arbitrer avec le constat **F2** de l'audit firmware.

> Un audit jumeau couvre le dépôt firmware : `n3_firmwires/docs/AUDIT_BUGS_2026-07.md`.
> Les constats **S1** (ici) et **F2** (là-bas) portent sur le même contrat HMAC.

## Synthèse

| # | Gravité | Sujet | Fichier principal | État |
|---|---------|-------|-------------------|------|
| S1 | 🔴 Élevé | HMAC `X-Sig-*` : 401 systématique N3PP / MSP1 / PGL sous mod_php | `AbstractHmacPostDataController.php` | ✅ **corrigé** (6.31.0) |
| S2 | 🟠 Moyen+ | Arrêt pompe « sécurité marée » annulé par le POST firmware suivant | `PumpService.php` | ✅ **corrigé** (6.31.0) |
| S3 | 🟠 Moyen | `PRAGMA table_info` (SQLite) exécuté sur MySQL → garde `name` inerte | `PumpService.php` | ✅ **corrigé** (6.31.0) |
| S4 | 🟠 Moyen | Exemption CSRF accordée à un canal **ambiant** (cookie) | `CsrfMiddleware.php` | ✅ **corrigé** (6.32.0) |
| S5 | 🟠 Moyen | Rôle manquant en session → repli sur `ROLE_ADMIN` | `AuthService.php` | ✅ **corrigé** (6.32.0) |
| S6 | 🟡 Faible+ | `EXIT_FLOOD` renvoyé à chaque tick → log toutes les minutes | `FloodStateMachine.php` | ✅ **corrigé** (6.33.0) |
| S7 | 🟡 Faible (latent) | Hystérésis inversée pour `DIRECTION_HIGH` sans seuil explicite | `AbstractVitalsDerivedAlertService.php` | ✅ **corrigé** (6.33.0) |
| S8 | 🟡 Faible | `updated` compte les tentatives, pas les lignes modifiées | `OutputRepository.php` | ✅ **corrigé** (6.34.0) |
| S9 | 🟡 Faible | Rate-limit contournable via `X-Forwarded-For` | `AbstractPostDataController.php` | ✅ **corrigé** (6.34.0) |
| S10 | ⚪ Robustesse | Divers (division par zéro latente, `strtotime` false, fuseau, session) | divers | ✅ **corrigé** (6.34.0) |

---

## S1 — 🔴 HMAC `X-Sig-*` : corps signé vide → 401 systématique pour N3PP / MSP1 / PGL — ✅ CORRIGÉ

> **Correctif appliqué (6.31.0)** — **option A** ci-dessous. Une signature `X-Sig-*`
> présente mais non vérifiable ne rejette plus à elle seule : la requête poursuit sur le
> contrat legacy `timestamp`+`signature` (qui ne dépend PAS du corps, et que `n3DataPost`
> place justement dans le body) puis sur `api_key`. `HMAC_STRICT_MODE=true` restaure le
> rejet 401. `authenticatedByHmac` **reste false** en cas de repli : la seule présence d'un
> en-tête `X-Sig-*` ne vaut jamais authentification (garde-fou couvert par test). L'audit
> HMAC distingue le repli du rejet via le motif `signature_invalid_soft_fallback`.
> FFP3 est inchangé (il a sa reconstitution canonique). Les options B et C restent
> pertinentes comme correctif de fond.

### Constat

Le correctif de la **5.1.12** (cf. `CHANGELOG.md`) documente un incident réel de
production :

> **Cause** : sous `application/x-www-form-urlencoded`, `php://input` est souvent
> vide côté mod_php alors que le firmware signe le corps complet →
> `Signature incorrecte` malgré NTP et secret OK.

Le correctif — reconstitution canonique du corps via `App\Security\Ffp3HmacPostBody` —
n'a été appliqué **qu'à FFP3** (`src/Controller/Ffp3/PostDataController.php:200-214`).

Les trois autres chemins de body-signing lisent **uniquement** le corps brut :

| Endpoint | Fichier | Ligne |
|----------|---------|-------|
| `POST /post-data` N3PP / MSP1 | `src/Controller/AbstractHmacPostDataController.php` | 307-319 |
| `POST /*-heartbeat` N3PP / MSP1 | `src/Controller/Concerns/LegacyHeartbeatHandler.php` | 288-291 |
| `POST /pgl/post-data` | `src/Controller/Concerns/PglHmacAuthTrait.php` | 82-88 |

Sous mod_php, `$rawBody` vaut `''` → `SignatureValidator::isValidForBody('', …)`
échoue → **401**, et **sans repli sur `api_key`** :
`HmacAuthTrait::validateHmacOrFallback()` (`src/Controller/Concerns/HmacAuthTrait.php:66-89`)
retourne directement `Signature incorrecte, 401`.

### Pourquoi c'est déclenchable

Le firmware n3pp/msp émet ces en-têtes **dès que `API_SIG_SECRET` est non vide** :

- `n3_firmwires/shared/n3_data/src/n3_data.cpp:133-149` — `n3DataPost()` pose
  `X-Sig-Timestamp` / `X-Sig-Nonce` / `X-Sig-Hmac` si `sigSecret != nullptr` et
  epoch RTC valide ;
- `n3_firmwires/n3pp/src/n3pp_network.cpp:59` et `msp/src/msp_network.cpp:61` —
  `cfg.sigSecret = (API_SIG_SECRET[0] != '\0') ? API_SIG_SECRET : nullptr;` ;
- même chemin pour le heartbeat (`n3DataSendHeartbeat` → `n3DataPost`).

Et `docs/API_MSP1_N3PP.md:195` **recommande** justement de l'activer :

> Authentification POST | HMAC ou API_KEY | **Activer HMAC en prod (`API_SIG_SECRET` non vide partout)**

Autrement dit : suivre la recommandation du dépôt fait tomber la totalité des
POST **et** des heartbeats N3PP/MSP1 (et PGL) en 401 tant que le serveur tourne
sous mod_php. Aujourd'hui le problème est masqué uniquement parce que le secret
n'est pas déployé sur ces firmwares.

### Options de correction

**Option A — repli souple (mitigation immédiate, faible risque).**
Aligner ces chemins sur `App\Security\DeviceSignatureValidator` (déjà en place
pour la galerie) : une signature `X-Sig-*` **invalide** ne rejette plus, elle
retombe sur `api_key` ; le rejet strict devient opt-in.

```php
// HmacAuthTrait::validateHmacOrFallback(), branche __sig_hmac
if (!SignatureValidator::isValidForBody(...)) {
    $this->recordHmacAudit('reject', 'x_sig_body', [...], 'signature_invalid');
    if ($this->isHmacStrictMode()) {
        return ResponseHelper::text($response, 'Signature incorrecte', 401);
    }
    // Repli api_key (parité DeviceSignatureValidator) : une horloge/corps
    // désaligné ne doit pas faire perdre la mesure.
    return null;
}
```

*Avantage* : aucune perte de donnée, l'audit HMAC continue de tracer les rejets.
*Inconvénient* : affaiblit le contrôle tant que `HMAC_STRICT_MODE` est off — le
body-signing redevient purement informatif.

**Option B — reconstitution canonique par famille (correctif durable).**
Généraliser le mécanisme FFP3 : un `HmacPostBody` par famille (ordre des champs
aligné sur `n3DataPost`, qui émet les champs dans l'ordre du tableau
`N3DataField[]` puis `timestamp` / `signature`).

⚠️ Attention : contrairement à FFP3, `n3DataPost` **inclut** `timestamp=…&signature=…`
**dans le corps signé** (`n3_data.cpp:101-105`, ajoutés *avant* le calcul du HMAC
ligne 143). Une reconstitution calquée sur `Ffp3HmacPostBody` — qui les exclut via
`AUTH_KEYS` — produirait un corps différent. La reconstitution N3PP/MSP doit donc
les **conserver, en dernière position**.

*Avantage* : rétablit une vraie vérification d'intégrité du corps.
*Inconvénient* : recrée un couplage fort firmware ↔ serveur sur l'ordre et le
formatage des champs (cf. F2 dans l'audit firmware).

**Option C — supprimer la cause (correctif de fond, à planifier).**
Faire signer au firmware un condensé stable plutôt que le corps sérialisé
(ex. `sha256` des paires triées), ou poster en `application/json` — cas où
`php://input` reste lisible sous mod_php. Supprime définitivement la classe de
bug, mais impose une évolution firmware + serveur coordonnée.

**Recommandation** : A maintenant (arrêt de l'hémorragie potentielle), B ou C
ensuite selon l'appétit pour une évolution de contrat.

### Vérification suggérée

Ajouter à `PostDataHmacAuthTest` l'équivalent pour MSP/N3PP/PGL : requête avec
`parsedBody` peuplé + **stream vide** + en-têtes `X-Sig-*` valides sur le corps
réel → doit passer (et non 401).

---

## S2 — 🟠 L'arrêt de pompe « sécurité marée » est annulé par le POST firmware suivant — ✅ CORRIGÉ

> **Correctif appliqué (6.31.0)** — combinaison des options A et B : `PumpService::setState()`
> écrit désormais `requestTime` et `lastModifiedBy = 'cron'` (`PumpService::MODIFIED_BY`), et
> la clause de priorité s'appuie sur `OutputRepository::SERVER_OWNED_SOURCES` (`web`, `cron`)
> au lieu du littéral `'web'`. La fenêtre de 12 s existante suffit : ffp5cs relit
> `/api/outputs/state` toutes les 6 s (`REMOTE_FETCH_FALLBACK_INTERVAL_MS`), soit deux polls
> dans la fenêtre. Sémantique inchangée pour `lastModifiedBy IS NULL`.

### Constat

`CronOrchestrator::checkTideSystem()` (`src/Command/CronOrchestrator.php:437-459`)
coupe la pompe aquarium quand l'écart-type de `EauAquarium` s'effondre, puis
programme un redémarrage à +5 min.

Mais `PumpService::setState()` (`src/Service/PumpService.php:112-142`) écrit :

```sql
UPDATE {$table} SET state = :state WHERE gpio = :gpio
```

— **sans** `requestTime = NOW()` ni `lastModifiedBy`, contrairement à
`OutputRepository::updateState()` (`src/Repository/OutputRepository.php:162-176`).

Or la protection anti-écrasement du POST firmware repose exactement sur ces deux
colonnes (`OutputRepository::batchUpdateStatesSingleQuery()`, ligne 494) :

```sql
AND (lastModifiedBy != 'web' OR requestTime IS NULL
     OR requestTime < DATE_SUB(NOW(), INTERVAL :priority SECOND))
```

Après l'arrêt CRON, `lastModifiedBy` vaut toujours `'esp32'` et `requestTime` est
ancien → la clause passe → le **premier POST firmware suivant** (fenêtre de
protection de 12 s, `PHYSICAL_COMMAND_WEB_PRIORITY_SECONDS`) réécrit GPIO 16 avec
l'état renvoyé par l'ESP32, c'est-à-dire **pompe ON**.

Comme FFP3 poste toutes les ~30-60 s et que l'ESP32 relit `/api/outputs/state`
séparément, la commande d'arrêt peut être effacée avant d'avoir été lue.
Même problème pour `runPompeAqua()` (`RestartPumpCommand`) et `rebootEsp()`
(GPIO 110), qui perdent aussi la fenêtre de priorité de 20 s.

### Options de correction

**Option A (recommandée) — réutiliser le repository.**
Injecter `OutputRepository` dans `PumpService` et déléguer :

```php
public function setState(int $gpio, int $state): void
{
    $this->outputRepository->updateState($gpio, $state, 'cron');
}
```

⚠️ La clause de protection teste `lastModifiedBy != 'web'` : avec `'cron'` la
commande resterait écrasable. Utiliser `'web'` (sémantique « commande serveur,
prioritaire ») ou, mieux, élargir la clause à une liste de sources prioritaires
(`NOT IN ('web','cron','server-force')`). Cela supprime en prime la duplication
de logique SQL entre `PumpService` et `OutputRepository` (et rend S3 sans objet).

**Option B — correctif minimal, sur place.**

```sql
UPDATE {$table}
   SET state = :state, requestTime = NOW(), lastModifiedBy = :modifiedBy
 WHERE gpio = :gpio
```

Moins invasif, mais laisse deux écrivains concurrents sur la même table avec des
sémantiques à maintenir en parallèle.

**Option C — durcir la fenêtre côté lecture.**
Faire porter la priorité par une colonne dédiée (`priorityUntil DATETIME`) plutôt
que par le couple `lastModifiedBy`/`requestTime` : plus explicite, mais nécessite
une migration.

---

## S3 — 🟠 `PRAGMA table_info` (SQLite) exécuté sur MySQL → garde `name` inerte en production — ✅ CORRIGÉ

> **Correctif appliqué (6.31.0)** — **option B** : introspection portable branchée sur
> `PDO::ATTR_DRIVER_NAME` (`SHOW COLUMNS` en MySQL, `PRAGMA table_info` en SQLite), mémoïsée
> **par table**. C'était le prérequis de S2 : sans elle, écrire `requestTime`/`lastModifiedBy`
> aurait cassé les schémas de test minimaux.

`PumpService::outputsTableHasNameColumn()` (`src/Service/PumpService.php:60-86`) :

```php
$stmt = $this->pdo->query("PRAGMA table_info({$table})");
```

`PRAGMA` n'existe pas en MySQL. Avec `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
(`src/Config/Database.php:35`), l'appel lève une `PDOException`, avalée par le
`catch (\Throwable)` ligne 80 → `outputsTableHasNameColumn = false`.

Conséquences en production :

1. la « CORRECTION v11.38 » (`name IS NOT NULL AND name != ''`) n'est **jamais**
   appliquée — le repli ligne 137 s'exécute toujours ;
2. une requête invalide part vers MySQL à chaque première écriture de pompe
   (bruit dans le log d'erreurs serveur) ;
3. la mémoïsation est portée par l'instance **sans clé de table** : un changement
   d'environnement via `TableConfig::setEnvironment()` réutilise le verdict de la
   table précédente (sans effet ici puisque toujours `false`, mais piège latent).

Le bon motif existe déjà dans le dépôt, `src/Service/OutputCacheService.php:83-85` :

```php
$nameFilter = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
    ? " AND name IS NOT NULL AND name != ''" : '';
```

### Options de correction

**Option A (recommandée)** — supprimer la détection : appliquer le motif
`OutputCacheService` (détection du driver, pas introspection du schéma).

**Option B** — garder l'introspection mais la rendre portable :
`SHOW COLUMNS FROM \`{$table}\` LIKE 'name'` en MySQL, `PRAGMA` en SQLite ; et
mémoïser dans `array<string,bool>` indexé par nom de table.

**Option C** — sans objet si S2/Option A est retenue (`OutputRepository` porte
déjà le filtre `name`).

---

## S4 — 🟠 Exemption CSRF accordée à un canal ambiant (cookie) — ✅ CORRIGÉ

> **Correctif appliqué (6.32.0)** — **option A**. Nouveau `CsrfMiddleware::hasExplicitToken()` :
> l'exemption ne retient plus que l'en-tête (`Authorization: Bearer` / `X-Admin-Token`) et
> `?token=`. Le cookie `admin_token` n'exempte plus — une écriture qui n'a que lui doit
> fournir un `X-CSRF-Token` / `_csrf_token`. L'option B (en-tête seul, retrait de `?token=`)
> reste la cible M4, conditionnée à la migration du front.

`CsrfMiddleware` (`src/Middleware/CsrfMiddleware.php:91`) exempte de jeton CSRF
toute requête pour laquelle `AuthService::isAuthenticatedByToken()` répond vrai.
Son propre docblock justifie l'exemption ainsi :

> exempte les requêtes authentifiées par un token d'accès explicite […] : un
> secret **non-ambiant** n'est pas falsifiable en cross-site

Or `isAuthenticatedByToken()` (`src/Security/AuthService.php:201-216`) teste
**en premier** `$_COOKIE['admin_token']` — un cookie est par définition ambiant,
posé pour 30 jours par `setAdminTokenCookie()` (ligne 259). La prémisse de
l'exemption est donc fausse pour ce canal.

`AuthService` expose déjà la méthode correcte : `hasValidHeaderToken()`
(ligne 226), dont le docblock dit explicitement « utilisable comme exemption CSRF ».

**Atténuation existante** : le cookie est `SameSite=Lax`, ce qui bloque un POST
cross-**site**. La faille résiduelle couvre les cas non couverts par SameSite :
sous-domaine same-site compromis, navigateur ancien, ou tout futur assouplissement
du `SameSite`. C'est donc un défaut de défense en profondeur, pas une CSRF
directement exploitable aujourd'hui.

### Options de correction

**Option A (recommandée, 3 lignes)** — n'exempter que les canaux non-ambiants :

```php
$queryToken = $request->getQueryParams()['token'] ?? null;
$byExplicitToken = $this->authService->hasValidHeaderToken()
    || (is_scalar($queryToken) && $this->authService->validateToken((string) $queryToken));
if ($byExplicitToken) {
    return $handler->handle($request);
}
```

**Option B** — n'exempter que l'en-tête (`hasValidHeaderToken()` seul) et migrer
le front vers `X-Admin-Token`. C'est la cible déjà documentée sous « M4 » ; plus
propre, mais casse les liens de contrôle partagés tant que le front n'est pas migré.

---

## S5 — 🟠 Rôle manquant en session → repli sur `ROLE_ADMIN` — ✅ CORRIGÉ

> **Correctif appliqué (6.32.0)** — **option A** : repli sur `User::ROLE_READER` (fail-closed),
> cohérent avec le `?? 99` déjà appliqué au rôle requis par `hasMinimumRole()`. L'option B
> (invalider la session) a été écartée : plus stricte, mais elle déconnecte l'utilisateur sur
> une anomalie de stockage dont il n'est pas responsable.

`AuthService::getCurrentRole()` (`src/Security/AuthService.php:288`) :

```php
return (string) ($_SESSION[self::SESSION_ROLE_KEY] ?? User::ROLE_ADMIN);
```

Une session authentifiée sans clé `auth_role` obtient donc le rôle **le plus
élevé**. `login()` la pose toujours (ligne 105), mais le repli s'active sur toute
session survivant à un déploiement antérieur à l'introduction du champ, ou à une
désérialisation partielle du store de sessions.

C'est un « fail-open » sur un contrôle d'accès, à contre-courant du reste du
fichier : `hasMinimumRole()` (ligne 306) utilise à juste titre `?? 99` (fail-closed)
pour le rôle requis.

### Options de correction

**Option A (recommandée)** — repli fail-closed :

```php
return (string) ($_SESSION[self::SESSION_ROLE_KEY] ?? User::ROLE_READER);
```

**Option B** — traiter la session comme invalide : `logout()` puis `return null`.
Plus strict (l'utilisateur doit se reconnecter), et supprime toute ambiguïté.

---

## S6 — 🟡 `EXIT_FLOOD` renvoyé à chaque tick → une ligne de log par minute — ✅ CORRIGÉ

> **Correctif appliqué (6.33.0)** — **option A** (correction de la machine), avec la
> répercussion sur `flood_alert.h` que l'option prévoyait (firmware 15.24) : la parité
> annoncée entre les deux machines est conservée. Vérification faite avant de toucher au
> firmware : `_highAquaSent`, seul effet de `ExitedFlood` chez l'appelant ffp5cs, n'est
> **jamais lu** — l'impact y est donc nul. L'option B (garder la parité stricte et gater le
> log côté appelant) devenait sans objet.

`FloodStateMachine::evaluate()` (`src/Service/DerivedAlert/FloodStateMachine.php:88-97`)
renvoie `DECISION_EXIT_FLOOD` dès que le niveau est stable au-dessus du seuil de
ré-armement — **sans vérifier que l'on était effectivement en trop-plein**, et
sans réinitialiser `aboveResetSinceTs` après la sortie.

Conséquence : dans le cas **nominal** (aquarium jamais en trop-plein, distance
au-dessus de `limFlood + hystérésis`), la condition `elapsedAbove >= resetStableSec`
reste vraie indéfiniment → `EXIT_FLOOD` à chaque évaluation → le CRON, qui tourne
**chaque minute**, journalise :

```
Sortie de l'état trop-plein (niveau stabilisé au-dessus de l'hystérésis).
```

≈ 1 440 lignes/jour (`Ffp3DerivedAlertService.php:231-233`). Pas de mail envoyé,
mais bruit de journalisation permanent et signal trompeur en exploitation.

La machine firmware d'origine (`ffp5cs/include/automatism/flood_alert.h`) a la
même forme, mais son appelant se contente de remettre un drapeau à `false`
(idempotent, sans log) et tourne au rythme des réveils, pas à la minute.

### Options de correction

**Option A** — corriger la machine (améliore aussi la parité sémantique) :

```php
if ($elapsedAbove >= $resetStableSec) {
    $wasInFlood = $state['inFlood'];
    $state['inFlood'] = false;
    $state['aboveResetSinceTs'] = 0;   // évite de re-décider à chaque tick
    return $wasInFlood ? self::DECISION_EXIT_FLOOD : self::DECISION_NONE;
}
```

À répercuter sur `flood_alert.h` côté firmware pour maintenir la parité annoncée.

**Option B** — préserver la parité stricte et corriger l'appelant :
`Ffp3DerivedAlertService::checkFlood()` ne journalise que si `$floodState['inFlood']`
était vrai avant l'appel. Zéro risque sur la logique partagée, mais laisse la
machine renvoyer une décision non signifiante.

---

## S7 — 🟡 (latent) Hystérésis inversée pour `DIRECTION_HIGH` sans seuil explicite — ✅ CORRIGÉ

> **Correctif appliqué (6.33.0)** — **option A** : le défaut est calculé AVANT la négation
> (`seuil - 5 %`). Options B (paramètre obligatoire) et C (`HighValueAlertEvaluator`
> symétrique) écartées : la première casse la signature pour un cas qui a maintenant un
> défaut correct, la seconde duplique la logique. Nouveau `LatchedThresholdDirectionTest`
> couvrant les deux sens, avec et sans seuil explicite.

`AbstractVitalsDerivedAlertService::evaluateLatchedLowValue()`
(`src/Service/DerivedAlert/AbstractVitalsDerivedAlertService.php:168-177`) implémente
le seuil HAUT en niant valeur et seuils, puis en réutilisant `LowValueAlertEvaluator`.

Avec `$clearThreshold === null`, l'évaluateur calcule son défaut **après** la
négation (`LowValueAlertEvaluator.php:26`) :

```
c' = t' + t'/20 = -threshold - threshold/20 = -1,05 × threshold
```

Le ré-armement devient `value < 1,05 × threshold`, soit **au-dessus** du seuil de
déclenchement (`value > threshold`) au lieu d'en dessous. Toute valeur dans
`]threshold ; 1,05 × threshold[` déclenche puis ré-arme à chaque évaluation →
alerte en battement.

**Latent** : les deux seuls appels `DIRECTION_HIGH` (`MspDerivedAlertService::checkHeat()`,
ligne 143) passent un seuil explicite (`$threshold - 2.0`), qui donne bien
`temp < threshold - 2`. Le piège n'attend qu'un futur appelant qui omettra le
paramètre — d'autant que la signature l'autorise et que le docblock ne l'interdit pas.

### Options de correction

**Option A** — calculer le défaut avant négation :

```php
if ($direction === self::DIRECTION_HIGH) {
    $clearThreshold ??= $threshold - ($threshold / 20.0);
    $decision = LowValueAlertEvaluator::evaluate(-$value, -$threshold, $latched, -$clearThreshold);
}
```

**Option B** — rendre le paramètre obligatoire pour `DIRECTION_HIGH`
(`\InvalidArgumentException` si null) : le fait échouer bruyamment plutôt que
silencieusement.

**Option C** — remplacer l'astuce de négation par un `HighValueAlertEvaluator`
symétrique : plus lisible, au prix d'un peu de duplication.

---

## S8 — 🟡 `updated` compte les tentatives, pas les lignes modifiées — ✅ CORRIGÉ

> **Correctif appliqué (6.34.0)** — **option A**. Le piège MySQL décrit ci-dessous (0 ligne
> quand rien ne CHANGE) ne se matérialise pas ici : la requête écrit toujours
> `requestTime`, donc la ligne change dès qu'elle existe. Seul un ré-enregistrement à
> l'identique dans la MÊME seconde renverrait 0 — bénin. `PDO::MYSQL_ATTR_FOUND_ROWS` a été
> écarté : il change la sémantique de `rowCount()` pour **toute** l'application.
> `NOW()` → `CURRENT_TIMESTAMP` (synonyme exact en MySQL, portable SQLite) rend au passage
> la méthode testable unitairement.

`OutputRepository::updateMultipleParameters()` (`src/Repository/OutputRepository.php:270`) :

```php
if ($stmt->execute([...])) { $updated++; }
```

`PDOStatement::execute()` renvoie `true` dès que la requête part sans erreur —
**y compris quand elle ne touche aucune ligne** (GPIO absent de la table).
Le compteur remonté à l'UI via `OutputService::updateMultipleParameters()`
(`src/Service/OutputService.php:249`) puis `Ffp3\OutputController` annonce donc
des paramètres « enregistrés » qui ne l'ont pas été.

### Options de correction

**Option A** — compter les lignes réellement touchées :

```php
$stmt->execute([...]);
$updated += $stmt->rowCount() > 0 ? 1 : 0;
```

⚠️ En MySQL, `rowCount()` renvoie 0 quand l'`UPDATE` n'a rien **changé** (valeur
identique) : un paramètre ré-enregistré à l'identique compterait comme échec.
Ajouter `PDO::MYSQL_ATTR_FOUND_ROWS => true` à la connexion pour compter les
lignes *trouvées*, ou expliciter la sémantique retenue.

**Option B** — distinguer `attempted` / `changed` dans le tableau de retour et
laisser l'UI choisir ce qu'elle affiche. Plus verbeux, mais sans ambiguïté.

---

## S9 — 🟡 Rate-limit contournable via `X-Forwarded-For` — ✅ CORRIGÉ

> **Correctif appliqué (6.34.0)** — **option A**, mais par EXTRACTION plutôt que par
> réécriture : `RateLimitMiddleware::clientIp()` implémentait déjà ce durcissement,
> correctement et avec support IPv6. Il est déplacé verbatim dans
> `App\Util\ClientIpResolver`, désormais utilisé par les trois appelants — les limiteurs
> firmware héritent donc aussi d'IPv6 (`inet_pton`, CIDR v4/v6). L'option B (clé composite
> IP + sensor) reste possible en complément si un jour plusieurs appareils partagent une IP.

`AbstractPostDataController::enforceFirmwareRateLimit()`
(`src/Controller/AbstractPostDataController.php:100-106`) et son jumeau dans
`LegacyHeartbeatHandler` (lignes 92-98) construisent la clé de limitation ainsi :

```php
$xff = $request->getHeaderLine('X-Forwarded-For');
if ($xff !== '') { $ip = trim(explode(',', $xff)[0]); }
```

L'en-tête est pris tel quel, sans liste de proxys de confiance : un client qui
fait varier `X-Forwarded-For` obtient un compteur neuf à chaque requête, ce qui
annule la limite. La même faiblesse permet d'empoisonner le compteur d'une IP
tierce (déni de service ciblé) quand la limite est active.

Portée réduite : la limite est **désactivée par défaut** (`FIRMWARE_RATE_LIMIT_MAX = 0`)
et `RateLimiter` est fail-open par conception.

### Options de correction

**Option A (recommandée)** — ne faire confiance à `X-Forwarded-For` que si
`REMOTE_ADDR` appartient à une liste `TRUSTED_PROXIES` (`.env`) ; sinon
`REMOTE_ADDR` seul.

**Option B** — clé composite `REMOTE_ADDR + api_key/sensor` : un firmware
légitime reste identifiable même derrière un proxy, et l'en-tête ne pilote plus
rien à lui seul.

---

## S10 — ⚪ Robustesse (aucun déclencheur connu aujourd'hui) — ✅ CORRIGÉ

> **Correctifs appliqués (6.34.0)** : les cinq points ci-dessous, chacun avec le *fix*
> proposé. Tests ajoutés pour les trois testables (garde intervalle nul + `strtotime` faux
> dans `RealtimeHealthTraitTest` ; `APP_TIMEZONE` dans `ReadingTimeParserTest` ; session
> sans `auth_time` dans `AuthServiceTest`).

Regroupés ici : constats corrects mais non déclenchables par la configuration
actuelle. À traiter en défense en profondeur.

1. **Division par zéro latente** — `RealtimeHealthTrait::sensorUptimePercentage()`
   (`src/Service/Realtime/RealtimeHealthTrait.php:54`) : `($days * 24 * 60) / $intervalMinutes`
   lève `DivisionByZeroError` (fatal en PHP 8) si l'intervalle vaut 0. Les deux
   appelants passent des constantes non nulles (`2` et `EXPECTED_READING_INTERVAL_MINUTES`),
   mais le paramètre est un `int` libre.
   *Fix* : `if ($intervalMinutes <= 0) return 0.0;` (cohérent avec le garde-fou
   déjà présent dans `uptimePercentage()`).

2. **`strtotime()` faux interprété comme epoch 0** — `moduleUptimeSecondsFromDate()`
   (même fichier, ligne 67) : `(int) (time() - strtotime($firstReadingDate))` donne
   ~56 ans d'uptime si la date est illisible.
   *Fix* : `$ts = strtotime($firstReadingDate); return $ts !== false ? time() - $ts : null;`

3. **Divergence de fuseau** — `ReadingTimeParser` (`src/Util/ReadingTimeParser.php:15`)
   code en dur `Europe/Paris` comme fuseau de stockage, alors que `DisplayTime`
   (`src/Util/DisplayTime.php:29`) et `Database::currentUtcOffset()`
   (`src/Config/Database.php:66`) lisent `$_ENV['APP_TIMEZONE']`. Changer
   `APP_TIMEZONE` décalerait silencieusement les horodatages Highcharts.
   *Fix* : lire `APP_TIMEZONE` partout, avec `Europe/Paris` en défaut.

4. **Session sans expiration** — `AuthService::isAuthenticated()`
   (`src/Security/AuthService.php:153-160`) n'applique le délai de 2 h que si
   `$_SESSION['auth_time']` existe ; sans cette clé, la session ne périme jamais.
   *Fix* : traiter l'absence comme expirée (`$_SESSION['auth_time'] ?? 0`).

5. **Ordonnancement non déterministe** — `SensorReadRepository::getLastReadings()`
   (`src/Repository/SensorReadRepository.php:208`) trie par `reading_time DESC`
   seul ; deux lignes de la même seconde donnent une « dernière lecture »
   arbitraire (les alertes dérivées et la page contrôle en dépendent).
   *Fix* : `ORDER BY reading_time DESC, id DESC`.

---

## Points vérifiés et écartés (non-bugs)

Consignés pour éviter de les ré-auditer.

- **Injection SQL** : tous les noms de table interpolés passent par
  `TableValidator` / `TableConfig` (listes blanches) ou sont des constantes de
  classe ; les valeurs passent par des requêtes préparées. Les clés de GPIO
  interpolées dans `batchUpdateStatesSingleQuery()` (ligne 487) proviennent
  exclusivement de tableaux internes à clés entières.
- **`SeriesDownsampler::lttbIndices()`** : les bornes de buckets restent dans
  `[1, count-1]` car `bucketSize > 1` est garanti par le garde `count > maxPoints`
  en amont — pas de dépassement d'index ni d'indices dupliqués.
- **`Ffp3WaterLevelUnit::scaleSensorRowsFromMmToCm()`** : le `foreach ($rows as &$row)`
  est bien suivi d'un `unset($row)` (pas de référence pendante).
- **`SensorRepository::insertAtomically()`** : la `PDOException` de doublon est
  bien filtrée sur le code driver 1062 avec repli SQLSTATE 23000 ; en MySQL une
  instruction en échec n'invalide pas la transaction englobante.
- **`FeedingScheduleValidator::assertHourRanges()`** : le rejet des non-entiers
  (`(float) $raw !== (float) $hour`) est correct, y compris pour `"8.0"`.
