# Plan d'action BDD — IoT n3 (juillet 2026)

**Projet** : serveur unifié n3 IoT (`iot.olution.info`)  
**Version serveur** : `6.8.1` (document) / firmwares de référence : N3PP `4.50`, MSP `2.49`, FFP5CS `15.01`  
**Dump prod analysé** : `dump_bdd/oliviera_iot (1).sql` — **719 Mo**, **~3,74 M lignes**, export du **05/07/2026**  
**Statut** : **serveur 6.8.1** livré ; scripts SQL prêts ; **prod** : exécuter `01a`→`01`→`02`→`03` sur oliviera_iot ; firmwares à flasher terrain

---

## Références audits (05/07/2026)

Trois audits parallèles ont été conduits dans la session d'analyse du 05/07/2026 (conversation parent `dec3d54c`) :

| Audit | Périmètre | Constats majeurs |
|-------|-----------|------------------|
| **Schéma serveur** | `TableConfig`, migrations, Docker init, `*GpioMap`, repositories | S3 code → `*S3` mais prod → `ffp3Data4` (~779 k lignes) ; Docker manque `gallerySyncSessions` ; GPIO 117/110 hors maps ; `allowedGpios` trop large |
| **Firmware ↔ serveur** | N3PP 4.49, MSP 2.48, FFP5CS 15.01 vs endpoints/GPIO | Heartbeat N3PP/MSP **absent** côté firmware ; URLs legacy `.php` ; notifications GPIO 101/108/109 incohérentes ; `etatPompe` N3PP non lié GPIO 12 |
| **Dump & données** | `oliviera_iot` 719 Mo | FFP3/N3PP actifs au 05/07 ; MSP stale ~40 j ; heartbeats msp/n3pp vides ; double-POST N3PP ; pollution historique ; tables orphelines |

**Documents liés** :

- [`ENDPOINTS_ESP32_SERVEUR.md`](ENDPOINTS_ESP32_SERVEUR.md) — contrat API ESP32
- [`API_MSP1_N3PP.md`](API_MSP1_N3PP.md) — GPIO actionneurs MSP/N3PP
- [`migrations/README.md`](../migrations/README.md) — procédure prod et checklist migrations
- [`README.md`](../README.md) — installation, Docker, import dump local

---

## 1. Résumé exécutif

Le diagnostic des trois audits converge : **contrat firmware ↔ serveur partiellement rompu** (N3PP/MSP), **dette structurelle BDD** (S3 orphelin, GPIO doublons, heartbeats vides), et **données pédagogiquement bruitées** (double-POST N3PP, valeurs fallback FFP3, pollution early-stage).

**Arbitrages critiques tranchés le 05/07/2026** (voir §8) : migration S3 Option A, GPIO N3PP 12 / MSP sans pompe, notifications Option B.

**Priorité immédiate** : sécuriser prod (backup, migrations conditionnelles), appliquer migration S3, corriger notifications GPIO 101, activer heartbeat N3PP/MSP, aligner seed/Docker, puis **élagage qualitatif** (Annexe B — chronologie intacte, pas de purge par date).

**Stratégie données (juillet 2026)** : la rétention temporelle 12/24 mois est **rejetée** ; conserver l'historique depuis l'origine des tables et supprimer uniquement le bruit qualitatif (tables orphelines, doublons, valeurs aberrantes avérées).

---

## 2. Principes directeurs

| Principe | Application |
|----------|-------------|
| **Cohérence firmware ↔ serveur** | Toute modification endpoint/GPIO = changement de contrat documenté et déployé des deux côtés |
| **Pas de breaking change sans migration** | ALTER sur tables volumineuses : backup, script idempotent, `99_validate_prod.sql` |
| **Source unique GPIO** | GPIO métier dans `Ffp3GpioMap` / `MspGpioMap` / `N3ppGpioMap` / `NotificationPolicyGpioMap` |
| **TableConfig = vérité runtime** | Jamais de nom de table en dur ; S3 → `*S3` (arbitrage A verrouillé) |
| **Test Docker avant prod** | `local-docker.ps1` → smoke `-AuthMode both` → `composer test` |
| **Pédagogie** | Critères d'acceptation vérifiables (pages web, phpMyAdmin, requêtes HTTP) |

---

## 3. Phases et actions (41 actions / 6 phases)

> Effort : **S** = petit, **M** = moyen, **L** = large.  
> Statut arbitrage : actions marquées 🔒 intègrent une décision verrouillée §8.

### Phase 0 — Urgences prod / risque données

