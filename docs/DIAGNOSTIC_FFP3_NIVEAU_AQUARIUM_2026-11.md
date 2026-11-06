# Diagnostic — FFP3 : niveau d'eau aquarium figé puis absent (30/10 → 06/11/2026)

> Instantané d'analyse (lecture seule). **Aucune action sur la prod** : pas d'écriture BDD,
> pas de changement de sorties, pas de lancement manuel du CRON.
> Heures en **Europe/Paris** (`APP_TIMEZONE`, confirmé par les réponses MCP `timezone`).

## Sources lues

| Source | Version / état |
|---|---|
| Serveur `n3_serveur` | `master` @ 8cae49c (v6.54.5) |
| Firmware `ffp5cs` **15.31** | `oliviera999/n3_firmwires` @ 799a8bc, dossier `ffp5cs/` (`include/config_system.h:13` → `"15.31"`) |
| Données prod | connecteur MCP `iot_olution` : `iot_series`, `iot_stats`, `iot_latest`, `iot_alerts`, `iot_grep_files` (cronlog applicatif + journal web `logs-public`) |

⚠️ Le dépôt `oliviera999/ffp5cs` est **gelé en v12.27** (dernier commit 06/03/2026) et ne
correspond pas au firmware en service. La source 15.31 vit dans `n3_firmwires/ffp5cs`.

## Chronologie établie

| Quand | Fait | Preuve |
|---|---|---|
| 30/10 ~08:00 | Boot de l'ESP32 (reboot n° 104) | heartbeat 31/10 21:03 : `uptime=133402 s, reboots=104` |
| 30/10 → 12:45 | EauAquarium varie (9,3–10,8 cm en moyenne 15 min), diffMaree ≠ 0 | `iot_series` 15 min |
| 30/10 13:15 | EauAquarium **figé à 81 mm**, diffMaree = 0, tideNoiseMm = 20 | `iot_series` |
| 30/10 17:55 | 1ʳᵉ coupure pompe : `Problème de marée détecté (stddev: 0)` | `cronlog-2026-10-30.txt:3331` |
| 30/10 17:55 → 01/11 07:30 | **196 ordres d'arrêt pompe** aquarium (un toutes les 10–15 min), **101 e-mails** « [FFP3][P1] Problème de marées » (17:55 → 07:20) | `iot_grep_files`, `iot_alerts` |
| 31/10 ~22:46 | **Reboot de l'ESP32** (n° 105) pendant une veille | heartbeat 22:51:31 : `uptime=302 s, reboots=105` |
| 31/10 22:46 → aujourd'hui | EauAquarium **NULL** (champ absent du POST) | `iot_series`, `iot_stats` 01/11→06/11 : 1 146 mesures, `EauAquarium` dans `empty_fields` |
| 01/11 07:40 | Dernier redémarrage pompe ; ensuite **silence total** | cronlog 07:35/07:40 |
| 06/11 08:51 | Toujours NULL, aucune alerte en cours | `iot_latest`, `iot_alerts` |

Rythme des POST : par rafales d'environ 10 envois espacés de 30 s, puis environ 1 h 40 sans
données (veille). `iot_stats` donne 9,5 mesures/h. Les 60 dernières mesures couvrent donc
**environ 9 à 10 h**.

## Verdicts sur les 6 constats

