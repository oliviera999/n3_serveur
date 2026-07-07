# Architecture d'arbitrage des e-mails — serveur primaire / ESP en relais

> **Statut** : audit + plan de conception (cible). Le socle « seuils pilotés en BDD »
> (PR supervision 6.15.0) est déjà mergé ; ce document définit la suite.
> **Portée** : écosystème n³ — firmwares `ffp5cs` (aquaponie), `n3pp` (serre/élevage),
> `msp` (station météo), `uploadphotosserver` (CAM) et le serveur **FFP3 Datas** (`n3_serveur`).
> Familles supervisées côté serveur : **FFP3, N3PP, MSP1** (tables + heartbeat + résolveur hors-ligne).

## 1. Objectif

Aujourd'hui les alertes e-mail sont émises **en double et sans coordination** : le firmware
(SMTP direct via Gmail) **et** le serveur (CRON → `NotificationService`) envoient chacun leurs
mails, avec des recouvrements (niveau aquarium bas, réserve). Pire, le firmware **perd des
alertes** hors ligne (cf. §3). 

**Cible :** le **serveur est la base d'envoi de TOUS les mails dérivables des données**. Le
firmware **ne prend le relais que s'il constate que le serveur n'a pas reçu ses données**
(bascule de secours), et **sans se congestionner** quand il est hors ligne.

Deux invariants :
1. **Un seul émetteur à la fois** pour une alerte donnée (fin des doublons).
2. **Aucune perte de couverture** : quand l'ESP se tait, le serveur émet ; quand le serveur
   ne reçoit rien, soit l'ESP émet (s'il a Internet), soit le serveur émet l'alerte
   « appareil silencieux » (filet de sécurité).

## 2. État des lieux (audit)

### 2.1 Qui envoie quoi aujourd'hui

| Alerte | Firmware (SMTP direct) | Serveur (CRON) | Donnée source reçue au POST |
|---|---|---|---|
| Niveau aquarium bas | ffp5cs | ✅ (doublon) | `EauAquarium` (mm) + `aqThreshold` |
| Trop-plein / débordement | ffp5cs | ❌ | `EauAquarium` + `limFlood` ✅ |
| Réserve basse | ffp5cs | ✅ opt-in (doublon) | `EauReserve` + `tankThreshold` |
| Problème de marées | ❌ | ✅ | `EauAquarium` (stddev) |
| Chauffage ON/OFF | ffp5cs | ❌ | `TempEau` + `etatHeat` + `chauffageThreshold` ✅ |
| Nourrissage fait/manqué/plafond | ffp5cs | ❌ | compteurs `bouffePetits/Gros` + créneaux ✅ |
| Sol sec | n3pp | ❌ | `HumidMoy` + `SeuilSec` ✅ |
| Batterie faible (P1) | n3pp, msp | ❌ | **n3pp/msp** : `PontDiv` + `SeuilPontDiv` ✅ · **ffp5cs** : absent ❌ |
| Appareil hors-ligne / silencieux | ❌ | ✅ | heartbeat / cadence données (les 3 familles) |
| Boot / OTA / veille-réveil / rapport réseau (P4) | ffp5cs, n3pp, msp | partiel (OTA piloté serveur) | `bootCount`, état OTA ; rapport réseau = diagnostic intrinsèque |
| CAM : boot, bascule jour/nuit, échec OTA | uploadphotosserver | ❌ | POST version/heartbeat |
| CAM : récap transfert galerie | ❌ (déjà délégué) | ✅ `sendGalleryTransferReport` | session sync start/finish |

> **MSP (station météo)** n'a que **2 mails** : batterie faible (P1) et rapport réseau (P4, 6 h).
> Aucune alerte sur les capteurs météo (temp/humidité/pluie/luminosité) aujourd'hui — mais tous
> ces champs sont au POST, donc de futures alertes météo seraient calculables côté serveur.

### 2.2 Comportement hors-ligne du firmware — **fragile, pertes réelles**