| Ordre | ID | Titre | Description | Critères d'acceptation | Effort |
|------:|-----|-------|-------------|------------------------|--------|
| 1 | **P0-U01** | Backup et diagnostic prod | Export phpMyAdmin + `00_diagnostic_prod.sql` + `99_validate_prod.sql` | Rapport daté ; colonnes manquantes listées | S |
| 2 | **P0-U02** 🔒 | Migration S3 Option A | Copier `ffp3Data4` → `ffp3DataS3` (+ outputs4→outputsS3, heartbeat4→heartbeatS3 si existants) ; `TableConfig` inchangé | ~779 k lignes migrées ; routes `/post-data3` lisent `ffp3DataS3` | L |
| 3 | **P0-U03** | Bundle migrations prod | `APPLY_PROD_AUDIT_2026.sql` si diagnostic OK | `99_validate_prod.sql` vert | M |
| 4 | **P0-U04** | Investiguer MSP stale (~40 j) | Dernier `reading_time` msp1Data, logs, WiFi, URLs firmware | Cause identifiée ; plan correctif | M |
| 5 | **P0-U05** 🔒 | Nettoyer doublons GPIO prod | `FIX_DUPLICATE_GPIO_ROWS` ; **supprimer GPIO 109 legacy « Arrosage manuel »** N3PP/MSP ; garder GPIO **13** N3PP arrosage ; fantômes `ffp3Outputs3` gpio 16 NULL | Une ligne par `(board, gpio)` ; N3PP 12=pompe, 13=arrosage | M |
| 6 | **P0-U06** | Qualifier pollution N3PP | Double-POST, valeurs aberrantes, `sensor=msp1` historique ; règles filtrage **affichage/stats** (pas DELETE niveau 3 FFP3) | Rapport volumes ; règle filtrage stats | M |
| 7 | **P0-U07** 🔒 | Migration S3 + DROP `ffp3Data4` | Post P0-U02/P2-01 : export optionnel puis `DROP TABLE ffp3Data4` (+ outputs4/heartbeat4 si migrés) ; Option A verrouillée | Comptages `ffp3DataS3` = source ; `ffp3Data4` absente | M |
| 8 | **P0-U08** | Élagage qualitatif niveaux 0–1 | Annexe B **0b–0d** immédiat ; **1b–1c** ; **1a** (double-POST) **après P0-U09** | Scripts idempotents ; backup préalable ; gain ~150–250 Mo | M |
| 9 | **P0-U09** | Comptage double-POST N3PP | Mesurer paires à 1–2 s identiques sur `n3ppData` ; prérequis action **1a** | Rapport volumes (% doublons) ; règle validée avant 1a / P2-11 | S |

### Phase 1 — Contrat firmware ↔ serveur (P0)

| Ordre | ID | Titre | Description | Critères d'acceptation | Effort |
|------:|-----|-------|-------------|------------------------|--------|
| 1 | **P1-01** | Heartbeat N3PP firmware | POST `/n3pp/heartbeat` (sensor, version, uptime, rssi, auth) | Lignes dans `n3ppHeartbeat` ; en ligne < 15 min | L |
| 2 | **P1-02** | Heartbeat MSP firmware | POST `/msp1/heartbeat` → `msp1Heartbeat` | Idem P1-01 pour MSP | L |
| 3 | **P1-03** 🔒 | Notifications Option B | GPIO **108/109** = server-only (UI web) ; firmware lit uniquement **101** | GET `/state` n'expose pas 108/109 ; doc à jour | M |
| 4 | **P1-04** | Documenter collision GPIO 106/107 | Matrice inter-familles (FFP3 nourrissage / MSP servo / N3PP arrosage) | Fiche dans `ENDPOINTS_ESP32_SERVEUR.md` | S |
| 5 | **P1-05** 🔒 | Aligner `etatPompe` N3PP ↔ GPIO 12 | Relais pompe confirmé terrain GPIO **12** ; sync POST ↔ `n3ppOutputs` | Toggle UI = état ESP32 terrain | M |
| 6 | **P1-06** | URLs canoniques Slim | Remplacer `*.php` par `/n3pp/post-data`, `/msp1/api/outputs/state`, etc. | Firmware sans `.php` ; POST/GET 200 | M |
| 7 | **P1-07** | HMAC heartbeat legacy | Parité `X-Sig-*` sur heartbeat N3PP/MSP (optionnel `.env`) | Rejet sans auth si `HMAC_STRICT_MODE=true` | M |
| 8 | **P1-08** | Clarifier `FIRMWARE_STATE_REQUIRE_KEY` | Documenter impact GET `/state` MSP/N3PP | Valeur prod recommandée testée | S |
| 9 | **P1-09** 🔒 | Corriger `mailNotifValueForFirmware()` | Écrire mode réel (`important`/`partial`/`full`/`none`) sur GPIO **101** ; tests unitaires | Firmware reçoit mode cohérent UI ; tests verts | M |

