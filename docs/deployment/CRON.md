# Tâches CRON — serveur n3 IoT

Référence pour le déploiement et la maintenance des crons sur **iot.olution.info**.

## Vue d'ensemble

Deux crons système distincts :

| Cron | Fréquence | Rôle |
|------|-----------|------|
| **Déploiement** | Toutes les minutes | `git pull origin master` → hook `post-merge` → vidage caches |
| **Applicatif FFP3** | Toutes les 5 minutes | `php run-cron.php` → `CronOrchestrator` |

MSP1 et N3PP n'ont pas de cron dédié : les données sont traitées à la réception POST.

## Crontab de production

Chemin serveur : `/home4/oliviera/iot.olution.info`

```cron
# Déploiement automatique (ne pas supprimer)
* * * * * cd /home4/oliviera/iot.olution.info && /usr/bin/git pull origin master >> /dev/null 2>&1

# Orchestrateur applicatif FFP3 (remplace cronpompe.php et anciennes commandes séparées)
*/5 * * * * cd /home4/oliviera/iot.olution.info && /usr/bin/php run-cron.php >> /dev/null 2>&1
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
            ├── verrou global (flock cron_orchestrator.lock)
            ├── RestartPumpCommand (flag pompe aqua, délai 5 min)
            ├── tâches fréquentes (chaque run)
            └── tâches horaires (si intervalle écoulé)
```

### Tâches fréquentes (chaque exécution ~5 min)

1. Redémarrage pompe aquarium si flag `/tmp/pump_restart_scheduled.flag` expiré
2. Journalisation état pompes (aquarium, réserve, reset)
3. Nettoyage valeurs aberrantes (`SensorDataService::cleanAllSensorData`)
4. Alerte niveau eau bas → arrêt pompe réserve + email
5. Détection marée figée (écart-type faible) → arrêt pompe aqua + flag 5 min + email
6. Log écart-type sur la dernière heure (informatif)

### Tâches horaires (intervalle configurable)

Fichier d'état : `var/cache/cron_last_hourly.timestamp`

1. Vérification système en ligne (`SystemHealthService::checkOnlineStatus`, seuil 1 h)
2. Vérification niveau réserve (`SystemHealthService::checkTankLevel`, opt-in via `RESERVE_LOW_LEVEL_THRESHOLD`)

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
