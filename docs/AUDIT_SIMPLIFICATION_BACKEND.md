# Audit de simplification backend — serveur n3 IoT

**Date** : 2026-03-20  
**Perimetre** : `serveur/src/`, `serveur/config/`, `serveur/public/index.php`  
**Exclusions front-end** : `templates/*.twig`, `public/assets/`, `*.css`, `*.js` — aucun de ces fichiers n'est modifie.

---

## 1. Inventaire des hotspots

### H01 — `getFirmwareVersion()` dupliquee dans MspSensorRepository / N3ppSensorRepository

| Propriete | Valeur |
|-----------|--------|
| Fichiers | `src/Repository/MspSensorRepository.php:37-43`, `src/Repository/N3ppSensorRepository.php:27-33` |
| Type | Duplication identique |
| Description | Code strictement identique (requete SQL sur `$this->getTableName()`). La methode abstraite parente `AbstractSensorRepository` a deja `getTableName()`. |
| Simplification | Deplacer `getFirmwareVersion()` dans `AbstractSensorRepository`. |
| Lignes supprimees | ~14 |
| Risque regression | **Faible** — meme logique, meme table via `getTableName()` |

### H02 — `requireAuth()` instancie AuthService hors du conteneur DI

| Propriete | Valeur |
|-----------|--------|
| Fichier | `src/Controller/AbstractOutputController.php:120-136` |
| Type | Mauvaise pratique architecturale |
| Description | `new \App\Security\AuthService()` au lieu d'utiliser le service deja enregistre dans `config/dependencies.php:179`. Peut causer des effets de bord (sessions demarrees en doublon). |
| Simplification | Injecter `AuthService` dans le constructeur de `AbstractOutputController`. Mettre a jour `dependencies.php` pour les constructeurs enfants `MspOutputController` et `N3ppOutputController`. |
| Lignes modifiees | ~15 (constructeur + requireAuth) |
| Risque regression | **Faible** — AuthService a un constructeur sans parametre, deja injectable |

### H03 — `getDefaultParams()` dupliquee dans MspOutputController / N3ppOutputController

| Propriete | Valeur |
|-----------|--------|
| Fichiers | `src/Controller/Msp/MspOutputController.php:78-90`, `src/Controller/N3pp/N3ppOutputController.php:82-95` |
| Type | Duplication structurelle |
| Description | Meme patron (map cle→valeur par defaut), seules les cles 104-105 different (ServoHB/ServoGD vs HeureArrosage/tempsArrosage). |
| Simplification | Declarer `abstract protected function getDefaultParamKeys(): array` dans `AbstractOutputController` et implementer `getDefaultParams()` une seule fois dans la classe parente. |
| Lignes supprimees | ~15 |
| Risque regression | **Faible** — logique triviale, valeurs de fallback uniquement |

### H04 — `updateParameters()` dupliquee dans MspOutputController / N3ppOutputController

| Propriete | Valeur |
|-----------|--------|
| Fichiers | `src/Controller/Msp/MspOutputController.php:174-214`, `src/Controller/N3pp/N3ppOutputController.php:171-211` |
| Type | Duplication quasi-identique |
| Description | Meme flux : auth → extraction params → normalisation mailNotif/WakeUp → appel `outputRepo->updateParameterByName()`. Aucune difference de logique entre les deux. |
| Simplification | Deplacer dans `AbstractOutputController` avec acces au repo via `abstract protected function getOutputRepository()`. |
| Lignes supprimees | ~40 |
| Risque regression | **Faible** — logique identique, meme contrat |

### H05 — `handleOutputCreate()` dupliquee dans MspOutputController / N3ppOutputController

| Propriete | Valeur |
|-----------|--------|
| Fichiers | `src/Controller/Msp/MspOutputController.php:216-239`, `src/Controller/N3pp/N3ppOutputController.php:264-287` |
| Type | Duplication structurelle |
| Description | Meme patron : extraire body → construire array de params avec defaults → `batchUpdateParameters()`. Seules les cles 104-105 different. |
| Simplification | Factoriser dans `AbstractOutputController` en reutilisant `getDefaultParamKeys()`. |
| Lignes supprimees | ~25 |
| Risque regression | **Faible** — reutilise les memes cles que getDefaultParams |

### H06 — `setOutput()` dupliquee dans MspOutputController / N3ppOutputController

