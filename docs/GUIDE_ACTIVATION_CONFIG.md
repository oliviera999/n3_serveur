# Guide pas-à-pas — Vérifier que tout est bien activé

> Checklist opérationnelle pour s'assurer que les fonctionnalités « workflow / mails / OTA »
> sont **réellement effectives** en production (`iot.olution.info`), et pas seulement présentes
> dans le code. À dérouler dans l'ordre. Chaque point = **quoi vérifier → comment → attendu → sinon**.
>
> Complète `docs/ETAT_CONFIG_NON_EFFECTIVE.md` (le *quoi*) par le *comment vérifier*.
> Réf. : `docs/deployment/CRON.md`, `docs/AUTHENTICATION.md`, et côté firmware
> `n3_firmwires/docs/OTA_GITHUB_DEPLOY.md`.

Légende cases : `[ ]` à vérifier · cocher `[x]` une fois validé.

---

## 1. Mails / Notifications (serveur)

### 1.1 — Destinataire et expéditeur réels
- [ ] **Vérifier** : `.env` de prod contient un vrai destinataire.
- **Comment** : sur le serveur, `grep -E 'NOTIF_EMAIL_RECIPIENT|MAIL_FROM' .env`
- **Attendu** : une **vraie adresse** (pas `your_notification_email@example.com` ni `user@example.com`).
- **Sinon** : renseigner `NOTIF_EMAIL_RECIPIENT=...` et `MAIL_FROM="Nom <adresse>"`. Sans ça, les alertes partent vers une adresse factice.

### 1.2 — Transport SMTP (sinon `mail()` souvent muet)
- [ ] **Vérifier** : un transport SMTP est configuré (sinon repli sur `mail()` PHP, souvent inopérant en mutualisé).
- **Comment** : `grep -E '^SMTP_DSN|^SMTP_HOST' .env`
- **Attendu** : `SMTP_DSN=` renseigné **ou** `SMTP_HOST/PORT/USER/PASS/ENCRYPTION` renseignés (dé-commentés).
- **Sinon** : configurer le SMTP (ex. `SMTP_DSN=smtps://user:pass@smtp.hebergeur:465`). Si l'hébergeur a un MTA local fiable, `mail()` peut suffire — à valider par un envoi réel (1.4).

### 1.3 — Mode de verbosité (le digest P3/P4)
- [ ] **Vérifier** : `NOTIF_MODE` correspond au niveau d'alerte voulu.
- **Comment** : `grep '^NOTIF_MODE' .env`
- **Attendu** : `important` = seulement critiques P1 + alertes P2 (**le digest de synthèse et les infos P3/P4 ne partent PAS**). `partial` = + P3. `full` = tout (P3 + P4, digest inclus).
- **Sinon** : si tu veux recevoir les **e-mails de synthèse (digest)** et les rapports « succès » (galeries), passer à `partial` ou `full` (global dans `.env`, ou par famille via les pages de contrôle FFP3/N3PP/MSP1). `NOTIF_DISABLED_CATEGORIES` doit être vide (ou ne couper que ce qui est voulu).

### 1.4 — Envoi réel de bout en bout
- [ ] **Vérifier** : un e-mail arrive vraiment.
- **Comment** : déclencher une passe CRON manuelle qui évalue les alertes : `php run-cron.php` (voir §2.2), OU provoquer une condition d'alerte connue. Surveiller la réception + le journal.
- **Attendu** : réception d'un e-mail (ou, selon l'état système, une entrée d'envoi dans les logs) ; pas d'erreur SMTP dans `error_log`.
- **Sinon** : vérifier identifiants SMTP, port/chiffrement, quotas de l'hébergeur, dossier spam.

---

## 2. CRON / Orchestration (serveur)

### 2.1 — Les deux crons système sont installés
- [ ] **Vérifier** : crontab de prod contient le déploiement (chaque minute) **et** l'orchestrateur (toutes les 5 min).
- **Comment** : `crontab -l`
- **Attendu** (cf. `docs/deployment/CRON.md`) :
  ```cron
  * * * * * cd /home4/oliviera/iot.olution.info && /usr/bin/git pull origin master >> /dev/null 2>&1
  */5 * * * * cd /home4/oliviera/iot.olution.info && /usr/bin/php run-cron.php >> /dev/null 2>&1
  ```
- **Sinon** : ajouter ces lignes (`crontab -e`). Supprimer tout legacy (`cronpompe.php`, `cronmsp1.php`, `cronn3pp.php`).

