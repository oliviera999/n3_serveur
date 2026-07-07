# Tâches CRON — serveur n3 IoT

Référence pour le déploiement et la maintenance des crons sur **iot.olution.info**.

## Vue d'ensemble

Deux crons système distincts :

| Cron | Fréquence | Rôle |
|------|-----------|------|
| **Déploiement** | Toutes les minutes | `git pull origin master` → hook `post-merge` → vidage caches |
| **Applicatif FFP3** | **Toutes les minutes** | `php run-cron.php` → `CronOrchestrator` |

MSP1 et N3PP n'ont pas de cron dédié : les données sont traitées à la réception POST,
et leurs **alertes dérivées** (batterie, redémarrage, sol sec) sont évaluées chaque minute
par l'orchestrateur (Phase 2 arbitrage mails).

> **Phase 1 arbitrage mails** (cf. `docs/ARCHITECTURE_MAILS_ARBITRAGE.md`) : la cadence
> passe de 5 min à **1 min** — le serveur est l'émetteur primaire de toutes les alertes
> dérivables des données, avec une latence cible ≤ 1 min (y compris trop-plein).

## Crontab de production

Chemin serveur : `/home4/oliviera/iot.olution.info`

```cron
# Déploiement automatique (ne pas supprimer)
* * * * * cd /home4/oliviera/iot.olution.info && /usr/bin/git pull origin master >> /dev/null 2>&1

# Orchestrateur applicatif (remplace cronpompe.php et anciennes commandes séparées)
# Cadence 1 min (Phase 1 arbitrage mails) : le verrou flock ignore proprement les
# runs qui se chevauchent ; l'anti-spam (AlertThrottler, cooldown par sévérité) et
# les machines d'état (debounce trop-plein, latches) bornent le volume d'e-mails.
* * * * * cd /home4/oliviera/iot.olution.info && /usr/bin/php run-cron.php >> /dev/null 2>&1
```

**Migration** : supprimer toute entrée legacy (`cronpompe.php`, multiples `run-cron.php`, scripts `cronmsp1.php` / `cronn3pp.php` si encore présents).

### Prérequis

- Hook Git installé : `bash bin/install-hook-post-merge.sh`
- `.env` configuré (BDD, emails, seuils)
- Dossier `var/cache/` inscriptible par l'utilisateur cron
- Branche Git : **master** (pas `main`)

## Architecture applicative

```
run-cron.php (CLI uniquement)
    └── CronOrchestrator
            ├── verrou global (flock cron_orchestrator.lock, non bloquant : run chevauché = skip)
            ├── RestartPumpCommand (flag pompe aqua, délai 5 min HORODATÉ — indépendant de la cadence)
            ├── tâches fréquentes (chaque run, soit chaque minute)
            └── tâches horaires (si intervalle écoulé)
```

### Tâches fréquentes (chaque exécution, ~1 min)

1. Redémarrage pompe aquarium si flag `/tmp/pump_restart_scheduled.flag` expiré (délai horodaté 5 min)
2. Journalisation état pompes (aquarium, réserve, reset)
3. Nettoyage valeurs aberrantes (`SensorDataService::cleanAllSensorData`)
4. Alerte niveau eau bas → arrêt pompe réserve + email
5. Détection marée figée (écart-type faible) → arrêt pompe aqua + flag 5 min + email.
   ⚠️ Sautée tant qu'un redémarrage est déjà programmé (sinon, à 1 min, le flag serait
   réécrit à chaque tick et le redémarrage perpétuellement repoussé)
6. **Alerte réserve basse** (`SystemHealthService::checkTankLevel`, opt-in) — déplacée du bucket horaire
7. **Alertes dérivées du POST** (Phases 2+, `src/Service/DerivedAlert/`) :
   - FFP3 : trop-plein (machine debounce/cooldown portée du firmware), chauffage ON/OFF,
     remplissage démarré/terminé (`etatPompeTank`), firmware mis à jour (OTA réussie)
   - N3PP : sol sec (hystérésis +5 %), batterie faible, redémarrage (reset `bootCount`),
     arrosage effectué (`etatPompe`), arrosage continu (pompe ON sur ≥ 2 lignes),
     firmware mis à jour
   - MSP1 : batterie faible, redémarrage, firmware mis à jour + **alertes météo opt-in**
     (gel `MSP_FROST_ALERT_THRESHOLD_C`, canicule `MSP_HEAT_ALERT_THRESHOLD_C`,
     pluie `MSP_RAIN_WET_THRESHOLD` — désactivées si non définies dans `.env`)
   État inter-runs persisté dans `var/cache/derived_alerts_*.json`