| Propriete | Valeur |
|-----------|--------|
| Fichiers | `src/Controller/Msp/MspOutputController.php:124-169`, `src/Controller/N3pp/N3ppOutputController.php:133-166` |
| Type | Duplication avec variante |
| Description | Meme structure mais MSP supporte gpio + name, N3PP gpio uniquement. Les deux partagent auth, dispatch output_create, state normalization. |
| Simplification | Extraire la structure commune dans `AbstractOutputController::setOutput()` avec `abstract protected function doSetOutput(array $params, int $board): array` (meme patron que `doToggle`). |
| Lignes supprimees | ~30 |
| Risque regression | **Moyen** — MSP a une branche `name` que N3PP n'a pas. Necessite tests avant/apres. |

### H07 — `getParametersForBoard()`, `updateParameterByName()`, `batchUpdateParameters()` dupliquees dans les repositories

| Propriete | Valeur |
|-----------|--------|
| Fichiers | `src/Repository/MspOutputRepository.php:62-125`, `src/Repository/N3ppOutputRepository.php:106-170` |
| Type | Duplication structurelle |
| Description | Meme logique (PARAM_GPIO_MAP, reverse map, boucles). Seul le contenu de `PARAM_GPIO_MAP` differe (cles 104-105). |
| Simplification | Deplacer dans `AbstractOutputRepository` avec `abstract protected function getParamGpioMap(): array`. |
| Lignes supprimees | ~60 |
| Risque regression | **Moyen** — schemas GPIO differents, doit etre teste avec les deux modules |

### H08 — HeartbeatController utilise `Database::getConnection()` directement

| Propriete | Valeur |
|-----------|--------|
| Fichier | `src/Controller/Ffp3/HeartbeatController.php:92` |
| Type | Mauvaise pratique (acces direct BDD hors repository) |
| Description | Le controleur fait directement `$pdo = Database::getConnection()` et les requetes SQL inline, au lieu de passer par un repository. Viole le patron MVC/repository du reste du projet. |
| Simplification | Injecter PDO dans le constructeur. Optionnel : creer `HeartbeatRepository` pour l'insertion et la whitelist de tables. |
| Lignes modifiees | ~20 |
| Risque regression | **Faible** — alignement avec le reste de l'architecture |

### H09 — `parameterMap` dupliquee dans OutputService

| Propriete | Valeur |
|-----------|--------|
| Fichier | `src/Service/OutputService.php:84-99` et `208-224` |
| Type | Duplication interne + incohérence |
| Description | Le meme mapping gpio↔nom est defini deux fois (sens inverse). De plus, `OutputSyncService::GPIO_MAPPING` (ligne 16-45) definit un mapping similaire mais plus complet. Triple source de verite. |
| Simplification | Utiliser `OutputSyncService::getGpioMapping()` comme source unique. Deriver `parameterMap` et `reverseMap` dynamiquement. |
| Lignes supprimees | ~30 |
| Risque regression | **Faible** — les mappings sont identiques pour les GPIOs concernes |

### H10 — OutputService utilise `Database::getConnection()` et `TableConfig` directement

| Propriete | Valeur |
|-----------|--------|
| Fichier | `src/Service/OutputService.php:173, 226-227` |
| Type | Mauvaise pratique (connexion BDD hors du constructeur) |
| Description | `updateStateById()` et `updateMultipleParameters()` appellent `Database::getConnection()` et `TableConfig::getOutputsTable()` directement, alors que le service a deja `OutputRepository` injecte. |
| Simplification | Deleguer les requetes SQL au `OutputRepository` (y ajouter les methodes manquantes). |
| Lignes modifiees | ~40 |
| Risque regression | **Moyen** — logique SQL a verifier apres migration vers repository |

### H11 — Middleware auth inline dans `index.php` (80+ lignes)

| Propriete | Valeur |
|-----------|--------|
| Fichier | `public/index.php:158-272` |
| Type | Complexite structurelle |
| Description | Deux closures longues (`$applyAuth` et le middleware global) avec logique session/token/both dupliquee. Calcul de basePath duplique (lignes 58-79 et 253-259). |
| Simplification | Extraire dans une classe `AuthGuardMiddleware` qui encapsule la logique session/token/both. |
| Lignes deplacees | ~80 |
| Risque regression | **Eleve** — touche toutes les routes (publiques et protegees), tout changement d'auth impacte l'ensemble |

### H12 — FFP3 OutputController : 5 methodes toggle identiques