### 2.2 — L'orchestrateur s'exécute réellement
- [ ] **Vérifier** : le journal applicatif est frais et contient les marqueurs de début/fin.
- **Comment** : ouvrir <https://iot.olution.info/public/cronlog.txt> (ou `tail -n 50 public/cronlog.txt`). Test manuel : `php run-cron.php` puis re-regarder.
- **Attendu** : lignes `--- Début orchestrateur CRON ---` / `--- Fin orchestrateur CRON ---` datées de moins de ~5 min, sans exception PHP.
- **Sinon** : vérifier le chemin PHP du crontab, les droits, et `error_log` (lignes `[run-cron]`). Le verrou `flock` (`cron_orchestrator.lock`) empêche les recouvrements — normal si un run est sauté.

### 2.3 — Cache CRON inscriptible (tâches horaires)
- [ ] **Vérifier** : l'état des tâches horaires se met à jour.
- **Comment** : `ls -l var/cache/cron_last_hourly.timestamp`
- **Attendu** : fichier présent, horodatage qui avance environ toutes les heures.
- **Sinon** : rendre `var/cache/` inscriptible par l'utilisateur du cron.

### 2.4 — Seuils d'alerte cohérents
- [ ] **Vérifier** : les seuils métier sont ceux voulus.
- **Comment** : `grep -E 'AQUA_LOW_LEVEL_THRESHOLD|TIDE_STDDEV_THRESHOLD|HEARTBEAT_OFFLINE_THRESHOLD_SECONDS|CRON_HOURLY_INTERVAL_SECONDS' .env`
- **Attendu** : valeurs adaptées (défauts : 180 mm / 1 / 3600 s / 3600 s).
- **Note** : `checkTankLevel()` (niveau réserve) reste un **placeholder** (aucune alerte réserve émise) — voir §6.

---

## 3. Déploiement automatique / hook (serveur)

### 3.1 — Hook `post-merge` installé (vidage des caches après pull)
- [ ] **Vérifier** : le hook est bien en place (sinon les modifs déployées restent invisibles, caches Twig/DI non vidés).
- **Comment** : `ls -l .git/hooks/post-merge`
- **Attendu** : fichier présent et exécutable, pointant vers la logique de `bin/hooks/post-merge`.
- **Sinon** : `bash bin/install-hook-post-merge.sh`. Test : `php bin/clear-cache.php` doit vider sans erreur.

### 3.2 — Branche Git = `master`
- [ ] **Vérifier** : `git rev-parse --abbrev-ref HEAD` → `master` sur le serveur (le cron pull `origin master`).

---

## 4. OTA — côté serveur

### 4.1 — Fichiers OTA servis publiquement
- [ ] **Vérifier** : les métadonnées OTA sont accessibles.
- **Comment** : `curl -sI http://iot.olution.info/ota/n3pp/metadata.json` (et `/ota/msp/`, `/ota/cam/`, `/ota/pgl/`, `https://.../ota/metadata.json` pour ffp5).
- **Attendu** : `200 OK` (ou `206` sur Range) pour les cibles déjà déployées. `/ota/pgl/` renverra `404` **tant qu'aucun déploiement pgl n'a eu lieu** (normal, cf. §5).
- **Sinon** : vérifier les routes `/ota/{path}` (`public/index.php`) et l'arbre `ota/`.

### 4.2 — Authentification OTA (choix de sécurité)
- [ ] **Vérifier** : `grep '^OTA_REQUIRE_AUTH' .env`
- **Attendu** : `false` = endpoints OTA publics (intégrité garantie côté firmware par sha256 + ECDSA). `true` = exige `API_KEY` (et firmwares envoyant la clé).
- **Sinon** : laisser `false` sauf besoin explicite ; si `true`, s'assurer que `API_KEY` est défini **et** que la flotte envoie la clé.

---

## 5. OTA — pipeline firmware (dépôt n3_firmwires)