### 1. Jusqu'au 30/10 ~13h, variation normale (9–12,6 cm, marées visibles) — **confirmé** (amplitude à nuancer)
Moyennes 15 min : 9,3 → 10,8 cm, diffMaree de −4 à +7 mm. Le 12,6 cm n'apparaît pas dans les
moyennes 15 min (c'est probablement un maximum ponctuel) ; non contradictoire.

### 2. Du 30/10 13h15 au 31/10 ~21h, figé à 81 mm et diffMaree = 0 — **confirmé**
Dernière valeur 81 mm à 31/10 21:07 (fin de rafale) ; rafale suivante à 22:46 déjà NULL.
diffMaree = 0 est la conséquence directe d'un historique firmware constant
(`ffp5cs/src/system_sensors.cpp:345-366`, différence « valeur il y a 15 s − valeur actuelle »).

### 3. Depuis le 31/10 ~22h45, NULL : le firmware omet le champ et le serveur insère NULL — **confirmé**
- Firmware : `WaterLevelFallbackPolicy::USE_FALLBACK_AQUA = false` (`ffp5cs/include/config_sensors.h:38-42`).
  Si la lecture est invalide, `resolveOrZero()` renvoie 0, puis `formatPostValue(0)` produit une
  chaîne vide (`shared/n3_analog_sensors/src/n3_sensor_fallback.h`). Le champ est alors omis
  (`ffp5cs/src/automatism/automatism_sync.cpp:286`).
- Serveur : `$toFloat` renvoie `null` si la clé est absente ou non numérique
  (`src/Controller/AbstractPostDataController.php:236-239`) ; `eauAquarium: $toFloat('EauAquarium')`
  (`src/Controller/Ffp3/PostDataController.php:386`) ; liaison `:eauAquarium => null`
  (`src/Repository/SensorRepository.php:74`). La ligne est insérée avec NULL, le reste est enregistré normalement.
- Autre cause écartée : le nettoyage CRON met EauAquarium à NULL hors de [40 ; 700] mm
  (`src/Service/SensorDataService.php:84-87`, `:189`), mais aucune ligne « Nettoyage … colonne
  EauAquarium » n'apparaît dans les cronlogs du 30/10 au 03/11.

### 4. checkTideSystem a pris l'écart-type nul pour une panne de marées — **confirmé, mais l'ampleur est sous-estimée**
- Mécanisme : `stddevOnLastReadings('EauAquarium')`, soit le STDDEV des 60 dernières lignes
  (`src/Service/SensorStatisticsService.php:170-199`). Une valeur figée donne 0, ce qui est sous le
  seuil (GPIO 129 / `TIDE_STDDEV_THRESHOLD`, défaut 1.0). Le CRON appelle alors `stopPompeAqua()`,
  pose le drapeau de redémarrage et envoie `notifyMareesProblem()`
  (`src/Command/CronOrchestrator.php:604-628`).
- Ce n'est **pas** « ~40 alertes à partir du 31/10 16h15 » mais :
  - **101 e-mails du 30/10 17h55 au 01/11 07h20** (critique, anti-spam 15 min, `src/Notification/Severity.php:77`) ;
  - **196 coupures de la pompe aquarium** (une toutes les 10–15 min pendant environ 37 h).
- Ce n'est **pas** « deux coupures » : le serveur coupait 5 à 10 min sur chaque cycle de 10 à 15 min.
  Les deux longues plages où `etatPompeAqua = 0` (31/10 ~10h45–13h15 et 31/10 22h45 → 01/11 ~02h–04h)
  correspondent à l'**état remonté par le firmware**. Celui-ci ne relit les sorties qu'à ses réveils :
  une coupure de 5 min côté serveur peut donc durer toute une veille côté ESP (amplification
  plausible, l'état physique réel n'est pas vérifiable à distance).
- Le délai de 5 min devient 10 min quand le CRON passe 1 s trop tôt (`Redémarrage programmé dans
  1 secondes`, cronlog 01/11 07:35). En effet, **le CRON prod tourne toutes les 5 min** (horodatages
  `--- Début orchestrateur CRON ---` à :00/:05/:10…), alors que `docs/deployment/CRON.md:34` et
  le docblock de `CronOrchestrator` supposent 1 min.

### 5. Depuis le 01/11 07h20, plus d'alerte : stddev sur des NULL renvoie NULL et le contrôle se tait — **confirmé (angle mort réel)**
- MySQL `STDDEV()` ignore les NULL : s'il reste au moins une valeur non nulle, le résultat est 0
  (population) ; si tout est NULL, le résultat est **NULL**. `CronOrchestrator.php:619` traite
  `$stddev === null` comme « rien à signaler » et sort.
- Cela explique aussi la **poursuite** des coupures pendant environ 9 h après le passage à NULL :
  tant que la fenêtre de 60 lignes contenait encore des 81 mm, STDDEV(81, NULL, …) valait 0. La
  60ᵉ ligne NULL est arrivée vers 07h20-07h30 (67 mesures entre 22h45 et 07h30).
- Aucune autre supervision ne prend le relais : `iot_alerts` est vide depuis le 01/11 07:21,
  alors que le capteur est muet depuis plus de 5 jours.

### 6. Remplissage auto (aqThreshold 15 cm) et trop-plein (limFlood 7 cm) aveugles — **confirmé (serveur et firmware)**
- Serveur : `checkLowWaterLevel()` sort sur `null` (`CronOrchestrator.php:576`) ; trop-plein dérivé
  idem (`src/Service/DerivedAlert/Ffp3DerivedAlertService.php:169-172`).
- Firmware : `RefillStart::evaluate` et `RefillOverfill::evaluate` renvoient `None` si
  `!wlAquaKnown` (`ffp5cs/include/automatism/refill_start.h:38`, `include/automatism/refill_overfill.h:38`).
  Le remplissage auto et le verrou trop-plein sont donc **inactifs**.
- Aggravant : **pendant la phase figée**, les automatismes croyaient le niveau connu (81 mm, entre
  limFlood 70 mm et aqThreshold 150 mm) et ne pouvaient donc rien déclencher. Ils étaient aussi
  aveugles, sans le savoir.
- À vérifier côté firmware : `FloodAlert::evaluate` (`include/automatism/flood_alert.h:51-53`) teste
  `wlAquaMm < limFloodMm` **sans garde « niveau connu »**. Avec `wlAqua = 0`, l'ESP pourrait émettre un
  faux e-mail « trop plein » si son mailer de secours est actif.

## Ce qui produit une valeur figée à 81 mm (firmware 15.31)

Chaîne de lecture aquarium (`ffp5cs/src/system_sensors.cpp:99-136`) :
`_usAqua.readReactiveFiltered()`, puis, si invalide, `readFiltered(3)`, puis la politique de repli.

1. `readReactiveFiltered()` (`src/sensor_ultrasonic.cpp:260-395`) fait 5 tirs HC-SR04. Un écho n'est
   gardé que si 20 mm ≤ d < 4000 mm (`:333`). Les échos manqués (timeout 30 ms) sont rejetés.
2. **S'il ne reste aucun écho valide**, la fonction **ne renvoie pas 0** mais
   `_lastValidDistance` (`:362-365`), même si son log dit « retourne 0 ». Après 10 échecs consécutifs,
   le capteur passe « désactivé » (`SensorFailureManager(…, 10, 60000, 3)`, `:32`) et renvoie encore
   `_lastValidDistance` (`:285`, `:306`), avec un test de réactivation périodique.
3. `system_sensors` juge cette valeur **valide** (`isWaterLevelKnown` : `0 < wl ≤ 5000`,
   `include/config_sensors.h:192-194`). Elle est donc postée, la « récupération » `readFiltered(3)`
   n'est jamais tentée, et `USE_FALLBACK_AQUA=false` n'entre jamais en jeu.
4. `_lastValidDistance` vit **en RAM** : initialisé à 0 au constructeur (`:30`), remis à 0 seulement
   par `resetHistory()`, qui n'est jamais appelé pour les ultrasons. **Au reboot**, il repart à 0 : la
   même absence d'écho donne alors `0` → `readFiltered(3)` → `0` → champ omis → NULL.

**Lecture cohérente avec les données** : vers 13h10 le 30/10, le capteur aquarium a cessé de
recevoir tout écho valide. Le firmware a re-posté la dernière médiane valide (81 mm) pendant 33 h,
jusqu'au reboot n° 105 du 31/10 ~22h46, puis a posté « absent ». Il n'y a ni fallback (152 mm), ni
écho minimal (< 20 mm rejeté), ni valeur du nettoyage serveur.

## Cause la plus probable

**Matérielle** : perte totale d'écho du HC-SR04 aquarium depuis le 30/10 ~13h10, toujours en cours.
Les pistes sont, par ordre de probabilité : capteur décalé ou masqué (objet, condensation, éclaboussure),
câble ou connecteur TRIG/ECHO (fil unique `Pins::ULTRASON_AQUA`), capteur HS. Le 81 mm, plus court que les ~93-108 mm de la matinée du 30/10,
peut être le **dernier écho parasite** juste avant la perte (objet ou main
proche) : non vérifiable à distance.

**Logicielle, deux défauts qui ont transformé la panne en incident** :
1. Firmware : le « dernier bon » masque silencieusement une panne durable (valeur figée présentée
   comme mesure réelle).
2. Serveur : la valeur figée a été prise pour une panne de marées, avec coupure de la pompe en boucle.
   Puis la valeur absente a fait taire toute la supervision.

À faire sur place : inspecter le capteur aquarium. Un reboot seul ne corrigera rien (la donnée est
déjà « absente » depuis le dernier reboot).

## Correctifs proposés

> **Suivi (06/11/2026)** — implémentés : serveur 1 à 5 en **6.56.0** (alerte capteur absent / figé,
> garde `TideCutGuard` + disjoncteur, NULL traité comme défaut capteur, règle santé), outils MCP en
> 6.55.0 ; firmware 7, 9, 10 en **ffp5cs 15.32** (n3_firmwires PR #120, OTA non déployé). Restent : 6
> (crontab prod à passer à 1 min — action hébergeur, `docs/deployment/CRON.md`) et 8 (état capteur au
> POST : changement de contrat). Le capteur lui-même reste à inspecter sur place.

### Serveur (`n3_serveur`)
1. **Alerte « capteur aquarium absent »** : si les K dernières lignes (ou les N dernières minutes de
   lignes **reçues**, l'appareil étant en ligne) ont `EauAquarium IS NULL`, envoyer une alerte `Alert`
   (`ffp3:sensor-missing:EauAquarium`) avec une machine ouverture/rétablissement (patron
   `AvailabilityNotifier`). Par défaut : N = 60 min de lignes reçues, au moins 5 lignes. Ne pas
   déclencher si l'appareil est hors ligne (couvert par `DeviceHealthService`).
2. **Alerte « capteur aquarium figé »** : `COUNT(DISTINCT EauAquarium) = 1` sur les K dernières lignes
   non nulles couvrant au moins N h. Les vraies marées font varier la mesure de plusieurs mm par cycle.
   Seuil par défaut : 2 h.
3. **checkTideSystem : ne plus couper la pompe sur une donnée invalide**.
   - `stddevOnLastReadings` doit renvoyer aussi `COUNT(col)` et `COUNT(DISTINCT col)`. Si la
     couverture non nulle est inférieure à 80 %, ou si une seule valeur distincte est observée, on a
     un **défaut capteur**, pas un défaut marée : pas de `stopPompeAqua()`, et l'alerte 1 ou 2 à la place.
   - Ne couper que si la variance est faible **et** que les données sont variées mais plates (par
     exemple plusieurs valeurs distinctes dans moins de 1 mm), ou mieux avec `diffMaree` / `tideEvent`
     cohérents.
   - Disjoncteur : au plus M coupures par 24 h (par exemple 3). Au-delà, alerte « boucle de coupure
     marée suspendue », la pompe reste ON.
4. **NULL ≠ OK** : traiter explicitement `stddev === null` (fenêtre sans aucune valeur) comme
   « capteur absent » et non comme un retour silencieux (`CronOrchestrator.php:619`).
   `checkLowWaterLevel` et le trop-plein dérivé ont le même défaut et doivent alimenter l'alerte 1.
5. **Rapport de santé MCP** : ajouter une règle orange « champ critique NULL / figé » (EauAquarium,
   EauReserve, TempEau) à `HealthRules`.
6. **Cadence CRON** : aligner `docs/deployment/CRON.md` et le docblock de `CronOrchestrator` sur la
   cadence réelle (5 min), ou corriger la crontab (1 min). `RestartPumpCommand::RESTART_DELAY = 300`
   sur un CRON de 5 min donne en pratique 5 ou 10 min.

### Firmware (`n3_firmwires/ffp5cs`)
7. `readReactiveFiltered()` / état désactivé : **renvoyer 0** (inconnu) au lieu de `_lastValidDistance`
   quand aucun écho n'est valide, ou ne garder le « dernier bon » que pendant une durée bornée (par
   exemple 2 lectures ou 60 s). Sinon `USE_FALLBACK_AQUA=false` est contourné.
8. Exposer l'état capteur au POST (par exemple `wlAquaStatus=ok|stale|absent` ou un compteur d'échecs)
   pour que le serveur distingue « mesure », « dernier bon » et « absent ».
9. `FloodAlert::evaluate` : ajouter la garde `isWaterLevelKnown` (faux trop-plein possible sur `wlAqua=0`).
10. Corriger le log trompeur « … retourne 0 » (`sensor_ultrasonic.cpp:363`), qui renvoie en fait le dernier bon.

## Ce qui a manqué côté MCP pour ce diagnostic à distance

| Manque | Impact | Remède possible |
|---|---|---|
| `src/` illisible | Impossible de lire CronOrchestrator, SensorStatisticsService, SensorDataService, PostDataController : les verdicts 3, 4 et 5 restaient des hypothèses | Racine `src` (déjà possible **sans code** via `.env` : `MCP_FILE_ROOTS=defaults,src`) |
| Source firmware absente du serveur | Mécanisme de la valeur figée introuvable à distance | Hors périmètre (pas d'accès hors dépôt) : lire `n3_firmwires` sur GitHub |
| « Dernière valeur non nulle / depuis quand NULL / depuis quand figé » | Obtenu en recoupant plusieurs appels `iot_series` ; `iot_latest` **omet** silencieusement les champs vides | Faisable avec l'existant ; amélioration légère possible (voir PR) |
| Reboots ESP | Trouvés par `iot_grep_files` (« Heartbeat reçu ») dans `logs-public`, pas via un outil de données | Faisable avec l'existant |
| `templates/` | Non nécessaire pour ce diagnostic | — |