| Propriete | Valeur |
|-----------|--------|
| Fichier | `src/Controller/Ffp3/OutputController.php:136-159` |
| Type | Duplication triviale |
| Description | `toggleOutput()`, `toggleOutputTest()`, `toggleOutputTest3()`, `toggleOutputS3()`, `toggleOutputS3Test()` appellent toutes `$this->handleToggle()`. L'environnement est deja defini par `EnvironmentMiddleware`. |
| Simplification | Remplacer par une seule methode `toggleOutput()` et mettre a jour les routes dans `routes_ffp3.php`. |
| Lignes supprimees | ~16 |
| Risque regression | **Faible** — le middleware fixe deja l'environnement, les methodes sont des pass-through |

---

## 2. Mapping tests existants / manquants par hotspot

| Hotspot | Tests existants | Tests manquants avant refactor |
|---------|----------------|-------------------------------|
| H01 | `SensorReadRepositoryTest` (cousin) | Test unitaire `getFirmwareVersion()` dans `AbstractSensorRepository` (mock PDO) |
| H02 | `RoutesConfigSecurityTest` (auth indirecte) | Test unitaire `requireAuth()` avec AuthService mocke |
| H03 | Aucun | Test unitaire `getDefaultParams()` (retour correct par module) |
| H04 | Aucun | Test integration `updateParameters()` : mailNotif normalization, WakeUp normalization, param inconnu → 400 |
| H05 | Aucun | Test integration `handleOutputCreate()` : batch update avec params valides/incomplets |
| H06 | Aucun | Test integration `setOutput()` : action=set gpio, action=set name (MSP), action=output_create, action inconnue |
| H07 | Aucun | Test unitaire `getParametersForBoard()`, `updateParameterByName()`, `batchUpdateParameters()` avec mock PDO |
| H08 | `SignatureValidatorTest` (CRC voisin) | Test `HeartbeatController::handle()` : POST valide, CRC mismatch, champs manquants, methode GET rejetee |
| H09 | `OutputCacheServiceTest` (cache) | Test `OutputService::getParametersMap()` et `updateMultipleParameters()` |
| H10 | `OutputCacheServiceTest` | Test `OutputService::updateStateById()` via repository mocke |
| H11 | `RoutesConfigSecurityTest`, `AuthControllerRedirectTest` | Test integration middleware auth : session valide, token valide, session expiree, chemin public, chemin protege |
| H12 | Aucun | Test integration `toggleOutput()` unique avec environnements differents |

**Tests existants a executer systematiquement avant chaque vague** :
- `AbstractPostDataControllerTest` — contrat firmware
- `RoutesConfigSecurityTest` — securite routes
- `AssetWhitelistCoherenceTest` — coherence assets
- `TwigPartialsCoherenceTest` — coherence templates
- `SignatureValidatorTest` — HMAC
- `OutputCacheServiceTest` — cache outputs

---

## 3. Priorisation en 3 vagues

### Vague 1 — Quick wins (risque faible, effort < 30 min chacun)

| # | Hotspot | Action | Lignes gagnees | Effort |
|---|---------|--------|----------------|--------|
| 1 | H01 | Deplacer `getFirmwareVersion()` dans `AbstractSensorRepository` | ~14 | 10 min |
| 2 | H02 | Injecter `AuthService` dans `AbstractOutputController` + MAJ `dependencies.php` | ~10 | 15 min |
| 3 | H03 | Factoriser `getDefaultParams()` via methode abstraite | ~15 | 15 min |
| 4 | H12 | Fusionner 5 toggle en 1 + MAJ routes | ~16 | 15 min |
| 5 | H09 | Centraliser `parameterMap` via `OutputSyncService` | ~30 | 20 min |

**Total vague 1** : ~85 lignes supprimees, ~75 min.

### Vague 2 — Refactors moyens (risque faible a moyen, effort 30-60 min)

| # | Hotspot | Action | Lignes gagnees | Effort |
|---|---------|--------|----------------|--------|
| 6 | H04 | Factoriser `updateParameters()` dans `AbstractOutputController` | ~40 | 30 min |
| 7 | H05 | Factoriser `handleOutputCreate()` dans `AbstractOutputController` | ~25 | 20 min |
| 8 | H06 | Factoriser `setOutput()` avec `doSetOutput()` abstrait | ~30 | 40 min |
| 9 | H07 | Deplacer logique parametres dans `AbstractOutputRepository` | ~60 | 45 min |
| 10 | H08 | Injecter PDO dans `HeartbeatController` | ~10 | 20 min |

**Total vague 2** : ~165 lignes supprimees, ~2h35.

### Vague 3 — Refactors sensibles (risque moyen a eleve, effort > 1h)

| # | Hotspot | Action | Lignes gagnees | Effort |
|---|---------|--------|----------------|--------|
| 11 | H10 | Deleguer SQL d'`OutputService` au repository | ~40 | 1h |
| 12 | H11 | Extraire `AuthGuardMiddleware` depuis `index.php` | ~80 | 1h30 |

