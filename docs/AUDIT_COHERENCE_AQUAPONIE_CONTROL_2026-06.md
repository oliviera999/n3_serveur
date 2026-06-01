# Audit de cohérence — page de contrôle aquaponie

**Date** : 2026-06-01  
**Périmètre** : `/aquaponie-control` (prod), `/aquaponie-control-test` (test WROOM)  
**Référence** : plan d’audit juin 2026, [`ENDPOINTS_ESP32_SERVEUR.md`](ENDPOINTS_ESP32_SERVEUR.md), [`VARIABLE_NAMING.md`](../../firmwires/ffp5cs/docs/technical/VARIABLE_NAMING.md)

---

## Résumé exécutif

| Zone | Verdict | Commentaire |
|------|---------|-------------|
| Routes / auth | OK | URLs actuelles, 301 depuis `/control*`, auth sur page + POST, GET state public |
| Mapping GPIO / paramètres | OK | `OutputRepository::PARAMETER_GPIO_MAP` aligné sur `control.twig` et GET state |
| GPIO 117 (forçage pompe) | OK (serveur) | Documenté extension serveur ; absent firmware `ALL_MAPPINGS` (volontaire) |
| GPIO 18 (pompe réserve) | OK après clarification | UI/firmware : 1=ON ; `PumpService` = legacy relais actif-bas uniquement |
| Texte UI « sync » | Corrigé | Ancien « &lt; 4 s » remplacé par délai réaliste 6–10 s |
| Documentation historique | Corrigé | `AUDIT_PAGE_CONTROL_DISTANT`, `SYNCHRONISATION_BIDIRECTIONNELLE`, `AUTHENTICATION` |
| Tests automatisés | Ajout | `tests/Repository/OutputRepositoryTest.php` (3 tests, 51 assertions) |
| Validation Docker | OK | Pages, API toggle/parameters/OTA, GET state ; PHPUnit dans conteneur |

---

## 1. Matrice interface ↔ GPIO ↔ contrat

### Actionneurs affichés (`control.twig`)

| GPIO | Libellé UI | Firmware `gpio_mapping.h` | GET state | POST sync (`OutputSyncService`) |
|------|------------|---------------------------|-----------|--------------------------------|
| 16 | Pompe aquarium | `PUMP_AQUA` | Oui | `etatPompeAqua` (+ forçage si 117 actif) |
| 18 | Pompe réserve | `PUMP_TANK` | Oui | `etatPompeTank` (1=ON) |
| 2 | Radiateur | `HEATER` | Oui | `etatHeat` |
| 15 | Lumière | `LIGHT` | Oui | `etatUV` |
| 101 | Notifications email | `EMAIL_EN` (`mailNotif`) | Oui | Non sync POST (config via GET) |
| 108 / 109 | Nourrissage petits/gros | `FEED_SMALL` / `FEED_BIG` | Oui | Flags ack |
| 110 | Reset ESP | `RESET_CMD` | Oui | `resetMode` (priorité web 20 s) |
| 115 | Forçage réveil | `WAKEUP` | Oui | Config GET |
| 117 | Forcer pompe aqua ON | **Serveur uniquement** | Oui | Force GPIO 16 à 1 si actif |

### Paramètres numériques / email (`parameter_gpio_map`)

| Clé UI (`params.*`) | GPIO | Clé POST firmware | Statut |
|---------------------|------|-------------------|--------|
| `mail` | 100 | `mail` | OK |
| `aqThr` | 102 | `aqThreshold` | OK (mapping `OutputService`) |
| `taThr` | 103 | `tankThreshold` | OK |
| `chauff` | 104 | `chauffageThreshold` | OK |
| `bouffeMatin/Midi/Soir` | 105–107 | idem | OK |
| `tempsGros/Petits` | 111–112 | idem | OK |
| `tempsRemplissageSec` | 113 | idem | OK |
| `limFlood` | 114 | idem | OK |
| `FreqWakeUp` | 116 | idem | OK |

`mailNotif` est dans `PARAMETER_GPIO_MAP` (101) mais piloté par le **switch** GPIO 101, pas un champ `params.mailNotif` — cohérent.

---

## 2. Matrice routes ↔ comportement ↔ doc

| Élément | Prod | Test WROOM |
|---------|------|------------|
| Page HTML | `GET /aquaponie-control` | `GET /aquaponie-control-test` |
| API JS (`CONTROL_API_BASE`) | `/api/outputs` | `/api/outputs-test` |
| Toggle | `POST /api/outputs/toggle` | `POST /api/outputs-test/toggle` |
| Paramètres | `POST /api/outputs/parameters` | `POST /api/outputs-test/parameters` |
| OTA | `POST /api/outputs/trigger-ota-check` | `POST /api/outputs-test/trigger-ota-check` |
| Poll état | `GET /api/outputs/state?fresh=1` (public) | `GET /api/outputs-test/state?fresh=1` |
| Table BDD | `ffp3Outputs` | `ffp3Outputs2` |
| Redirection legacy | `/control` → `/aquaponie-control` (301) | `/control-test` → `/aquaponie-control-test` |

**Auth** : pages et POST protégés (`registerFfp3ProtectedRoutes` + `protected_paths`). **Exception** : GET `.../state` listé dans `public_paths` pour l’ESP32.

---