8. Log écart-type sur la dernière heure (informatif)

> **Anti-spam à 1 min** : chaque alerte passe par `NotificationService` → politique de
> notification + `AlertThrottler` (cooldown par clé : P1 15 min, P2 1 h, P3 6 h, P4 24 h)
> + digest P3/P4. Les machines d'état (latch batterie/sol sec, debounce trop-plein)
> garantissent un e-mail par épisode, pas par tick.

### Tâches horaires (intervalle configurable)

Fichier d'état : `var/cache/cron_last_hourly.timestamp`

1. Vérification système en ligne (`SystemHealthService::checkOnlineStatus`, seuil dérivé du temps de veille)
2. Supervision « appareil silencieux » toutes familles (`DeviceHealthService::checkAllFamilies`)
3. Envoi du digest des alertes P3/P4 accumulées (`NotificationService::flushDigest`)

> Le niveau de réserve (`checkTankLevel`) est passé dans les tâches fréquentes (Phase 1).

## Variables `.env`

| Variable | Défaut | Description |
|----------|--------|-------------|
| `CRON_HOURLY_INTERVAL_SECONDS` | `3600` | Délai minimum entre deux passes horaires |
| `AQUA_LOW_LEVEL_THRESHOLD` | `180` | Distance capteur→surface aquarium (mm) au-delà de laquelle l'eau est basse — arrêt pompe réserve (aligné `aqThreshold` firmware, 18 cm) |
| `RESERVE_LOW_LEVEL_THRESHOLD` | *(vide = désactivé)* | Distance capteur→surface réserve (mm) au-delà de laquelle la réserve est basse — alerte email uniquement (aucune action pompe) |
| `TIDE_STDDEV_THRESHOLD` | `1` | Seuil écart-type marée |
| `CLEAN_MIN_*` / `CLEAN_MAX_*` | voir `.env.example` | Seuils nettoyage capteurs |
| `LOG_FILE_PATH` | `cronlog.txt` | Journal Monolog |
| `NOTIF_EMAIL_RECIPIENT` | — | Destinataire alertes email |
| `NOTIF_MODE` / `NOTIF_DISABLED_CATEGORIES` | voir `.env.example` | Défaut global ; surcharge par famille via les pages de contrôle (FFP3, MSP1, N3PP, galeries) |

## Logs et diagnostic

- **Journal applicatif** : [https://iot.olution.info/public/cronlog.txt](https://iot.olution.info/public/cronlog.txt)
- **Marqueurs attendus** : `--- Début orchestrateur CRON ---` / `--- Fin orchestrateur CRON ---`
- **Erreurs wrapper** : `error_log` PHP, lignes `[run-cron]`
- **Test manuel SSH** :

```bash
cd /home4/oliviera/iot.olution.info
php run-cron.php
tail -n 30 cronlog.txt
```

## Fichiers source

| Fichier | Rôle |
|---------|------|
| [`run-cron.php`](../../run-cron.php) | Point d'entrée CLI |
| [`src/Command/CronOrchestrator.php`](../../src/Command/CronOrchestrator.php) | Orchestrateur |
| [`src/Command/RestartPumpCommand.php`](../../src/Command/RestartPumpCommand.php) | Redémarrage différé pompe aqua |
| [`bin/hooks/post-merge`](../../bin/hooks/post-merge) | Vidage caches après git pull |

## Legacy (hors orchestrateur)

- **`triphotos.php`** (galeries) : absent du dépôt unifié ; protéger par token si réintroduit (`TRIPHOTOS_SECRET`, voir `RECOMMANDATIONS_IOT.md`)
- **`cronpompe.php`** : supprimé ; ne plus référencer

## Tests

```bash
cd serveur
composer test:unit -- --filter "CronOrchestratorTest|RestartPumpCommandTest"
```
