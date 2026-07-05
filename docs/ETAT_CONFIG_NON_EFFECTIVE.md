# État des configurations « implémentées mais pas (forcément) effectives » — Serveur

> Recensement des fonctionnalités **présentes dans le code** mais **inactives par défaut**
> (variable d'env absente, valeur par défaut neutralisante, code jamais appelé, tâche à
> installer manuellement). Objectif : ne plus confondre « le code existe » et « c'est actif en prod ».
>
> Portée : automatisation du workflow (CRON / CI / OTA) et mails / notifications.
> Voir aussi le pendant firmware dans `n3_firmwires/docs/ETAT_CONFIG_NON_EFFECTIVE.md`.

Légende : ✅ actif · ⚠️ implémenté mais conditionné · ❌ implémenté mais inactif / code mort.

## Mails & notifications

| Élément | Emplacement | Statut | Pour l'activer |
|---|---|---|---|
| Transport SMTP (symfony/mailer) | `src/Notification/MailTransportFactory.php` | ⚠️ Tous les `SMTP_*` sont commentés dans `.env.example` → repli sur `mail()` PHP (souvent muet en mutualisé). | Renseigner `SMTP_DSN=` **ou** `SMTP_HOST/PORT/USER/PASS/ENCRYPTION` dans `.env`. |
| Mode de verbosité `NOTIF_MODE` | `src/Notification/NotificationPolicy.php` | ⚠️ Défaut recommandé `important` → seules P1/P2 passent ; **toutes les P3/P4 sont coupées**, donc le **digest de synthèse** (`NotificationDigest` / `flushDigest`) et les rapports galerie « succès » ne partent jamais. | `NOTIF_MODE=partial` (P3) ou `full` (P3+P4), global ou par famille via les pages de contrôle. |
| Destinataire / expéditeur | `src/Service/NotificationService.php` | ⚠️ Sans config, fallback interne `user@example.com` / `noreply@example.com` → alertes vers une adresse factice. | Renseigner `NOTIF_EMAIL_RECIPIENT` et `MAIL_FROM`. |
| `checkTankLevel()` (niveau réservoir) | `src/Service/SystemHealthService.php` | ⚠️ Implémenté, **dormant** tant que `RESERVE_LOW_LEVEL_THRESHOLD` n'est pas défini dans `.env` (log informatif uniquement). Alerte P2/Hydraulic via `sendAlert` si `EauReserve > seuil` ; aucune action pompe. | Définir `RESERVE_LOW_LEVEL_THRESHOLD` (mm) dans `.env` prod, calibré selon la géométrie du réservoir. |
| `notifyFloodRisk()` (niveau trop haut) | `src/Service/NotificationService.php` | ⚠️ Code mort **volontaire** côté serveur : le trop-plein aquarium est déjà géré par le firmware FFP5CS (`limFlood`, `FloodOrchestrator`, mail ESP32). Une détection CRON dupliquerait les alertes. | Régler `limFlood` via la page de contrôle / firmware ; ne pas activer côté serveur. |
| `ALERT_EMAIL` (exemple Docker) | `.env.docker.example` | ✅ **Corrigé** : variable morte (jamais lue) retirée ; le code lit `NOTIF_EMAIL_RECIPIENT`. | — |
| HMAC n3pp / msp / CAM | `firmwires/credentials.h` + `HmacAuthTrait` | ⚠️ Supporté côté serveur si `API_SIG_SECRET` est défini dans `.env` **et** dans `credentials.h`. Souvent absent en prod → auth legacy `api_key` seule. | Renseigner `API_SIG_SECRET` dans les deux fichiers, reflasher les firmwares. |
| HMAC poissonglouton (`PGL_API_SIG_SECRET`) | `poissonglouton/src/pgl_network.cpp` | ❌ Option firmware documentée ; **le serveur PGL ne valide que `api_key`** (`PglPostDataController`, `PglHeartbeatController`). | Ne pas activer côté firmware tant que le serveur n'implémente pas la validation HMAC PGL. |
| Variables `REALTIME_*`, `PWA_*`, `PUSH_VAPID_*` | Archives `docs/archive/implementations/` | ❌ **Non lues** par le code PHP actuel (legacy v4). Présentes dans certains `.env` historiques. | Retirer du `.env` prod ou ignorer ; ne pas les ajouter aux nouveaux déploiements. |
| `PGL_STATS_TOKEN` | — | ❌ Variable orpheline (aucune lecture dans `src/`). | Supprimer du `.env` local. |

Notifications réellement actives (sous réserve du transport + mode ci-dessus) : marée figée,
niveau eau bas, heartbeat silencieux (`DeviceHealthService`), système hors ligne, `ErrorAlertService`.

## Automatisation du workflow

| Élément | Emplacement | Statut | Pour l'activer |
|---|---|---|---|
| `CronOrchestrator` | `run-cron.php`, `src/Command/CronOrchestrator.php` | ⚠️ Code complet, mais **le crontab n'est posé par rien dans le dépôt** — seulement documenté. | Installer à la main sur le serveur : `*/5 * * * * php run-cron.php` (cf. `docs/deployment/CRON.md`), `var/cache/` inscriptible. |
| Hook `post-merge` (vidage caches) | `bin/install-hook-post-merge.sh` | ⚠️ Non actif tant que non installé → après `git pull`, les caches Twig/DI ne sont pas vidés (modifs invisibles). | `bash bin/install-hook-post-merge.sh` une fois sur le serveur. |
| CI GitHub Actions | `.github/workflows/ci.yml` | ✅ tests/qualité — ❌ **aucun CD / OTA / notification**. Aucun secret. | Un déploiement continu nécessiterait un nouveau workflow + secrets (inexistants). |
| Auth OTA | `src/Controller/Ffp3/OtaFileController.php` | ⚠️ `OTA_REQUIRE_AUTH=false` par défaut → endpoints `/ota/**` publics (intégrité assurée côté firmware par sha256/ECDSA). | `OTA_REQUIRE_AUTH=true` + `API_KEY` renseignée, firmwares envoyant la clé. |

## Ce qui ne peut PAS être activé sans intervention manuelle / décision

- **Crontab & hook de déploiement** : accès serveur requis.
- **SMTP réel** : identifiants (hors dépôt, `.env` non versionné).
- **`RESERVE_LOW_LEVEL_THRESHOLD`** : seuil réserve à calibrer manuellement en prod (opt-in).
- **CD via GitHub Actions** : secrets de déploiement à provisionner.