| Aspect | ffp5cs (light sleep) | n3pp (deep sleep) | uploadphotosserver (deep sleep) |
|---|---|---|---|
| Queue mail | FreeRTOS **6 slots**, RAM | aucune (one-shot) | aucune (one-shot) |
| Débordement | **drop du nouveau** | n/a | n/a |
| Retry SMTP | ≤2 + backoff 60 s | **0** | **0** |
| Persistance | RAM (perdue au reboot) | flags RTC | flags RTC (bascule jour/nuit) |
| **Flag « envoyé » posé sur échec ?** | **Oui** (enqueue/inconditionnel) | **Oui** (inconditionnel, RTC) | first-boot non ; OTA-fail oui (perdu) |
| Cooldown persisté ? | écrit NVS **jamais relu** | RTC seulement | n/a |
| Détection offline | `WiFi.status()` + DNS | envoi aveugle sur wake | `WiFi.status()` + GET 200 |
| Signal « serveur joignable » | **`AutomatismSync::isServerOk()`** ✅ | ❌ (aucun) | GET 200 / POST version OK (proxy) |
| Switch mail distant | GPIO **101** `mailNotif` (mode gradué) | GPIO **101** (idem) | clé **103** (bool binaire) |
| Taxonomie sévérité | `N3Severity`/`N3NotifMode` (P1-P4) | idem | **non** (bool custom) |

> **MSP (deep sleep)** = profil **quasi identique à n3pp** : `n3_mail` one-shot, **aucune queue**,
> **0 retry**, flags anti-spam en **RTC** (`s_mspBatteryMailSent`), taxonomie `N3Severity/N3NotifMode`,
> switch **GPIO 101** `mailNotif`, `FreqWakeUp` **GPIO 107**, **pas d'`isServerOk`**, et **même bug de
> latching** (`s_mspBatteryMailSent = true` posé inconditionnellement après un envoi non vérifié —
> `msp/src/msp_automation.cpp:203-204`).

**Bug central à corriger :** le flag anti-spam (« déjà alerté ») est posé au **moment de
l'enqueue** (ffp5cs) ou **inconditionnellement** (n3pp/msp/ffp5cs alertes niveau, uploadphotosserver
OTA), **pas à la livraison SMTP confirmée**. Un envoi qui échoue hors ligne est donc considéré
« fait » : l'alerte est **perdue** jusqu'à ce que la grandeur repasse l'hystérésis. Références :
`ffp5cs/include/automatism/level_alert_orchestrator.h:38-43`,
`ffp5cs/include/automatism/flood_orchestrator.h:44-49`,
`n3pp/src/n3pp_automation.cpp:137-146` (`emailHumidSent` en `RTC_DATA_ATTR`),
`msp/src/msp_automation.cpp:198-205` (batterie, `s_mspBatteryMailSent`).

### 2.3 Rouages déjà en place (réutilisables)

- **Reachability ESP→serveur** : `ffp5cs` `AutomatismSync::isServerOk()` (vrai après GET config
  OK, faux sinon). C'est le signal exact du basculement.
- **Kill-switch mail distant appliqué à l'envoi** : GPIO 101 `mailNotif` → plafond `N3NotifMode`
  vérifié dans `Mailer::sendSync()` (ffp5cs) et `sendEmailNotification()` (n3pp). Mettre
  `101=none` fait **déjà** taire l'ESP.
- **Flag serveur→ESP one-shot dans le JSON du GET** : précédent `triggerOtaCheck`
  (`OutputCacheService::maybeAttachAndConsumeOtaTrigger`) — patron pour tout signal descendant.
- **Serveur déjà émetteur** de : aquarium bas, marées, offline, réserve (`CronOrchestrator`,
  `SystemHealthService`, `DeviceHealthService`, `NotificationService` avec throttle + digest).
- **Seuils déjà pilotés en BDD** (6.15.0) : `aqThreshold` (102), `tankThreshold` (103),
  `tideStddev` (129), `reserveLowThresholdMm` (130), facteur nuit (126-128).

## 3. Architecture cible

### 3.1 Principe

```
              POST données (1×/cycle)                 GET config (1×/cycle)
   ESP  ───────────────────────────────▶  SERVEUR  ◀───────────────────────────  ESP
        ◀── ack texte ──                  (cron 1 min)   ── JSON config + flags ──▶

   Le SERVEUR calcule et envoie TOUTES les alertes dérivables des données reçues.
   L'ESP n'envoie QUE si isServerOk()==false (le serveur n'a pas eu ses données),
   et alors uniquement le strict nécessaire (anti-congestion, §3.4).
```

- **CRON à 1 min** (au lieu de 5) : latence serveur ≤ 1 min, acceptable même pour le trop-plein.
  Le serveur devient primaire sur **tout**, y compris les alertes urgentes ; l'ESP n'est plus
  primaire sur rien.
