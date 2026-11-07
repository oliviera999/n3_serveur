# Diagnostic — FFP3 WROOM : perte de contact du 06/11/2026 (16:40)

> Analyse en **lecture seule** (étape 0 du chantier « pertes de contact diagnosticables »).
> **Aucune action sur la prod** : pas d'écriture BDD, pas de changement de sorties, pas de lancement
> manuel du CRON, aucune publication OTA. Heures en **Europe/Paris** (`APP_TIMEZONE`, = heure de
> Casablanca en novembre). Rédigé le 07/11/2026.

## Sources lues

| Source | Détail |
|---|---|
| Serveur `n3_serveur` | `master` @ `1a7ce93` (v6.58.0 + commit de publication OTA 15.33) |
| Firmware `ffp5cs` **15.31** (en service) et **15.33** (publiée) | `oliviera999/n3_firmwires` @ `668d517`, dossier `ffp5cs/` (`include/config_system.h` → `VERSION = "15.33"` sur master ; la 15.31 est le dernier binaire que le module a exécuté) |
| Données prod | MCP `iot_olution` : `iot_sql_select` sur `ffp3Data`, `ffp3Heartbeat`, `notification_log` ; `iot_grep_files` sur `logs-public/cronlog-2026-11-06.txt` (journal **web** : POST, heartbeats, uploads caméra) et `logs/cronlog-2026-11-06.txt` (journal **CRON**) ; `logs/error_log` ; `iot_alerts`, `iot_cron_status` |
| Déploiement | `git log` du dépôt serveur (commit `1a7ce93` « ota: publish ffp5-wroom-prod=15.33… ») — le serveur fait `git pull` **chaque minute** (`docs/deployment/CRON.md`) |

⚠️ Le dépôt `oliviera999/ffp5cs` est gelé en v12.27 : la source en service vit dans `n3_firmwires/ffp5cs`.

## Chronologie reconstituée

Le module dort `FreqWakeUp = 2000 s` (33 min) puis émet une **rafale** de ~8 à 10 POST espacés de
30 s, un ou deux heartbeats, puis se rendort. Rafales du 06/11 : 06:51, 07:26, 08:05, 08:44, 09:24,
10:04, 10:43, 11:24, 12:02, 12:41, 13:21, 14:00, 14:40, 15:19, 15:59, **16:37**.