### Phase 2 — Intégrité BDD & GPIO

| Ordre | ID | Titre | Description | Critères d'acceptation | Effort |
|------:|-----|-------|-------------|------------------------|--------|
| 1 | **P2-01** 🔒 | Exécuter migration S3 (Option A) | Script idempotent post P0-U02 ; vérif comptages | `ffp3DataS3` peuplée ; `ffp3Data4` prête pour DROP (P0-U07) | L |
| 2 | **P2-02** 🔒 | Harmoniser doc/migrations `*4` → `*S3` | `ENDPOINTS`, `migrations/README`, `TableValidator` | Grep `ffp3Data4` : historique ou absent | M |
| 3 | **P2-03** | GPIO 117 dans `Ffp3GpioMap` | ForcePompeAquarium server-only | Tests `OutputSyncServiceTest` verts | S |
| 4 | **P2-04** | GPIO 110 reset dans *GpioMap | Déplacer depuis templates MSP/N3PP | Templates lisent map | S |
| 5 | **P2-05** | Restreindre `allowedGpios` | Liste dérivée des maps ; exclure GPIO inter-famille | POST GPIO 16 sur N3PP → 400 | M |
| 6 | **P2-06** 🔒 | MSP sans pompe | **Supprimer GPIO 2** seed/prod ; **GPIO 111 = ServoModeAuto** (renommer prod « Pompe ») ; pas d'actionneur pompe MSP | Seed + BDD + UI cohérents ; firmware lit 111 config servo | M |
| 7 | **P2-07** | Dédupliquer GPIO 109 | Isolation par table/board ; corriger lignes croisées | Pas de sémantique FFP3 sur `n3ppOutputs` gpio 109 | M |
| 8 | **P2-08** | Stratégie double stockage config | Tableau champ → table canonique FFP3 vs MSP/N3PP | Tests POST ne désynchronisent pas UI | M |
| 9 | **P2-09** | Gouvernance boards 5/6 S3 | Inventaire appareils ; pas de collision POST | `inventaire_appareils.md` à jour | S |
| 10 | **P2-10** | Corriger `sync-import-staging-to-local.sql` | Mapper `tide*`, `post_id`, `ffp3Data4`→`ffp3DataS3` post-migration | Import local représentatif prod | M |
| 11 | **P2-11** | Élagage qualitatif niveau 2 | Post P0-U09 : actions Annexe B **2a–2b** (lectures DHT N3PP à 0/0, emails historiques aberrants) | Scripts idempotents ; pas de DELETE fallback FFP3 20/50 | M |

### Phase 3 — Dev local & CI

| Ordre | ID | Titre | Description | Critères d'acceptation | Effort |
|------:|-----|-------|-------------|------------------------|--------|
| 1 | **P3-01** | `gallerySyncSessions` init Docker | Ajouter au init si absent | `docker down -v && up` OK galerie | S |
| 2 | **P3-02** | `sensors_present` pglHeartbeat Docker | Colonne dans `90-poissonglouton.sql` | POST PGL stocke capteurs | S |
| 3 | **P3-03** 🔒 | Seed Docker aligné arbitrages | N3PP GPIO 12/13 ; MSP **sans GPIO 2** ; GPIO 111 ServoModeAuto ; FFP3 GPIO 117 | Seed idempotent ; pages contrôle OK | M |
| 4 | **P3-04** | Pipeline import dump documenté | `import-mysql-dump-to-local-docker.ps1` + sync P2-10 | Import réussi ; pages données réelles | M |
| 5 | **P3-05** | Tests heartbeat MSP/N3PP | Étendre suite intégration | `composer test` vert | M |
| 6 | **P3-06** | Tests GPIO maps & allowedGpios | Seed ⊆ maps ; pas de surplus | PHPStan + PHPUnit verts | S |

### Phase 4 — Documentation & dette

| Ordre | ID | Titre | Description | Effort |
|------:|-----|-------|-------------|--------|
| 1 | **P4-01** | Réécrire `ENDPOINTS_ESP32_SERVEUR.md` | S3 `*S3`, heartbeat, GPIO, HMAC, notifs Option B | M |
| 2 | **P4-02** | Tableau firmware ↔ serveur README | Versions, endpoints, GPIO résumé | S |
| 3 | **P4-03** | Fiche GPIO inter-familles pédagogique | 106/107/109, server-only | S |
| 4 | **P4-04** | ADR double stockage & legacy PHP | Plan dépréciation alias `.php` | S |
| 5 | **P4-05** | Inventaire appareils à jour | Post-intervention connectivité | S |

### Phase 5 — Optimisations & durcissement