**Total vague 3** : ~120 lignes supprimees, ~2h30.

**Grand total** : ~370 lignes backend supprimees ou factorisees, ~5h40 d'effort.

---

## 4. Verrous « zero changement front-end »

### Fichiers strictement exclus de toute modification

```
serveur/templates/**/*.twig
serveur/public/assets/**/*
serveur/public/service-worker.js
serveur/public/manifest.json
```

### Criteres d'acceptation par refactor

1. **Aucun fichier Twig modifie** — les variables passees aux templates doivent garder exactement les memes noms et structures.
2. **Aucun fichier CSS/JS modifie** — les chemins d'assets, classes CSS, IDs HTML restent inchanges.
3. **Memes URLs de routes** — aucune route ne change de chemin, methode HTTP, ni de codes de reponse.
4. **Meme structure JSON des reponses API** — les cles JSON retournees aux firmwares et au front JS restent identiques.
5. **Memes codes HTTP** — 200, 400, 401, 405, 500 retournes aux memes conditions.

### Verification automatisee

Apres chaque refactor, les tests suivants doivent passer :
- `AssetWhitelistCoherenceTest` : verifie que les assets JS/CSS references existent
- `TwigPartialsCoherenceTest` : verifie que les partials Twig references existent
- `RoutesConfigSecurityTest` : verifie que les routes protegees ne sont pas devenues publiques

---

## 5. Protocole de validation backend local

### Avant chaque vague

```bash
cd serveur
composer install --dev
./vendor/bin/phpunit
```

Tous les 17 tests existants doivent passer (vert).

### Apres chaque refactor unitaire

```bash
# 1. Tests automatises
./vendor/bin/phpunit

# 2. Serveur local (si modif de routes ou middleware)
php -S localhost:8080 -t public

# 3. Verifications manuelles critiques
# - GET http://localhost:8080/ping → 200 "OK"
# - GET http://localhost:8080/aquaponie → 200 (page HTML)
# - GET http://localhost:8080/meteo → 200 (page HTML)
# - GET http://localhost:8080/serre → 200 (page HTML)
# - GET http://localhost:8080/api/outputs/state → 200 JSON
# - GET http://localhost:8080/msp1/api/outputs/state → 200 JSON
# - GET http://localhost:8080/n3pp/api/outputs/state → 200 JSON
# - GET http://localhost:8080/dashboard → 302 (redirect login si non auth)
```

### Checklist de non-regression par vague

**Vague 1** (H01, H02, H03, H12, H09) :
- [ ] `phpunit` vert
- [ ] Page meteo-control charge sans erreur
- [ ] Page serre-control charge sans erreur
- [ ] `/api/outputs/state` retourne JSON valide
- [ ] Toggle FFP3 fonctionne (un seul endpoint)

**Vague 2** (H04-H08) :
- [ ] `phpunit` vert
- [ ] `updateParameters` MSP1 et N3PP retournent `{"success":true}`
- [ ] `setOutput` MSP1 et N3PP retournent `{"success":true}`
- [ ] `handleOutputCreate` MSP1 et N3PP retournent `{"success":true}`
- [ ] POST `/heartbeat-test` avec CRC valide retourne 200
- [ ] POST `/heartbeat-test` avec CRC invalide retourne 400

**Vague 3** (H10-H11) :
- [ ] `phpunit` vert
- [ ] Toutes les routes protegees redirigent vers /login sans session
- [ ] Toutes les routes publiques restent accessibles sans auth
- [ ] Toggle FFP3 via OutputService fonctionne
- [ ] updateMultipleParameters via OutputService fonctionne

---

## 6. Plan d'execution detaille

### Phase preparatoire (avant toute modification de code)

1. Executer `composer test` — s'assurer que les 17 tests passent.
2. Creer une branche : `git checkout -b refactor/simplification-backend`.

### Vague 1 — Quick wins

**Etape 1.1 — H01 : getFirmwareVersion dans AbstractSensorRepository**
- Ajouter `getFirmwareVersion(): string` dans `AbstractSensorRepository` (utilise `$this->getTableName()`)
- Supprimer la methode de `MspSensorRepository` et `N3ppSensorRepository`
- Executer `phpunit`

**Etape 1.2 — H02 : Injection AuthService**
- Ajouter `AuthService $authService` au constructeur de `AbstractOutputController`
- Modifier `requireAuth()` pour utiliser `$this->authService`
- Mettre a jour `MspOutputController` et `N3ppOutputController` (constructeur + parent::__construct)
- Mettre a jour `dependencies.php` (ajouter AuthService aux constructeurs)
- Executer `phpunit`