- **L'arbitrage est décidé LOCALEMENT par l'ESP** (le côté qui sait s'il est isolé), jamais
  par un switch poussé depuis le serveur — sinon un ESP qui repasse offline resterait muet
  avec un `101=none` périmé (piège du « double silence »).

### 3.2 Matrice de propriété cible (par alerte)

| Alerte | Émetteur primaire | Relais (failover ESP) | Notes |
|---|---|---|---|
| Aquarium bas, réserve, marées | **Serveur** (déjà) | ffp5cs si `!isServerOk()` | fin des doublons |
| Trop-plein, chauffage, nourrissage | **Serveur** (à implémenter) | ffp5cs si `!isServerOk()` | données présentes au POST |
| Sol sec | **Serveur** (à implémenter) | n3pp si POST échoué | `HumidMoy`/`SeuilSec` présents |
| Batterie faible **n3pp / msp** | **Serveur** (à implémenter) | n3pp/msp si POST échoué | `PontDiv`+`SeuilPontDiv` **présents au POST** ✅ |
| Batterie faible **ffp5cs** | **ESP** (critique) | — | pas de champ batterie au POST → §Phase 4 |
| Boot / redémarrage | **Serveur** (via `bootCount`) | — | dérivable ; diag ESP supprimé |
| Rapport réseau (P4) ffp5cs/n3pp/msp | **ESP** (diagnostic intrinsèque) | — | RSSI/heap/uptime non fidèlement reconstituables ; candidat à réduire/supprimer |
| Appareil hors-ligne | **Serveur** (déjà, 6.15.0) | — | filet de sécurité de tout le reste, les 3 familles |
| Crash / panic | **ESP** (critique) | — | intrinsèque à l'appareil |
| CAM boot / jour-nuit / OTA-fail | **Serveur** si dérivable, sinon ESP critique | uploadphotosserver | adopter taxonomie |
| CAM récap galerie | **Serveur** (déjà) | — | aucune duplication |

### 3.3 Mécanisme de failover par firmware

- **ffp5cs** — déjà outillé. À chaque évaluation d'alerte :
  `if (isServerOk() && lastPostRecentEnough()) → NE PAS envoyer` (le serveur s'en charge) ;
  `else → failover` (§3.4). `isServerOk()` existe (`automatism_sync.h:56`) ; ajouter une
  fraîcheur (`getLastSendMs()` < N cycles).
- **n3pp** — **net-new** : pas de `isServerOk`. Il POST sur wake ; capturer le **succès du POST
  de ce wake** (code retour HTTP) dans un flag RTC `postOkThisWake`. Si OK → suppression des
  alertes partagées ; sinon → failover. Deep sleep : le flag est recalculé chaque wake.
- **msp** — **net-new, identique à n3pp** : pas d'`isServerOk` (le compteur d'échec GET
  `s_outputsGetFailureCount` n'est pas `RTC_DATA_ATTR` → inutilisable entre cycles). Ajouter un flag
  RTC `postOkThisWake` sur le code retour du POST (`n3DataPost` dont le résultat est aujourd'hui
  ignoré, `msp_network.cpp:149`). Seule alerte partagée à arbitrer : **batterie** (le rapport réseau
  P4 reste ESP, cf. §3.4-2 il est supprimé en failover de toute façon).
- **uploadphotosserver** — proxy « serveur OK » = GET config 200 **et** POST version OK. Adopter
  le même gate pour ses 4 diagnostics ; migrer d'abord ceux dérivables côté serveur (boot via
  version POST, OTA via le pilotage OTA serveur).

### 3.4 Anti-congestion de l'ESP hors ligne (exigence forte)

Quand le failover est actif (`!isServerOk()`), l'ESP **ne doit pas se saturer** :

1. **Distinguer « app serveur down » vs « pas d'Internet ».** Le SMTP passe par Gmail, pas par
   l'app serveur. Donc :
   - `!isServerOk()` **mais** WiFi+DNS OK → l'ESP **peut** livrer par SMTP → il envoie (relais utile).
   - Pas d'Internet du tout (WiFi down / DNS KO) → **ne rien tenter** (aucun SMTP ne passera) ;
     c'est l'alerte « appareil silencieux » du serveur qui couvre. Évite le martèlement TLS.
   Concrètement : en failover, tenter SMTP **seulement si** `WiFi.status()==WL_CONNECTED` et
   résolution DNS OK (ffp5cs a déjà `waitForNetworkReadyForSMTP()`).
2. **Filtrer par sévérité** : en failover, n'émettre que **P1/P2** (Critique/Alerte) ;
   supprimer P3/P4 (confirmations, diagnostics, rapports réseau) — ils ne valent pas le coût TLS.
3. **Plafonner** : budget de failover borné (ex. ≤ K mails par fenêtre offline), au-dessus du
   simple cap de queue (6, drop-new). Éviter tout backlog qui viderait d'un coup au retour.
4. **Non bloquant** : garder le pompage séquentiel + backoff 60 s existant + feeds watchdog ;
   ne jamais laisser l'envoi affamer la task automation (heap guard 32 KB déjà présent).
5. **Corriger le latching** (Phase 0) : sinon un envoi failover qui échoue latche « envoyé » et
   l'alerte est perdue au lieu d'être retentée au cycle suivant.

## 4. Plan par phases

> Ordonnancement clé : **la couverture serveur d'une alerte doit précéder la mise en veille de
> l'ESP sur cette alerte** — sinon trou de couverture. On monte donc le serveur d'abord, puis on
> bascule l'ESP.

### Phase 0 — Fiabilité (firmware, tous : ffp5cs, n3pp, msp, uploadphotosserver) — *indépendant, prioritaire*
- Latcher le flag « envoyé » sur la **livraison SMTP confirmée**, pas sur l'enqueue ni
  inconditionnellement (`ffp5cs` level/flood orchestrators ; `n3pp` `automatismes()` ;
  `msp` `sommeil()` batterie `msp_automation.cpp:203-204` ; `uploadphotosserver` OTA-fail).
  → `sendEmailNotification()` (n3pp/msp) doit **renvoyer un booléen de succès** au lieu de `void`.
- Relire les cooldowns anti-spam en NVS au boot (ex. `lastFloodEmailEpoch` : écrit mais jamais
  relu) pour ne pas re-spammer / perdre après reboot.
- Bénéfice immédiat même avant l'arbitrage : plus de pertes d'alertes hors ligne.

### Phase 1 — Serveur primaire + CRON 1 min (serveur)
- Passer la crontab à **1 min** (`docs/deployment/CRON.md`) ; déplacer aquarium bas / marées /
  réserve dans le bucket **fréquent** (chaque minute).
- ⚠️ **Rendre les délais tick-based → time-based** : `RestartPumpCommand` suppose « +5 min via
  prochain CRON » ; à 1 min il faut un délai horodaté (`requestTime`), pas « prochain tick ».
- Vérifier `flock` (runs qui se chevauchent à 1 min → skip propre) et l'anti-spam
  (`AlertThrottler`) recalibré pour une cadence 1 min.

### Phase 2 — Migration serveur des alertes ESP-only dérivables (serveur)
Nouvelles tâches CRON dans `CronOrchestrator` (ou services dédiés), sur données déjà reçues :
- **Trop-plein** : `EauAquarium < limFlood(114)` + debounce/cooldown (porter la machine
  `FloodAlert` du firmware côté serveur).
- **Chauffage ON/OFF** : `TempEau` vs `chauffageThreshold(104)` ± hystérésis + `etatHeat`.
- **Nourrissage fait/manqué/plafond** : compteurs `bouffePetits/Gros(108/109)` + créneaux
  `bouffeMatin/Midi/Soir(105-107)` (le serveur détient déjà les compteurs).
- **Sol sec (n3pp)** : `HumidMoy` vs `SeuilSec(102 n3pp)` + hystérésis +5 %.
- **Batterie (n3pp + msp)** : `PontDiv` vs `SeuilPontDiv` (les deux au POST) + hystérésis de
  ré-armement. (ffp5cs n'a pas de champ batterie → Phase 4.)
- **Boot / redémarrage** : détecter l'incrément de `bootCount` (n3pp/msp) / nouvelle session.
- Réutiliser `NotificationService` (throttle, digest, catégories, destinataire, mm/cm).
- À l'issue : le serveur couvre **toutes** les alertes partagées des 3 familles capteurs.

### Phase 3 — Bascule ESP en relais + anti-congestion (firmware, tous)
- **ffp5cs** : gate `isServerOk() && postFrais` → suppression des alertes partagées ; sinon
  failover §3.4 (P1/P2 only, SMTP seulement si Internet, budget borné).
- **n3pp** : ajouter `postOkThisWake` (RTC) ; même gate.
- **msp** : idem n3pp — ajouter `postOkThisWake` (RTC, sur le résultat de `n3DataPost`
  aujourd'hui ignoré) ; arbitrer la batterie ; le rapport réseau P4 est de toute façon supprimé
  en failover (§3.4-2).
- **uploadphotosserver** : adopter `N3Severity/N3NotifMode` (aujourd'hui bool clé 103), gate sur
  proxy « serveur OK », failover critique-only.
- Garder le kill-switch manuel GPIO 101 (override).

### Phase 4 — Alertes non dérivables (firmware + serveur)
- **Batterie ffp5cs uniquement** : soit ajouter le champ tension/`%` au POST ffp5cs (→ serveur
  primaire, comme n3pp/msp), soit la laisser **ESP critique-only** (P1, hors gate failover car non
  couverte serveur). *(n3pp/msp sont déjà couverts en Phase 2.)*
- **Crash / panic** : rester **ESP critique-only**.
- Documenter explicitement la courte liste d'alertes qui restent légitimement côté ESP.

## 5. Gains attendus (globalité)

| Axe | Gain |
|---|---|
| **Fiabilité livraison** | `NotificationService` (SMTP `symfony/mailer`, throttle, digest, repli `mail()`, log) vs queue RAM lossy + bug de latching. Le primaire serveur **supprime les pertes** pour tout ce qui migre. |
| **Fin des doublons** | Un seul émetteur arbitré ; plus de double mail aquarium/réserve. |
| **Ressources ESP** | SMTP embarqué = TLS + bloc heap 32 KB + mutex + watchdog + blocage task. En temps normal l'ESP n'envoie plus rien → moins de heap/CPU, moins de risque watchdog, **moins de temps éveillé = moins de conso** (surtout ffp5cs light-sleep). |
| **Latence** | CRON 1 min → alertes serveur ≤ 1 min, y compris trop-plein. |
| **Pilotage** | Seuils + verbosité déjà en BDD (6.15.0) ; tuning sans reflash. Politique unifiée (catégories, digest) toutes familles. |
| **Couverture pannes** | Failover ESP (si Internet) + alerte « silencieux » serveur (sinon) = aucun angle mort. |
| **Observabilité** | Historisation serveur (`notification_log`, Monolog) là où l'ESP perd tout au reboot. |

Bilan : passage d'un système **« deux émetteurs non coordonnés, l'un fiable, l'autre lossy et
redondant »** à **« un émetteur fiable unique + un relais ciblé et borné »**. Gain principal =
**fiabilité** (fin des pertes) + **allègement embarqué** (ESP déchargé du SMTP en régime normal).

## 6. Risques / vigilance

- **Délais tick-based** (`RestartPumpCommand`) cassés par le passage 1 min → passer en horodaté.
- **Fenêtre de transition** online↔offline : hystérésis de N cycles côté ESP + `AlertThrottler`
  côté serveur pour éviter un double envoi sur ~1 cycle.
- **Ordonnancement** : ne jamais activer la suppression ESP d'une alerte avant que le serveur ne
  la calcule (respecter l'ordre des phases par classe d'alerte).
- **Charge SMTP serveur à 1 min** : throttle/digest à recalibrer ; surveiller les quotas Gmail.
- **n3pp / msp / uploadphotosserver** : pas d'`isServerOk` natif → coût net-new (flag POST par wake).
- **Latching (Phase 0)** à corriger avant toute bascule, sinon le failover perd des alertes.

## 7. Références code

**Serveur** : `src/Command/CronOrchestrator.php`, `src/Service/SystemHealthService.php`,
`src/Service/DeviceHealthService.php`, `src/Service/OfflineThresholdResolver.php`,
`src/Service/NotificationService.php`, `src/Controller/Ffp3/PostDataController.php`,
`src/Controller/Ffp3/OutputController.php` (GET état + `triggerOtaCheck`),
`src/Config/Ffp3GpioMap.php`, `docs/deployment/CRON.md`.

**Firmware** : `ffp5cs/src/mailer*.cpp`, `ffp5cs/src/automatism/automatism_display.cpp`,
`ffp5cs/include/automatism/{level_alert_orchestrator,flood_orchestrator}.h`,
`ffp5cs/src/automatism/automatism_sync.{h,cpp}` (`isServerOk`),
`n3pp/src/n3pp_automation.cpp`, `n3pp/src/n3pp_network.cpp`,
`msp/src/msp_automation.cpp` (batterie P1 + rapport réseau P4), `msp/src/msp_network.cpp`
(POST/GET, `n3DataPost` résultat ignoré), `uploadphotosserver/src/{main,camera_remote,camera_sync}.cpp`,
`shared/n3_mail/src/n3_notify.h` (taxonomie `N3Severity`/`N3NotifMode`).