### 5.1 — Secrets GitHub Actions provisionnés
- [ ] **Vérifier** : *Settings ▸ Secrets and variables ▸ Actions* du dépôt `n3_firmwires` contient :
  - `OTA_SIGNING_KEY` (clé privée ECDSA, correspond à `shared/n3_common/.../n3_ota_pubkey.h`)
  - `CREDENTIALS_H` (credentials.h de prod — n3pp/msp/cam/**pgl**)
  - `FFP5CS_SECRETS_H` + `FFP5CS_SECRETS_CONFIG_H` (ffp5cs)
  - `N3_SERVEUR_DEPLOY_TOKEN` (PAT avec push sur `n3_serveur`)
- **Attendu** : tous présents. Variables optionnelles : `N3_SERVEUR_REPO`, `N3_SERVEUR_OTA_ROOT` (défauts OK).
- **Sinon** : le déploiement échoue avec `::error::Secret ... absent`.

### 5.2 — Environnement `prod` (garde-fou humain)
- [ ] **Vérifier** : *Settings ▸ Environments ▸ `prod`* existe avec **Required reviewers**.
- **Attendu** : tout déploiement `dry_run=false` + `channel=prod` attend une approbation.

### 5.3 — Dry-run de validation (aucune écriture)
- [ ] **Vérifier** : le pipeline compile/signe sans erreur.
- **Comment** : *Actions ▸ Firmware OTA Deploy ▸ Run workflow* → firmware au choix, `dry_run=true`.
- **Attendu** : job vert, log `[OTA] metadata.json:` affiché, artefact `firmware.bin` produit.

---

## 6. OTA poissonglouton (`pgl`) — activation dédiée

> La chaîne est **câblée** (cible `pgl`, prod-only, binaire `pgl-s3-display`) mais **aucun binaire
> n'est encore publié**. Le firmware interroge déjà `/ota/pgl/metadata.json` toutes les 2 h.

- [ ] **6.1 Version prête** : `python tools/ota/publish_ota.py --firmware pgl --print-version` → version à déployer (ex. `0.5.15`). Bumper `poissonglouton/include/config.h` (`PGL_FIRMWARE_VERSION`) si besoin **avant** de déployer.
- [ ] **6.2 Déclencher le déploiement** : *Actions ▸ Firmware OTA Deploy* → `firmware=pgl`, `channel=prod`, d'abord `dry_run=true` (valider), puis `dry_run=false` (approuver l'env `prod`). Alternative : `git tag ota-deploy/pgl/prod && git push --tags`.
- [ ] **6.3 Vérifier la publication** : `curl -s http://iot.olution.info/ota/pgl/metadata.json` → objet `{version,url,sha256,signature}` avec la bonne version ; `curl -sI http://iot.olution.info/ota/pgl/firmware.bin` → `200`.
- [ ] **6.4 Vérifier la prise OTA sur une carte** : au prochain cycle (≤ 2 h) ou reboot, la carte `pgl-s3-display` doit logguer une vérification OTA et, si version distante > locale, se mettre à jour (vérif sha256 + ECDSA).
- **⚠️ Limite** : une seule cible `pgl` = un binaire (`pgl-s3-display` / JC4827W543). Les cartes `pgl-s3-jc3248` (autre écran) et `pgl-s3-headless` **ne sont pas couvertes** — ne pas leur pousser cet OTA.

---

## 7. Mails / OTA — côté firmwares (flotte)

- [ ] **7.1 Secrets de compilation** : les binaires de prod sont bâtis avec un **vrai** `firmwires/credentials.h` (WiFi + `SMTP_*` + `API_KEY` / `API_SIG_SECRET`) — pas les placeholders CI. FFP5CS : `include/secrets.h` (WiFi/SMTP) **et** `include/secrets_config.h` (`API_KEY`, destinataire mail, HMAC). Sinon les mails firmware partent vers un compte inexistant ou les POST échouent en 401.
- [ ] **7.2 Interrupteur mail distant** : la clé serveur `101` (config appareil) est sur `checked`/`full` pour les appareils qui doivent alerter (n3pp/msp). `none`/`unchecked` = mails coupés.
- [ ] **7.3 OTA embarqué** : `PGL_ENABLE_OTA`, OTA n3pp/msp/cam/ffp5cs actifs (déjà `=1` par défaut). `OTA_CA_CERT` reste non défini → OTA en `setInsecure()` (chiffré, certif non vérifié ; authenticité par ECDSA). Définir `OTA_CA_CERT` si on veut la validation TLS complète (Phase 2).

---

## 8. Intégration continue (les deux dépôts)

- [ ] **8.1 CI serveur verte** : `n3_serveur` Actions ▸ workflow *CI* (cs:check, PHPStan, PHPUnit, audit) au vert sur `master`.
- [ ] **8.2 CI firmware verte** : `n3_firmwires` Actions ▸ *Firmware CI* — tests natifs (dont `test_data`, `test_net_stats`) + builds matriciels, au vert.
- **Local (équivalent)** : serveur `composer cs:check && composer analyse && composer test:unit && composer audit` ; firmware `cd shared/tests_native && pio test -c platformio-native.ini -e native`.

---

## Récap « c'est activé si… »

| Domaine | Activé quand |
|---|---|
| Mails | `NOTIF_EMAIL_RECIPIENT` réel + SMTP configuré + `NOTIF_MODE` au bon niveau + un e-mail test reçu |
| Digest de synthèse | `NOTIF_MODE=partial`/`full` (jamais avec `important`) |
| CRON | crontab posé + `cronlog.txt` frais avec marqueurs début/fin |
| Déploiement auto | hook `post-merge` installé (caches vidés après pull) |
| OTA serveur | `/ota/<cible>/metadata.json` en `200` |
| OTA pgl | déploiement lancé une fois → `/ota/pgl/metadata.json` en `200` |
| OTA pipeline | secrets GitHub + env `prod` présents ; dry-run vert |
| CI | les deux workflows verts sur `master` |