| Quand (Paris) | Fait | Preuve |
|---|---|---|
| 01/11 ~22:46 → 06/11 | Le module tourne depuis le reboot n° 105 (uptime 506 249 s = 5,86 j au dernier heartbeat) : **aucun crash sur la période**, heap stable (54–55 Ko libres, min 25 100). | `ffp3Heartbeat` id 154801–154850 |
| 06/11, toute la journée | **À chaque réveil, la première requête signée est rejetée** `401 X-Sig invalide` : `ts_received` est en avance de **+2 045 s** sur l'heure serveur (ex. 16:37:52 : `ts_received=1793981517` vs serveur `1793979472`), soit **un cycle de veille entier**. Parfois **+4 090 s** (deux cycles, ex. 15:59:01 `1793981231`). Les POST **acceptés** le sont **sans en-tête X-Sig** (« Aucune signature fournie – authentification api_key requise ») : ce sont les rejeux de la file firmware (`post_id` horodatés avant le réveil), pas des mesures fraîchement signées. | `logs-public/cronlog-2026-11-06.txt` l. 1654–1669 ; 62 rejets « X-Sig invalide » sur la journée (POST + heartbeat) |
| 06/11 00:55 → 23:50, chaque heure | **Bug CRON** : `DeviceHealthService: lecture heartbeat impossible … Unknown column 'reading_time'` sur `ffp3Heartbeat` (colonne réelle : `timestamp`). La supervision heartbeat FFP3 est **aveugle** (fail-safe silencieux) ; seule la supervision « flux de données » (`SystemHealthService`) reste active. | `logs/cronlog-2026-11-06.txt` l. 178, 377, … 4813 ; `SHOW COLUMNS FROM ffp3Heartbeat` |
| 16:01:42 | **Publication OTA 15.33** sur le manifeste partagé `ota/metadata.json` (canaux prod **et** test, WROOM **et** S3, 1 575 872 octets pour `esp32-wroom`) — commit `1a7ce93` ; mis en ligne par le `git pull` de la minute suivante (≈ 16:02). Aucune publication intermédiaire 15.32 : le module passe de 15.31 à 15.33. | `git log -- ota/metadata.json` ; `ota/metadata.json` |
| 15:59:30 → 16:04:43 | Rafale précédente (15.31). La vérification OTA post-réveil (3 s après le mail de réveil, c.-à-d. ~16:02–16:03) a pu voir le manifeste 15.31 **ou** 15.33 selon la seconde exacte du `git pull` : indécidable, les GET `/ota/metadata.json` n'étaient pas journalisés. Le module s'est rendormi normalement (prochaine rafale à 16:37). | `ffp3Data` id 1063514–1063523 |
| 16:37:52 | Réveil. POST signé **rejeté** (+2 045 s). | journal web l. 1654–1656 |
| 16:38:20 | POST rejoué (api_key) **accepté** → dernière… avant-dernière ligne `ffp3Data`. | id 1063524 |
| 16:38:50 | POST signé **rejeté** (+2 045 s). | l. 1662–1664 |
| **16:39:18** | **Dernier POST accepté** (rejeu api_key, `post_id esp32-wroom-1793981518-1717`). | id 1063525 |
| 16:39:58 | Heartbeat signé **rejeté** (+2 045 s). | l. 1671 |
| **16:40:27** | **Dernier contact** : heartbeat accepté par le chemin CRC seul (sans X-Sig), `uptime=506249, reboots=105`. | id 154850 ; `var/last_device_ip_prod.txt` modifié 16:40:27 |
| ~16:40:30 → 16:41 (déduit) | Fin de la séquence de réveil : mail de réveil, **3 s**, puis `AppTasks::netRequestOtaCheck()` → GET `/ota/metadata.json` → version distante 15.33 > 15.31 → téléchargement 1,5 Mo en HTTP → flash → `ESP.restart()`. **Rien de tout cela n'est journalisé côté serveur.** | `automatism_sleep.cpp` l. 480–492 ; `OtaFileController` (aucun log) |
| 16:36 → 22:00 et après | La **caméra ffp3 du même site** continue d'envoyer ses photos (16:36:40, 16:47:15, 16:57:49…) : **réseau du site et serveur sains**. msp1 aussi. | journal web l. 1652, 1677, 1685 |
| 16:40:27 → aujourd'hui | **Plus aucune requête du module, même rejetée** (aucun `PostData`/`Heartbeat` dans le journal web après 16:40:27, ni le 07/11). | `iot_grep_files` 06/11 et 07/11 |
| 18:35:04 | E-mail `[FFP3][P1] Système hors ligne` (flux de données > seuil). | `notification_log` 552 ; cronlog l. 3719 |
| **19:40:03** | **Faux rétablissement** `[FFP3][P2] Réception des données rétablie` : à 19:00 le régime **nuit** triple la veille (2000 × 3 = 6000 s) et le seuil passe à 6000 × 3 + 60 = 18 060 s (5 h) ; l'âge du dernier POST (3 h) repasse **sous** le seuil → `reportOnline()` → clôture, **sans aucune donnée reçue**. | cronlog l. 3951–3953 ; `OfflineThresholdResolver` (NIGHT_EXTRA_CYCLES) ; `AvailabilityNotifier::reportOnline()` |
| 21:45:03 | L'âge dépasse de nouveau le seuil → second `Système hors ligne`. | `notification_log` 554 |
| 07/11 10:26 | Toujours hors ligne (`ffp3:offline` depuis 21:45:01) ; CRON à l'heure (passage 10:25:02, 7,6 s). | `iot_alerts`, `iot_cron_status` |

## Réponses aux questions de l'étape 0

### Le firmware 15.31 interroge-t-il `/ffp3/ota/metadata.json` à chaque réveil, et quand ?