| Ordre | ID | Titre | Description | Effort |
|------:|-----|-------|-------------|--------|
| 1 | **P5-01** | Rate-limit firmware prod | Mitiger double-POST N3PP | M |
| 2 | **P5-02** | HTTPS obligatoire firmwares | `N3PP_SERVER_SCHEME` / `MSP_SERVER_SCHEME` → https | M |
| 3 | **P5-03** | Dédup `post_id` FFP3 | Index UNIQUE + `HMAC_NONCE_REQUIRED` si prêt | M |
| 4 | **P5-04** | Filtrage valeurs aberrantes POST | Seuils température/humidité/niveaux | M |
| 5 | **P5-05bis** | Élagage qualitatif — gouvernance | Politique Annexe B : chronologie intacte, niveaux 0–2, niveau 3 **hors périmètre** ; pas de purge par date | Doc + scripts révision annuelle ; pas de DELETE TempAir=20/Humidite=50 | M |
| 6 | **P5-06** | Index perf prod | `reading_time`, `post_id`, `(board, gpio)` | M |

---

## 4. Matrice de dépendances (condensée)

```
P0-U01 ──► P0-U02, U03, U04, U05, U06, U07, U08, U09
P0-U02 ──► P2-01, P2-02, P2-10, P3-04, P0-U07
P0-U09 ──► P0-U08 (1a), P2-11
P2-01 ──► P0-U07
P0-U05 ──► P1-05, P2-07
P1-01/02 ──► P1-07, P3-05, P5-01
P1-09 ──► P4-01 (doc notifs)
P2-01 ──► P2-09, P3-04
P2-06 ──► P3-03
P2-10 ──► P3-04
P3-03 ──► P3-04 (import cohérent)
P4-01 ──► P4-02
P5-01 ──► P5-05bis
P0-U08 ──► P5-05bis
```

| Action | Bloque | Bloqué par |
|--------|--------|------------|
| P0-U02 | P2-01, P2-02, P2-10, P0-U07 | P0-U01 |
| P0-U09 | P0-U08 (action 1a), P2-11 | P0-U06 |
| P0-U07 | — | P2-01 |
| P2-11 | — | P0-U09 |
| P1-09 | P4-01 | — |
| P2-06 | P3-03 | — (arbitrage 🔒) |
| P2-10 | P3-04 | P0-U02 |
| P3-03 | P3-04 | P2-04, P2-05, P2-06 |

---

## 5. Top 15 prioritaire

1. **P0-U01** — Backup + diagnostic prod
2. **P0-U02** 🔒 — Migration `ffp3Data4` → `ffp3DataS3`
3. **P0-U07** 🔒 — DROP `ffp3Data4` post-migration (Annexe B niv. 0a)
4. **P0-U03** — Bundle `APPLY_PROD_AUDIT_2026.sql`
5. **P0-U05** 🔒 — Nettoyage GPIO (109 legacy, fantômes test3)
6. **P0-U09** — Comptage double-POST N3PP (prérequis élagage niv. 1–2)
7. **P0-U08** — Élagage qualitatif niveaux 0–1 (Annexe B)
8. **P0-U04** — Diagnostic MSP stale
9. **P1-09** 🔒 — `mailNotifValueForFirmware()` → GPIO 101 mode réel
10. **P1-01** — Heartbeat N3PP firmware
11. **P1-02** — Heartbeat MSP firmware
12. **P1-05** 🔒 — `etatPompe` ↔ GPIO 12 N3PP
13. **P2-01** — Migration S3 appliquée
14. **P2-11** — Élagage qualitatif niveau 2 (post comptage)
15. **P4-01** — Mise à jour `ENDPOINTS_ESP32_SERVEUR.md`

---

## 6. Checklist validation globale

- [ ] Backup prod daté et restaurable
- [ ] `99_validate_prod.sql` sans erreur bloquante
- [ ] 🔒 Migration S3 Option A appliquée (`ffp3DataS3` peuplée, comptages OK)
- [ ] 🔒 `ffp3Data4` supprimée post-migration (P0-U07 — export optionnel archivé)
- [ ] Élagage qualitatif niveaux 0–1 exécuté (P0-U08) — **pas** de purge par date sur `ffp3Data` / `n3ppData`
- [ ] Comptage double-POST N3PP documenté (P0-U09) avant élagage niveau 2 (P2-11)
- [ ] Fallback FFP3 `TempAir=20` / `Humidite=50` **conservés** en BDD (niveau 3 hors périmètre)
- [ ] 🔒 GPIO N3PP : pompe **12**, arrosage **13** ; GPIO 109 legacy supprimé
- [ ] 🔒 GPIO MSP : **pas de GPIO 2** ; GPIO **111** = ServoModeAuto (libellé prod corrigé)
- [ ] 🔒 GPIO 101 : mode notification réel exposé firmware ; 108/109 server-only
- [ ] Heartbeats N3PP et MSP : ≥ 1 ligne / 15 min en prod
- [ ] Pages `/serre`, `/meteo`, `/aquaponie` : données < 24 h (MSP sauf panne confirmée)
- [ ] GPIO actionneurs : UI toggle = état firmware terrain
- [ ] `allowedGpios` : GPIO inter-famille rejeté (HTTP 400)
- [ ] Firmwares : URLs sans `.php` ; versions bumpées
- [ ] Docker : `local-docker.ps1 smoke -AuthMode both` vert
- [ ] Import dump local : colonnes `tide*` + `post_id` présentes (post P2-10)
- [ ] `composer test` + `composer analyse` + `composer cs:check` verts
- [ ] `ENDPOINTS_ESP32_SERVEUR.md` aligné code déployé
- [ ] `VERSION` + `CHANGELOG` + inventaire appareils à jour
- [ ] Footer prod = version attendue (vérif déploiement CRON)