**Etape 1.3 — H03 : getDefaultParams factorisee**
- Ajouter `abstract protected function getDefaultParamKeys(): array` dans `AbstractOutputController`
- Implementer `getDefaultParams()` dans la classe abstraite : genere la map a partir des cles
- Implementer `getDefaultParamKeys()` dans `MspOutputController` et `N3ppOutputController`
- Supprimer les methodes `getDefaultParams()` privees des sous-classes
- Executer `phpunit`

**Etape 1.4 — H12 : Fusion des toggles FFP3**
- Supprimer `toggleOutputTest`, `toggleOutputTest3`, `toggleOutputS3`, `toggleOutputS3Test` de `OutputController`
- Rendre `toggleOutput` public et seul point d'entree
- Mettre a jour `routes_ffp3.php` : remplacer les noms de methodes par `'toggleOutput'` partout
- Supprimer le nom `toggle_method` de `$ffp3RoutesConfig` (ou le garder constant)
- Executer `phpunit`

**Etape 1.5 — H09 : Centraliser parameterMap dans OutputService**
- Remplacer les deux `$parameterMap` hardcodes par `OutputSyncService::getGpioMapping()` filtre
- Supprimer les constantes locales dupliquees
- Executer `phpunit`

### Vague 2 — Refactors moyens

**Etape 2.1 — H04 : updateParameters dans AbstractOutputController**
- Ajouter `abstract protected function getOutputRepository()` (retourne le repo type)
- Deplacer `updateParameters()` de `MspOutputController` et `N3ppOutputController` vers `AbstractOutputController`
- Supprimer les methodes des sous-classes
- Executer `phpunit` + test manuel updateParameters MSP1/N3PP

**Etape 2.2 — H05 : handleOutputCreate dans AbstractOutputController**
- Deplacer dans `AbstractOutputController` en reutilisant `getDefaultParamKeys()`
- Supprimer des sous-classes
- Executer `phpunit`

**Etape 2.3 — H06 : setOutput dans AbstractOutputController**
- Ajouter `abstract protected function doSetOutput(array $params, int $board): array`
- Deplacer la structure commune (auth, dispatch output_create, state normalization) dans `AbstractOutputController::setOutput()`
- Implementer `doSetOutput()` dans chaque sous-classe (MSP : gpio+name, N3PP : gpio seul)
- Executer `phpunit` + test manuel setOutput MSP1/N3PP

**Etape 2.4 — H07 : Parametres dans AbstractOutputRepository**
- Ajouter `abstract protected function getParamGpioMap(): array` dans `AbstractOutputRepository`
- Deplacer `getParametersForBoard()`, `updateParameterByName()`, `batchUpdateParameters()` dans `AbstractOutputRepository`
- Supprimer des sous-classes, garder `PARAM_GPIO_MAP` comme retour de `getParamGpioMap()`
- Executer `phpunit`

**Etape 2.5 — H08 : Injection PDO dans HeartbeatController**
- Ajouter `PDO $pdo` au constructeur de `HeartbeatController`
- Mettre a jour `dependencies.php`
- Remplacer `Database::getConnection()` par `$this->pdo`
- Executer `phpunit`

### Vague 3 — Refactors sensibles

**Etape 3.1 — H10 : OutputService delegation au repository**
- Ajouter `updateStateByIdForWeb(int $id, int $state): bool` et `updateParametersByGpio(array $params): int` dans `OutputRepository`
- Remplacer les appels directs a `Database::getConnection()` et `TableConfig` dans `OutputService` par des appels au repository
- Executer `phpunit` + test complet toggle + parameters FFP3

**Etape 3.2 — H11 : Extraction AuthGuardMiddleware**
- Creer `src/Middleware/AuthGuardMiddleware.php` avec la logique session/token/both
- Injecter `AuthService`, `AuthMiddleware`, `TokenAuthMiddleware`, et la config routes
- Remplacer les 80+ lignes inline de `index.php` par `$app->add($container->get(AuthGuardMiddleware::class))`
- Mettre a jour `dependencies.php`
- Executer `phpunit` + test complet : routes publiques accessibles, routes protegees redirigent, token auth fonctionne

### Finalisation

1. Executer `composer test` — tous les tests verts
2. Verification manuelle : pages aquaponie, meteo, serre, dashboard, controle
3. Commit : `[serveur] simplification backend — factorisation controllers/repositories/services`
4. Publication via `publish-cycle.ps1`