## 3. Matrice UI ↔ JavaScript ↔ promesses

| Affirmation / comportement | Code | Verdict |
|----------------------------|------|---------|
| Sync ESP32 | `pollInterval` 10 s prod, 6 s test ; firmware ~6 s GET | Texte UI corrigé (juin 2026) |
| Badge « TEMPS RÉEL » | Polling + `useFresh: true` | OK (lecture BDD à jour, pas WebSocket) |
| Confirm reset / wake | `control-actions.js` GPIO 110, 115 | OK |
| OTA | `POST .../trigger-ota-check` ; GET firmware consomme flag | OK (doc ENDPOINTS) |
| Témoins `data-indicator` | `dataStates` dans réponse GET | OK (ignorés par ESP32) |
| CRON 5 min | `run-cron.php` | OK (lien `/public/cronlog.txt`) |

---

## 4. Validation exécutée

### PHPUnit (local)

```
tests/Repository/OutputRepositoryTest.php — OK (3 tests, 51 assertions)
```

### Docker / smoke HTTP (2026-06-01)

Stack locale : `.\tools\local-docker.ps1 -Action up` → `http://127.0.0.1:8082` opérationnel.

| Test | Résultat |
|------|----------|
| `GET /aquaponie-control?token=…` | 200 — `CONTROL_API_BASE`, champs `data-parameter`, texte sync |
| `GET /aquaponie-control-test?token=…` | 200 — idem |
| `GET /aquaponie-control` sans auth | 302 → login |
| `GET /api/outputs/state?fresh=1` | 200 — GPIO 117 présent |
| `GET /api/outputs-test/state?fresh=1` | 200 — GPIO 117 présent |
| `POST /api/outputs-test/parameters` (`aqThr=19`) | 200 `{ "updated": 1 }` |
| `POST /api/outputs-test/toggle` (gpio 16) | 200 ; GET state → `16=1` |
| `POST /api/outputs-test/trigger-ota-check` | 200 `{ "ok": true }` |
| Smoke `local-smoke-test.ps1 -AuthMode token` | OK pages + token + GET state (échec non lié : route `/pgl/…` → 500) |
| Smoke `-AuthMode both -AdminPassword localadmin` | OK token + session `/aquaponie-control` + checks négatifs auth |
| Scénario GPIO 117 (forçage pompe) | OK — OFF pompe 16 bloqué tant que 117=1 |
| PHPUnit dans conteneur `app` | `OutputRepositoryTest` — OK (3 tests) |

Checklist manuelle (complétée 2026-06-01) :

- [x] **GPIO 117** : forçage ON → `POST toggle` gpio 16 `state=0` renvoie `state: 1` en JSON et BDD `16=1` ; après désactivation forçage, OFF gpio 16 → `16=0`
- [x] **Smoke `-AuthMode both -AdminPassword localadmin`** : token + session sur `/aquaponie-control` OK ; refus sans auth / token invalide OK (mot de passe Docker local : `localadmin`, voir `.env.docker.example`)

---

## Écarts traités par cette livraison

| Écart | Action |
|-------|--------|
| Doc « GET state inverse GPIO 18 » | Corrigé dans `SYNCHRONISATION_BIDIRECTIONNELLE.md` |
| Doc « pas d’auth » / URLs `/control` | Encadré 2026-06 dans `AUDIT_PAGE_CONTROL_DISTANT.md` |
| `AUTHENTICATION.md` trop vague sur `/api/outputs/*` | GET state documenté comme public |
| Texte « &lt; 4 secondes » | `control.twig` |
| Chemins serveur dans `gpio_mapping.h` | Commentaires mis à jour |
| GPIO 117 non documenté firmware | `VARIABLE_NAMING.md` + commentaire header |
| Absence tests cohérence mapping | `OutputRepositoryTest.php` |

## Écarts non traités (backlog)

- Badges SYNC/EN ATTENTE décrits dans `SYNCHRONISATION_BIDIRECTIONNELLE.md` vs implémentation réelle du badge `#control-sync-badge` — à harmoniser si refonte UX.
- Centralisation unique du mapping côté serveur (objectif déjà noté dans `VARIABLE_NAMING.md`).
- Tests d’intégration HTTP Docker pour toggle/parameters (hors PHPUnit unitaire).

---

## Checklist non-régression

- [ ] PHPUnit : `vendor/bin/phpunit tests/Repository/OutputRepositoryTest.php`
- [x] Smoke Docker avec auth token sur `/aquaponie-control` et `-test` (2026-06-01)
- [ ] Vérifier footer version serveur après déploiement
- [ ] Pilote ESP32 : page test ↔ `GET /api/outputs-test/state` (même table)

---

## Fichiers modifiés (juin 2026)

- `serveur/templates/control.twig`
- `serveur/docs/AUTHENTICATION.md`, `AUDIT_PAGE_CONTROL_DISTANT.md`, `SYNCHRONISATION_BIDIRECTIONNELLE.md`, `ENDPOINTS_ESP32_SERVEUR.md`
- `serveur/src/Service/OutputSyncService.php`, `PumpService.php`
- `serveur/tests/Repository/OutputRepositoryTest.php`
- `firmwires/ffp5cs/include/gpio_mapping.h`, `firmwires/ffp5cs/docs/technical/VARIABLE_NAMING.md`