---

## 7. Synthèse effort par phase

| Phase | Actions | Dominant |
|-------|---------|----------|
| 0 — Urgences | **9** (+U07, U08, U09) | 2× L, 5× M, 2× S |
| 1 — Contrat P0 | **9** (+P1-09) | 2× L, 6× M, 1× S |
| 2 — BDD & GPIO | **11** (+P2-11) | 1× L, 7× M, 3× S |
| 3 — Docker & CI | 6 | 3× M, 3× S |
| 4 — Doc | 5 | 1× M, 4× S |
| 5 — Durcissement | 6 | 5× M, 1× S |
| **Total** | **41** | |
| ~~Phase D — Rétention temporelle~~ | **ANNULÉE** | Stratégie 12/24 mois rejetée → Annexe B |

---

## 8. Arbitrages — TRANCHÉS (05/07/2026)

> Les questions ouvertes du plan initial (§7) sont **closes**. Toute réouverture nécessite une décision explicite et une mise à jour de ce document.

### 8.1 S3 — Option A ✅ TRANCHÉ

| | |
|---|---|
| **Décision** | Migrer `ffp3Data4` (~779 k lignes) → `ffp3DataS3` ; conserver `TableConfig` (`env=s3` → `*S3`) |
| **Rejeté** | Option B (reconfig `TableConfig` vers `*4`) ; Option C (double-écriture) |
| **Actions** | P0-U02, P2-01, P2-02, P2-10, **P0-U07** |
| **Post-migration** | DROP `ffp3Data4` après vérif comptages (P0-U07 — Annexe B niv. 0a) ; export phpMyAdmin optionnel avant suppression |

### 8.2 GPIO actionneurs ✅ TRANCHÉ

| Famille | Décision |
|---------|----------|
| **N3PP** | Relais pompe sur **GPIO 12** (confirmé terrain) ; arrosage manuel **GPIO 13** ; supprimer GPIO **109** legacy « Arrosage manuel » |
| **MSP** | **Pas de pompe** ; supprimer GPIO **2** fantôme du seed/prod ; **GPIO 111 = ServoModeAuto** (renommer en prod le libellé erroné « Pompe ») |
| **Actions** | P0-U05, P1-05, P2-06, P3-03 |

### 8.3 Notifications — Option B ✅ TRANCHÉ

| GPIO | Rôle |
|------|------|
| **101** (`mailNotif`) | Mode réel firmware : `important`, `partial`, `full`, `none` via `mailNotifValueForFirmware()` |
| **108** (`notifMode`) | **Server-only** — réglage UI web uniquement |
| **109** (`notifCategories`) | **Server-only** — réglage UI web uniquement |
| **Actions** | P1-03, **P1-09** (nouveau), P4-01 |

### 8.4 Points secondaires (statut)

| Sujet | Statut | Note |
|-------|--------|------|
| MSP stale 40 j | **Ouvert opérationnel** | P0-U04 — intervention terrain possible avant dev |
| Élagage données | **Tranché** ✅ | Annexe B — élagage **qualitatif** ; historique intégral ; niveau 3 fallback FFP3 **non implémenter** |
| ~~Rétention temporelle 12/24 mois~~ | **ANNULÉE** ❌ | Phase D retirée — pas de DELETE par `reading_time` |
| `HMAC_STRICT_MODE` prod | **Ouvert** | Après P1-06 (URLs firmware) |
| Boards 5/6 S3 | **Ouvert** | P2-09 — inventaire à compléter |
| Double stockage config | **Ouvert** | P2-08 — formaliser, pas migrer vers FFP3 pour l'instant |

---

# Annexe A — Analyse volumétrie et pertinence des données