**Oui.** `AutomatismSleep::handleAutoSleep()` (`ffp5cs/src/automatism/automatism_sleep.cpp` l. 480–492)
enchaîne après chaque sortie de veille légère : POST de réveil → fetch config → mail de réveil →
attente `OTA_CHECK_DELAY_AFTER_WAKE_MS = 3000` → `AppTasks::netRequestOtaCheck()`. La tâche OTA
(`app_tasks_ota.cpp`) exécute alors `runOtaCheckCycle("Trigger")` si le heap contigu ≥ 28 Ko,
hors fenêtre de protection post-réveil (`isInWakeProtectionWindow`), sinon le report au cycle
périodique (2 h). URL : `ServerConfig::BASE_URL + OTA_BASE_PATH + metadata.json` (câblage
historique → manifeste racine partagé, `ota_model.h`). Un check a aussi lieu au boot
(`onWifiReady` → `checkForOtaUpdateInternal`, puis 3 reprises 1/3/10 min si heap insuffisant).

Conséquence : **le module a nécessairement vu la 15.33 au réveil de 16:37** (mise en ligne ≈ 16:02),
sauf report heap. Le dernier contact (16:40:27) tombe exactement à l'instant attendu du check OTA.

### Le rollback ESP-IDF est-il activé dans `ffp5cs` ?

**Oui, côté bootloader et application** : `CONFIG_BOOTLOADER_APP_ROLLBACK_ENABLE=y` et
`CONFIG_APP_ROLLBACK_ENABLE=y` dans `sdkconfig.defaults` et `sdkconfig.wroom-prod`. Le code
(`system_boot.cpp`, v14.17) arme une probation au boot d'une image `ESP_OTA_IMG_PENDING_VERIFY`
et la valide (`esp_ota_mark_app_valid_cancel_rollback`) **après 120 s d'uptime** (`tickOtaValidation`)
**ou avant tout reboot délibéré** (`confirmOtaValidation`). Limites :

- 120 s d'uptime ne prouvent **ni le Wi-Fi, ni l'heure, ni un POST accepté** : une 15.33 qui boote,
  tourne 2 min sans réseau puis se fige est **validée** et ne reviendra jamais en 15.31 ;
