# Procédure courte — « un module ne donne plus signe de vie »

> Vaut pour FFP3 WROOM (`env=prod`), FFP3 S3 (`env=s3`, production aussi), N3PP et MSP1.
> Tout se fait depuis le connecteur MCP `iot_olution` (Claude, Cursor…), en lecture seule.
> Aucune action sur la prod (OTA, sorties, CRON) sans décision explicite d'une personne.

## 1. Un seul appel : `iot_contact_timeline`

```
iot_contact_timeline { "family": "ffp3", "env": "prod", "from": "-24h" }
```

(`env` = `s3` pour l'aquamobile, `family` = `n3pp` / `msp1` pour la serre / la météo ; élargir
`from` à `-3d` si la perte est ancienne, maximum 30 jours.)

## 2. Lire le verdict

| Champ | Ce qu'il dit |
|---|---|
| `last_contact` / `last_authenticated_contact` | Dernière requête reçue, et dernière requête dont l'authentification a été vérifiée (un heartbeat FFP3 sans X-Sig ne compte pas comme authentifié). |
| `expected_wake` | Période de réveil retenue (FreqWakeUp × facteur nuit vs rafales observées), `missed_wakes`, `first_missed_at`, `next_expected_at`, `offline`. **Moins de 2 réveils manqués = attendre.** |
| `classification.verdict` | Cause la plus probable et son pourcentage. |
| `classification.causes[]` | Les six causes avec `for` / `against` : chaque ligne cite une preuve horodatée de la frise. Lire au moins les deux premières. |
| `classification.unverifiable` | Ce que le serveur ne peut PAS savoir : c'est la liste de ce qu'il faut aller voir sur place. |
| `timeline[]` | La frise : rafales de POST, requêtes rejetées (motif, horloge), heartbeats (uptime, reboots, raison de boot, OTA), événements OTA serveur et firmware, publications, photos de la caméra du site, e-mails, erreurs serveur. |

Grille de lecture rapide :

- **`ota` en tête** : une version publiée peu avant, un binaire servi en entier (`COMPLET`) ou un
  `start` firmware sans `marked_valid`. → Mettre le canal en pause **avant toute autre publication**
  (`OTA_PAUSED_CHANNELS` dans la supervision, groupe « Sécurité firmware »), puis étape 3.
- **`clock_signature`** : des requêtes **arrivent encore** mais sont rejetées (`REJETÉE … signature_invalid,
  horloge firmware +2045 s`). Le module est vivant ; le serveur refuse. Vérifier l'alerte P4 « Horloge du
  module désynchronisée », la fenêtre `SIG_VALID_WINDOW`, et que le firmware est ≥ 15.34 / 4.76 / 2.79
  (resynchronisation avant signature, recalage sur `X-Server-Ts`).
- **`wifi_network` / `power`** : la caméra du même site s'est tue aussi → coupure de courant ou de box
  sur place. Si la caméra continue, le réseau du site est sain : alimentation du module seul, ou OTA.