**Méthode** : grep, lecture partielle, script Python d'échantillonnage (parcours complet 3,74 M lignes en ~11 s).  
**Limite** : pas de stats exhaustives (NULL %, doublons `post_id`) sans import MySQL.

## A.1 Inventaire volumétrique (tables prioritaires)

| Table | ~Lignes (dernier id) | Période (reading_time) | Fraîcheur | Taille section dump |
|-------|----------------------|------------------------|-----------|---------------------|
| `ffp3Data` | **~1 035 574** | 2022 → **2026-07-05 15:43** | ✅ Actif | ~945 k lignes INSERT |
| `ffp3Data2` | **~154 864** | → 2026-06-23 | ⚠️ Test inactif 12 j | ~170 k lignes |
| `ffp3Data3` | **~7 361** | données anciennes | ⚠️ Board 4 peu actif | ~7 k lignes |
| `ffp3Data4` | **~779 471** | legacy FFP3 v3.9 | 🔴 Orpheline (hors TableConfig) | ~779 k lignes |
| `ffp3DataS3` / `S3Test` | **0** | — | 🔴 Vide (post-migration A) | 0 |
| `ffp3DataDel` | **~16 000** | archive suppressions | 📦 Archive | ~16 k lignes |
| `ffp3Heartbeat` | **~150 763** | → 2026-07-05 15:39 | ✅ Actif | ~19 k lignes |
| `ffp3Outputs` | 27 | config | ✅ Propre | < 1 k |
| `n3ppData` | **~1 858 087** | 2023 → **2026-07-05 15:57** | ✅ Actif | ~1,87 M lignes |
| `n3ppDataOld` | **~16 500** | pré-migration | 📦 Archive | ~16 k lignes |
| `n3ppHeartbeat` | **0** | — | 🔴 Vide (firmware absent) | structure seule |
| `n3ppOutputs` | 15 | config | ⚠️ GPIO 109 doublon | < 1 k |
| `msp1Data` | **~16 295** | → **2026-05-26 12:57** | 🔴 Stale ~40 j | ~16 k lignes |
| `msp1Heartbeat` | **0** | — | 🔴 Vide | structure seule |
| `msp1Outputs` | 13 | config | ⚠️ GPIO 109 doublon, 111 « Pompe » | < 1 k |

## A.2 Pertinence pédagogique par famille

### FFP3 (aquaponie) — ratio signal/bruit : **~75 % utile / 25 % bruit**

| Signal (utile cours/stats) | Bruit |
|----------------------------|-------|
| Séries eau (niveaux, température) récentes complètes | `TempAir=20`, `Humidite=50` constants (fallback DHT dégradé) |
| Marée active (`tideEvent`, `diffMaree`) v15.0 | NULL massifs 2022 (déploiement progressif) |
| `post_id` HMAC récent | `bootCount` souvent NULL |
| Heartbeat ~151 k (supervision) | `ffp3Data4` 779 k lignes **invisibles** côté serveur |

### N3PP (serre) — ratio signal/bruit : **~55 % utile / 45 % bruit**

| Signal | Bruit |
|--------|-------|
| Température/humidité/luminosité récentes | **Double-POST** (~2× volumétrie) : paires à 1–2 s identiques |
| `etatPompe`, seuils, config récents | `TempAir=0, Humidite=0` ponctuels (échec lecture) |
| Historique long pour tendances | 35 premières lignes `sensor='msp1'` (pollution early-stage 2023) |
| | Typo email historique (`gmailsdfg`) |

### MSP1 (météo) — ratio signal/bruit : **~40 % utile / 60 % bruit ou stale**

| Signal | Bruit |
|--------|-------|
| Archives 2023–2025 exploitables | **Aucune donnée depuis 26/05/2026** |
| Température intérieure parfois OK | `LuminositeMoy=0` constant ; `PontDiv` NULL fréquent |
| | Colonnes C/D jamais alimentées ; schéma legacy permuté |
| | Heartbeat vide → pas de supervision |

## A.3 Tables orphelines et pollution

| Élément | Nature | Recommandation import local |
|---------|--------|----------------------------|
| `ffp3Data4` | 779 k lignes FFP3 v3.9, hors `TableConfig` | **Ne pas importer** — migrer vers `ffp3DataS3` en prod d'abord |
| `ffp3DataDel` | 16 k suppressions | **Ignorer** en local |
| `n3ppDataOld` | 16 k pré-migration | **Ignorer** (sauf étude historique) |
| `ffp3Outputs3` gpio 16 NULL | ~400 fantômes test3 | **Purger** avant usage test3 |
| `ffp3Outputs_backup_*` | Backup migration | **Ignorer** |
| Heartbeats msp/n3pp vides | Schéma sans flux | Seed synthétique suffit |
| `ffp3DataS3*` vides | Env S3 non déployé | Données synthétiques post-migration A |