- un reboot délibéré (reset distant, reboot quotidien 0h–4h) **valide** l'image avant de redémarrer ;
- le rollback ne couvre que « l'image ne démarre pas » (panic/WDT avant 120 s). Dans ce cas précis
  le bootloader aurait relancé la 15.31, qui aurait POSTé — **ce que l'on n'observe pas**. Donc : soit
  la 15.33 a démarré et a été validée (puis s'est tue), soit le flash/reboot n'a jamais eu lieu
  (brown-out pendant l'écriture flash, coupure), soit le module n'a rien téléchargé et s'est tu pour
  une autre raison.

### Que change la 15.33 dans la signature (bloc suffixe signé `wlAquaDiag`) ?

La 15.33 ajoute `wlAquaDiag=<etat>,<timeouts>,<rejets>,<mm>` et `wlPotaDiag=…` dans le **bloc
suffixe du corps signé**, entre `tideExtremeMm` et `configSynced` (`ffp3_post_body.cpp`
`kSuffixKeys` ↔ `Ffp3HmacPostBody::SUFFIX_KEYS`, serveur 6.57.0). La signature HMAC porte sur le
**corps brut** : tant que le serveur lit `php://input` (`body_source=raw_middleware`, c'est le cas
en prod), l'ordre des clés n'affecte pas la vérification. Le contrat est aligné des deux côtés ;
**la 15.33 n'introduit pas de rejet de signature nouveau**. Les rejets observés le 06/11 viennent
de l'**horloge** (+2 045 s > `SIG_VALID_WINDOW = 300 s`), pas du corps : `nonce_len=16`,
`body_len=591` identiques aux requêtes acceptées.

### D'où vient l'avance d'horloge d'un cycle de veille ?

`PowerManager::getSleptTime()` (`power.cpp` l. 789–812) **ajoute la durée mesurée de la veille à
l'horloge système** (`settimeofday(now + sleptSec)`) après `esp_light_sleep_start()`. Or ESP-IDF
**maintient l'horloge système pendant le light sleep** (timer RTC) : la durée est comptée **deux
fois**. À chaque réveil le module avance de `FreqWakeUp` (2 000 s ≈ 33 min) ; NTP ne resynchronise
qu'au boot et toutes les heures (`NTP_SYNC_INTERVAL_MS`), c'est-à-dire **après** la première requête
signée de la rafale, d'où le motif « 1 à 3 rejets en début de rafale, puis accepté ». Les décalages
observés (+2 045 s, +4 090 s) sont exactement 1 et 2 cycles. C'est le « double comptage probable »
du cahier des charges, **confirmé**.

## Verdict le plus probable

**OTA 15.33 déclenchée au réveil de 16:37 et image non revenue en ligne** — probabilité estimée
**60–70 %**. Pour : publication 38 min avant, check OTA systématique à chaque réveil, dernier contact
à l'instant exact du check, module stable depuis 6 jours (pas de crash, heap sain), réseau du site et
serveur prouvés sains par la caméra, **silence total** incompatible avec un simple rejet de signature
(un module vivant aurait continué d'émettre des requêtes, même rejetées).

Alternatives : **coupure d'alimentation du module** (15–20 % — la caméra peut être sur une autre
alimentation ; un brown-out pendant l'écriture flash entre aussi ici) ; **gel / boucle silencieuse
sans réseau de la 15.33** après validation à 120 s (compris dans « OTA ») ; **crash matériel
indépendant** (< 10 %, aucun signe avant-coureur). **Exclus** : réseau du site, défaut serveur (photos
reçues, CRON à l'heure, aucune 5xx), signature seule.

## Ce qui manquait pour conclure

1. **Aucun journal des téléchargements OTA** (`metadata.json` et `.bin`) : impossible de savoir si
   et quand le module a récupéré la 15.33, ni si le transfert était complet.
2. **Heure de publication non enregistrée** côté serveur (seul le commit Git la porte).
3. **Aucun rapport du firmware** sur ses événements OTA (début, progression, fin, échec, rollback,
   validation) ni sur la **raison de son dernier boot** / la partition en cours.
4. **Rejets HMAC non structurés** : seulement des lignes de log texte, pas de décalage d'horloge
   exploitable ni d'alerte quand il dépasse la fenêtre.
5. **Supervision heartbeat FFP3 cassée** (`reading_time`) et **rétablissement sur simple seuil**
   variable avec l'heure.
6. **Aucune preuve d'alimentation** : pas de mesure de tension, pas de watchdog externe.

Tout cela est traité par les deux PR associées (serveur 6.59.0, firmware ffp5cs 15.34 + libs
`shared/`) ; la procédure « en cas de perte de contact » est dans `docs/PROCEDURE_PERTE_CONTACT.md`.

## Annexe — erreurs annexes constatées

- **Cache Twig** : `Failed to write cache file …/var/cache/twig/…` → 500 sur `/` et `/serre`
  (error_log, juin → novembre 2026). Cause hébergeur : les fichiers de `var/cache/twig` et
  `var/cache/di` sont créés tour à tour par le compte du CRON et par le worker PHP du web ;
  l'autre compte ne peut plus les réécrire (`Error while renaming … CompiledContainer.php`, 1er→6
  novembre, mêmes symptômes pour le container PHP-DI). Correctif 6.59.0 : `TemplateRenderer` teste
  l'écriture et **rend sans cache** au lieu de lever une 500 ; `TWIG_CACHE_DIR` permet de déplacer
  le cache. Reste à faire côté hébergement : un seul propriétaire pour `var/cache` (ou `setgid` +
  umask commun) — voir `docs/deployment/CACHE_MANAGEMENT.md`.
- `energieDataTest` absente (500 sur `/energie-test`) : migration `2026_10_energie_tables.sql` non
  appliquée en prod (hors périmètre, signalé).