- **`crash_reboot_loop`** : heartbeats avec `boot PANIC/TASK_WDT`, compteur `reboots` qui grimpe, ou
  `MODE SANS ÉCHEC`. Le module revient seul en mode sans échec (pompe maintenue, pas d'OTA) : lire le
  journal de bord (`firmware_heartbeat_log`) pour la cause.
- **`server`** : erreurs 5xx sur la fenêtre ou CRON en retard → `iot_health_report`, `iot_cron_status`,
  `iot_read_file logs/error_log tail`.

## 3. Quoi faire sur place (dans cet ordre)

1. **Regarder la LED / l'OLED** : écran figé ou noir = alimentation ou boot raté ; écran vivant sans
   réseau = Wi-Fi.
2. **Couper et remettre l'alimentation du module** (pas de la box). Attendre une rafale (≈ 2 min) :
   le premier heartbeat après boot porte `rst=POWERON`, la partition (`app0`/`app1`), l'état OTA et le
   nombre de boots ratés. Relancer `iot_contact_timeline` : la frise montre le retour, et
   `firmware_boot` dit si l'image a été validée ou rollbackée.
3. **Si rien ne revient après 2 cycles** : brancher un câble série (115200 bauds, moniteur
   `ffp5cs/monitor_5min.ps1` ou `pio device monitor`). Au boot le firmware écrit la raison de reset, la
   partition et l'état OTA. Un module en boucle de boot ou bloqué dans le bootloader se voit là.
4. **Dernier recours** : reflasher par USB le dernier binaire sain (`ota/esp32-wroom/firmware.bin`
   d'un commit antérieur, ou build local `wroom-prod`), puis **ne republier une OTA qu'en canari**
   (`OTA_CANARY_CHANNELS` + `OTA_CANARY_BOARDS` avec l'identifiant `X-Ota-Board` du banc).

## 4. Ce qui reste impossible à diagnostiquer à distance

| Angle mort | Pourquoi | Matériel / mesure qui le couvrirait |
|---|---|---|
| Coupure ou creux d'alimentation du module | Le module ne peut rien envoyer sans courant ; le serveur ne voit qu'un silence. | **Watchdog / superviseur externe** (ex. TPL5110 ou un second micro-contrôleur alimenté séparément) qui compte les resets et remonte un heartbeat indépendant ; ou **mesure de la tension d'alimentation** (INA226 déjà utilisé sur le banc énergie) postée par la caméra ou par un module voisin. |
| Flash OTA interrompu par une coupure (image corrompue, bootloader en boucle) | Aucune requête possible ; la console série est le seul témoin. | Une **prise connectée** pilotable (coupure/remise sous tension à distance) + le rapport de boot du firmware ≥ 15.34 ; à défaut, un **relais de reset** commandé par le superviseur externe. |
| État réel du Wi-Fi sur site (box redémarrée, canal changé, portée) | La caméra sert de témoin, mais elle peut être sur une autre alimentation / un autre point d'accès. | Faire **poster l'état Wi-Fi** du module voisin (RSSI, BSSID) — champs `rssi`, `wifi_ms`, `wifi_tries` du heartbeat enrichi — et garder les deux appareils sur la **même** alimentation témoin. |
| Image qui démarre puis se fige sans réseau avant de valider | Le rollback n'agit que sur un crash avant validation. | Firmware ≥ 15.34 : validation **seulement** après Wi-Fi + heure + POST accepté, sinon rollback automatique ; le rapport `rollback` arrive au boot suivant. |
| Horloge interne entre deux contacts | Mesurable seulement quand une requête arrive (`clock_offset_s`). | RTC externe DS3231 (déjà prévue en option `USE_RTC_DS3231`) ; resynchronisation avant chaque signature (15.34). |
| Contenu exact de la mémoire au crash | Le coredump ESP-IDF est désactivé sur WROOM (DRAM) ; seule la raison de reset remonte. | Conserver la partition coredump sur les cartes qui ont la flash pour (S3), ou journaliser la pile dans le journal de bord NVS (prévu, borné). |

## 5. Mémo des outils

| Besoin | Outil |
|---|---|
| Vue d'ensemble, résumé `contact_loss` par module hors ligne | `iot_overview`, `iot_health_report` |
| Frise + cause | `iot_contact_timeline` |
| Alertes en cours et e-mails envoyés | `iot_alerts` |
| Journaux web (POST, heartbeats, caméra) | `iot_grep_files logs-public/cronlog-<date>.txt "PostData|Heartbeat"` |
| Journal CRON | `iot_grep_files logs/cronlog-<date>.txt "DeviceHealth|SystemHealth|ota"` |
| Tables brutes | `iot_sql_select` sur `device_contacts`, `ota_events`, `ota_releases`, `device_events`, `ffp3Heartbeat` |
| Mettre un canal OTA en pause / canari | Supervision → réglages « Sécurité firmware » (`OTA_PAUSED_CHANNELS`, `OTA_CANARY_CHANNELS`, `OTA_CANARY_BOARDS`) |