## A.4 Impact import local : 719 Mo vs seed minimal

| Critère | Dump complet (719 Mo) | Seed Docker (~10 lignes) |
|---------|----------------------|--------------------------|
| Temps import | 15–45 min (SSD, MySQL 8) | < 1 min (`docker up`) |
| Espace disque MySQL | ~1,2–1,5 Go indexés | ~50 Mo |
| Graphiques historiques | ✅ ~3 M points (avec pertes sync) | ❌ 1 point/table |
| Colonnes `tide*` / `post_id` | ⚠️ Perdues via `sync-import` actuel | Seed partiel |
| GPIO cohérents post-arbitrage | ⚠️ Prod incohérente (109, 111) | ✅ Seed 6.7.2+ aligné N3PP 12/13 |
| Pertinence pédagogique | Bruit N3PP double-POST, FFP3 fallback | Propre, minimal |

**Recommandation dev local** :

- **Par défaut** : seed Docker (smoke, contrôle, tests PHPUnit)
- **Import dump** : uniquement pour debug graphiques/perf ; après P2-10 (sync corrigé) ; sous-ensemble possible pour accélérer l'import local (**confort dev**, pas politique prod)

## A.5 Tables à ne PAS importer en dev local

| Table | Raison |
|-------|--------|
| `ffp3Data4` | Orpheline — sera migrée vers `ffp3DataS3` |
| `ffp3DataDel` | Archive suppressions |
| `n3ppDataOld` | Archive pré-migration |
| `ffp3Outputs_backup_*` | Backup ponctuel |
| `notification_digest` / `notification_log` | Ops, volume faible mais inutile en classe |
| `error_alerts` | Ops serveur |
| `ffp3Heartbeat` (> 6 mois) | 151 k lignes — échantillon 1 000 dernières **en import local uniquement** (prod : intégral) |
| `n3ppData` doublons double-POST | Réduire bruit via élagage qualitatif Annexe B 1a (prod : pas de troncature par date) |

## A.6 Lien vers la stratégie de nettoyage

La politique de **rétention temporelle** (12/24 mois) initialement proposée est **annulée**.
Voir **Annexe B — Élagage qualitatif des données** pour la stratégie retenue (chronologie intacte, suppression du bruit uniquement).

---

# Annexe B — Élagage qualitatif des données (juillet 2026)

> **Décision** : conserver l'historique complet depuis l'origine des tables actives. Supprimer uniquement les données **qualitativement inutiles** (orphelines, archives, doublons avérés, lectures capteur invalides). **Aucun DELETE par date** sur `ffp3Data`, `n3ppData`, `msp1Data`.

## B.1 Philosophie

| Principe | Application |
|----------|-------------|
| **Chronologie intacte** | Pas de trou temporel dans les séries exploitées en cours |
| **Du plus inutile au moins sûr** | Pyramide niveaux 0 → 2 ; validation backup avant chaque vague |
| **Filtrage ≠ suppression** | Bruit signalé mais conservé (ex. fallback DHT FFP3) → filtre affichage/stats (P0-U06, P5-04) |
| **Mesurer avant de couper** | Comptage double-POST (P0-U09) avant DELETE niveau 1a / 2 |

## B.2 Pyramide des niveaux

| Niveau | Nature | Risque pédagogique | Statut |
|--------|--------|-------------------|--------|
| **0** | Tables orphelines / archives déjà hors runtime | Nul (données invisibles ou redondantes) | ✅ À exécuter |
| **1** | Bruit structurel avéré (doublons POST, pollution early-stage, fantômes GPIO) | Faible | ✅ À exécuter (post comptage pour 1a) |
| **2** | Lectures capteur invalides ponctuelles (DHT 0/0 N3PP, emails aberrants) | Faible à moyen | ✅ Après P0-U09 (P2-11) |
| **3** | Fallback FFP3 `TempAir=20` + `Humidite=50` (DHT dégradé) | **Élevé** si DELETE | ⛔ **HORS PÉRIMÈTRE / NON IMPLÉMENTER** — conserver en BDD |

## B.3 Actions ordonnées

