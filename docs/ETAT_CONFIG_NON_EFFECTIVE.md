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
| `checkTankLevel()` (niveau réservoir) | `src/Service/SystemHealthService.php:67` | ❌ Placeholder appelé à chaque cycle horaire du CRON : ne fait qu'un `log('logique à définir')`, aucune alerte émise. `notifyLowTankLevel()` n'existe pas. | **Décision métier requise** : champ capteur réservoir (`EauReserve` ?), seuil, sévérité, création de la notification. |
| `notifyFloodRisk()` (niveau trop haut) | `src/Service/NotificationService.php` | ❌ Code mort : jamais appelé en prod (seul le niveau *bas* est géré par `CronOrchestrator::checkLowWaterLevel()`). | **Décision métier requise** : ajouter une détection « niveau haut » qui l'appelle. |
| `ALERT_EMAIL` (exemple Docker) | `.env.docker.example` | ✅ **Corrigé** : variable morte (jamais lue) retirée ; le code lit `NOTIF_EMAIL_RECIPIENT`. | — |

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
- **`checkTankLevel()` / `notifyFloodRisk()`** : seuils et sémantique métier à trancher.
- **CD via GitHub Actions** : secrets de déploiement à provisionner.