| ID | Niveau | Cible | Action | Gain estimé | Action plan |
|----|--------|-------|--------|-------------|-------------|
| **0a** | 0 | `ffp3Data4` (+ outputs4/heartbeat4) | DROP post-migration Option A | ~300 Mo | **P0-U07** |
| **0b** | 0 | `ffp3DataDel` | Export optionnel puis DROP | ~5 Mo | **P0-U08** |
| **0c** | 0 | `n3ppDataOld` | Export optionnel puis DROP | ~5 Mo | **P0-U08** |
| **0d** | 0 | `ffp3Outputs_backup_*`, fantômes `ffp3Outputs3` gpio 16 NULL | DELETE lignes orphelines | négligeable | **P0-U08**, P0-U05 |
| **1a** | 1 | `n3ppData` double-POST | DELETE doublon (garder 1 ligne/paire à Δt ≤ 2 s, champs identiques) | ~350 Mo | **P0-U08** (post **P0-U09**) |
| **1b** | 1 | `n3ppData` early-stage | DELETE lignes `sensor='msp1'` (pollution 2023) | ~35 lignes | **P0-U08** |
| **1c** | 1 | `ffp3Outputs3` | DELETE gpio 16 NULL (~400 fantômes) | négligeable | **P0-U08**, P0-U05 |
| **2a** | 2 | `n3ppData` | DELETE `TempAir=0 AND Humidite=0` (échec lecture DHT) | ~10–20 Mo | **P2-11** |
| **2b** | 2 | `n3ppData` | DELETE emails historiques aberrants (`gmailsdfg`, etc.) | faible | **P2-11** |
| **3** | 3 | `ffp3Data` fallback 20/50 | **Aucun DELETE** — filtrer graphiques/stats si besoin | — | ⛔ **NON IMPLÉMENTER** |

**Gain total estimé** : **~200–280 Mo** sur dump 719 Mo, **sans trou chronologique** sur les séries actives.

> ⚠️ **Pas de script SQL DELETE niveau 3** : les lignes fallback FFP3 restent en base ; le filtrage eventuel se fait côté application (P5-04, requêtes stats).

## B.4 Ce qu'on ne touche pas

| Élément | Raison |
|---------|--------|
| `ffp3Data` intégral (2022 → présent) | Historique pédagogique long terme ; inclut fallback 20/50 |
| `n3ppData` hors doublons/bruit niv. 1–2 | Tendances saisonnières, comparaisons inter-années |
| `msp1Data` intégral | Faible volume (~16 k) ; réactivation MSP possible |
| `ffp3Heartbeat` intégral | Supervision ; pas de purge par date |
| `ffp3Data2` / `ffp3Data3` (env. test) | Environnements test — pas d'élagage prod sans décision explicite |
| Colonnes NULL massives 2022 FFP3 | Témoin déploiement progressif — conserver |

## B.5 Calendrier simplifié (S0–S4)

| Semaine | Contenu | Actions |
|---------|---------|---------|
| **S0** | Backup + migration S3 | P0-U01, P0-U02, P2-01 |
| **S1** | Niveau 0 (orphelines) | P0-U07 (0a), P0-U08 (0b–0d) |
| **S2** | Mesure + niveau 1 | P0-U09, P0-U08 (1a–1c) ; P5-01 rate-limit (prévention) |
| **S3** | Niveau 2 | P2-11 (2a–2b) |
| **S4** | Gouvernance + doc | P5-05bis, P5-04 filtrage affichage ; révision Annexe B |

## B.6 Comparatif stratégies

| Critère | Ancienne (rétention 12/24 mois) — **ANNULÉE** | Nouvelle (élagage qualitatif) — **RETENUE** |
|---------|-----------------------------------------------|---------------------------------------------|
| Principe | DELETE par `reading_time` | DELETE par critère qualitatif |
| Historique `ffp3Data` / `n3ppData` | Tronqué (12–24 mois) | **Intégral depuis l'origine** |
| Trou chronologique | Possible (sauts de mois) | **Non** |
| `ffp3Data4` | Purge post-migration | DROP niv. 0a (données migrées vers `ffp3DataS3`) |
| Fallback FFP3 20/50 | Potentiellement supprimé | **Conservé** (filtrage UI/stats) |
| Gain estimé dump | ~400–500 Mo | ~200–280 Mo |
| Risque perte pédagogique | Élevé (tendances long terme) | Faible |
| Phase plan | ~~Phase D — rétention temporelle~~ | P0-U07/U08/U09, P2-11, P5-05bis |

## B.7 Phase D — Rétention temporelle : ANNULÉE

La **Phase D** (purge glissante 12 mois `n3ppData`, 24 mois `ffp3Data`, 6 mois `ffp3Heartbeat`) est **retirée du plan**.
Toute action impliquant un `DELETE … WHERE reading_time < …` est **hors périmètre**.

**Actions plan liées** : P0-U07, P0-U08, P0-U09, P2-11, P5-05bis, P0-U06 (filtrage stats), P2-10 (import local), P5-01 (prévention double-POST).

---

*Document mis à jour le 05/07/2026 (v6.7.4). Prochaine étape : exécuter la séquence Top 15 §5 en commençant par P0-U01 (backup), P0-U02 (migration S3) et P0-U07 (DROP `ffp3Data4`).*
