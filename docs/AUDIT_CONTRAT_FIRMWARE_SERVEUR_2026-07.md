# Audit bout-en-bout du contrat FIRMWARE ↔ SERVEUR (2026-07)

Écosystème IoT « salle aérée n³ » — dépôts `n3_serveur` (ce dépôt) et `n3_firmwires`.
Audit croisé, preuves `fichier:ligne` des deux côtés. Fait suite à
`n3_firmwires/docs/AUDIT_GENERAL_2026-07.md` §7.

> **Statut de ce document :** rapport + **plan par étapes**. La phase 1 (safe /
> non bloquante) est **implémentée** dans le PR courant ; le reste est **documenté
> pour une prochaine session** (nécessite coordination firmware, banc de test, ou un
> changement de comportement de la flotte). **Aucune activation bloquante** (pas
> d'enforcement de signature, pas de retrait du chemin legacy) tant que la flotte
> ne peut pas être re-flashée.

---

## Tableau des écarts (priorisé)

| ID | Sévérité | Point | Écart (serveur ↔ firmware) | Statut |
|----|----------|-------|-----------------------------|--------|
| **O1** | CRITICAL | OTA signature | ffp5cs `ota/metadata.json` = **md5 seul** ; **pgl sans cible OTA**. Aucune authenticité crypto. | ⏳ documenté (train C) |
| **H1** | HIGH | POST HMAC | Serveur accepte la paire legacy `timestamp=&signature=` (signe l'epoch seul) → **forge de corps** ≤ 300 s. | ⏳ différé (firmware d'abord) |
| **C1** | HIGH | Config GET | n3pp/msp lisent des **clés plates** ; l'endpoint appelé renvoie du **nested** → config **jamais appliquée**. | ⏳ différé (fix additif serveur, cf. §Config) |
| **C2** | HIGH | Config GET | One-shots (reset 110, bouffe 108/109) **acquittés/effacés** alors que jamais appliqués. | ⏳ résolu par C1 |
| **O2** | HIGH | OTA signature | `N3_OTA_REQUIRE_SIGNATURE` **défini nulle part** → même les cibles signées restent fail-open. | ⏳ train A (banc requis) |
| **O3** | HIGH | OTA TLS | `DEFAULT_BASE` sert les cibles **signées en `http://`** (`publish_ota.py:57`). | ✅ **corrigé** (tooling → https) |
| **C3** | MED/HIGH | Config GET | Réponse config **non signée** + TLS non validé → MITM réécrit la config. | ⏳ différé (firmware doit vérifier) |
| **H2** | MEDIUM | POST HMAC | Aucune **dédup par nonce** ; rejeu ≤ 300 s hors FFP3-data ; `api_key` seul accepté. | ⏳ différé |
| **O4** | MEDIUM | Versions OTA | Toutes les cibles OTA **périmées** ; pgl absent. | ⏳ à republier (flotte gelée) |
| **D1** | MEDIUM | OTA doc | Clé publique **réellement P‑521**, tous les commentaires disent « P‑256 ». | ✅ **corrigé** (commentaires) |
| **L4** | LOW | Traversée | OTA handler : durcissement `realpath()` (défense en profondeur). | ✅ **corrigé** |
| **rollback** | — | Récupération | Aucune ergonomie de retour arrière côté serveur. | ✅ **implémenté** (`bin/ota-rollback.php`) |
| **L1‑L3** | LOW | Champs | Orphelins : `Pression` (lu, non émis), `VeilleInfinie` (émis, ignoré), extras pgl. | ⏳ nettoyage doc |

---

## Détail des preuves

### OTA — architecture (points 1, 2, 6)
- Le serveur est un **serveur de fichiers statiques** : `public/index.php:229-240` → `src/Controller/Ffp3/OtaFileController.php` (zéro crypto). Il ne signe ni ne hashe jamais.
- La signature est produite **hors-ligne** dans le dépôt firmware : `n3_firmwires/tools/ota/publish_ota.py:94-97` (`openssl dgst -sha256 -sign`, requiert `--key`), schéma **n3ota** (n3pp/msp/cam/pgl). Le schéma **ffp5** (`publish_ffp5`) n'émet **que md5**.
- Métadonnées servies : `ota/n3pp*/metadata.json`, `ota/msp*/metadata.json`, `ota/cam/metadata.json` portent `sha256`+`signature` ; **`ota/metadata.json` (ffp5cs wroom+s3) n'a que `md5`** ; **aucun `ota/pgl/`**.
- **Courbe (vérifié `openssl`) :** la clé embarquée `n3_firmwires/shared/n3_common/src/n3_ota_pubkey.h:35-41` est **secp521r1 / P‑521** ; les signatures serveur sont P‑521 → **elles correspondent**. `mbedtls_pk_verify` (`n3_ota.cpp:104`) valide contre la courbe parsée → l'enforcement ne briquera pas n3pp/msp/cam pour cause de courbe. Les commentaires « P‑256 » sont **faux** (D1).
- Garde d'enforcement firmware : `n3_ota.cpp:331` `#if defined(N3_OTA_REQUIRE_SIGNATURE)` — **jamais défini** (0 build_flag) → fail-open sha256-only.
- TLS : `publish_ota.py:57` `DEFAULT_BASE` → `http://` pour n3ota (corrigé). `docs/OTA_N3PP_MSP.md:135` documente « MITM acceptable tant que sha256+ECDSA actif » — **prémisse fausse** aujourd'hui.
- Versions servies vs firmware courant : n3pp 4.55<**4.63**, msp 2.55<**2.66**, cam 2.64<**2.70**, ffp5cs 15.15/s3 15.09<**15.20**, pgl **absent**.

### POST données — HMAC / anti-rejeu (point 3)
- ✅ HMAC du **corps complet** vérifié : `src/Security/SignatureValidator.php:43-46` (`ts."\n".nonce."\n".body`), via `Ffp3/PostDataController.php:227-310`, `Concerns/HmacAuthTrait.php:53-104`, `PglHmacAuthTrait.php`.
- 🔴 **Legacy epoch-only encore accepté** : `SignatureValidator.php:27-30,56-70` (signe l'epoch seul), en fallback `PostDataController.php:75-162`, `HmacAuthTrait.php:106-194`. Firmware émetteur : `shared/n3_data/src/n3_data.cpp:95-108` → **n3pp, msp, pgl, upload**. **ffp5cs est déjà X‑Sig-only** (`web_client.cpp:238-255`).
- Fenêtre : **300 s** (`SIG_VALID_WINDOW`, `SignatureValidator.php:64`). **Pas de dédup nonce** (jamais persisté). Dédup `post_id` **FFP3-data uniquement** (`SensorRepository.php:161-164`). `api_key` seul accepté (`AbstractPostDataController.php:198-216`). Mode strict `HMAC_STRICT_MODE` off par défaut, global, **n'interdit pas** le legacy forgeable (`HmacPolicyTrait.php:16-26`).

### Config distante GET (point 4)
- 🔴 **C1** — firmware appelle `…/api/outputs/state` (nested) `n3pp_globals.cpp:114`, `msp_globals.cpp:102` ; lit des **clés plates** `n3pp_network.cpp:183-186`. Serveur renvoie `{timestamp,outputs:[…]}` `AbstractRealtimeApiController.php:74-77`. Le bon endpoint plat existe : `…/api/firmware/outputs/state` → `getState` (`routes_helpers.php:196`, `AbstractOutputRepository.php:55-89`).
- 🔴 **C2** — `maybeAcknowledgeFirmwareOneShots` (`AbstractRealtimeApiController.php:87-108`) efface reset/bouffe si `X-Api-Key` présent (envoyé par le firmware) → commandes avalées.
- 🟢 **ffp5cs cohérent** : `OutputCacheService.php:119-123` duplique par **nom** via `Ffp3GpioMap` → parseur nommé ffp5cs OK.
- 🟠 **C3** — routes config sans auth ni signature (`routes_helpers.php:44-54`), TLS `setInsecure()` → MITM réécrit ; pas de revalidation de plage en sortie.

### Contrat de champs (point 5)
- 🟢 Cœurs de payload cohérents par nom (4 familles) ; nomenclature `sensor="ffp3"` cohérente par route.
- 🟢 **L1** `Pression` lu (`Ffp3/PostDataController.php`), jamais émis → NULL. **L2** `VeilleInfinie` émis (`msp_network.cpp:45`, `n3pp_network.cpp:39`), ignoré. **L3** pgl `location/total_count/…` émis, ignorés.

### Injection / traversée (point 7)
- ✅ Aucune faille exploitable. OTA handler bloque `..` (`OtaFileController.php:45`) **+ nouveau contrôle `realpath()`** (L4). Galeries whitelist+regex. SQL préparé + `TableConfig`/`TableValidator`. Dates `DateTimeImmutable::format()` + paramètres liés.

---

## Ce qui est fait dans le PR courant (phase 1 — safe, non bloquant)

1. **Système de rollback OTA serveur** : `src/Service/OtaRollbackService.php`, CLI `bin/ota-rollback.php`, tests, doc `docs/OTA_ROLLBACK.md`. Snapshot / liste / restauration atomique avec auto-backup. **Prérequis récupération** avant tout enforcement.
2. **Durcissement OTA handler (L4)** : contrôle `realpath()` sous base (anti-symlink).
3. **O3 (tooling firmware)** : `publish_ota.py` sert désormais les cibles n3ota en **https** + **archive-on-publish** (alimente l'historique de rollback).
4. **D1 (commentaires firmware)** : correction « P‑256 » → **P‑521** (aucun changement de clé ni de logique).
5. **Docs de plan** : ce document + `n3_firmwires/docs/OTA_SIGNATURE_ENFORCEMENT_PLAN.md`.

---

## Séquence de déploiement SÛRE — enforcement signature OTA (prochaines sessions)

Prérequis absolu : **la flotte doit pouvoir être re-flashée** (ce n'est pas le cas
aujourd'hui). Deux « trains » indépendants ; `N3_OTA_REQUIRE_SIGNATURE` ne couvre
que la **pile partagée** (n3pp/msp/cam/pgl) ; **ffp5cs a sa propre pile** (train C).

**Train A — n3pp / msp / cam (clé P‑521 déjà appariée) :**
1. Serveur signe **systématiquement** : republier les cibles aux versions courantes avec `publish_ota.py --key` ; **automatiser** via CI (`firmware-ota-deploy.yml`, clé privée en secret). Snapshoter (`bin/ota-rollback.php --snapshot`) l'état sain **avant**.
2. **Banc** : device témoin avec `-DN3_OTA_REQUIRE_SIGNATURE` → accepte une MAJ signée, **refuse** une MAJ non signée/altérée. Ajouter le test natif Unity de `verifyFirmwareSignature` (dette S4).
3. Activer `-DN3_OTA_REQUIRE_SIGNATURE` (build_flags), bumper, rollout **progressif** — jamais avant (1)+(2) verts pour la cible.

**Train B — pgl :** publier d'abord la cible pgl signée (`publish_ota.py --firmware pgl --key`), banc, **puis** activer le flag. Activer sans cible publiée = pas de MAJ possible.

**Train C — ffp5cs :** (i) faire émettre sha256+signature au schéma `ffp5` (ou migrer ffp5cs sur `shared/n3_ota`) ; (ii) faire vérifier l'ECDSA par sa pile ; (iii) publier+banc ; (iv) `OTA_REQUIRE_SIGNATURE=true`. **Tant que (i)-(iii) non faits : ne pas activer (brique l'OTA).**

**Transverse (non bloquant, parallèle) :**
- HMAC : migrer n3pp/msp/pgl/upload en X‑Sig-only (retirer `n3_data.cpp:95-108`), confirmer via `HmacAuditLogger` l'absence de legacy, puis flag serveur « corps-signé requis » + suppression `createSignature`/`isValid`.
- **C1/C2** : correctif possible **sans re-flash** = ajouter les clés plates à la réponse `getOutputsState` (additif) **ou** router le firmware vers `/api/firmware/outputs/state`. ⚠️ **Changement de comportement flotte** (les devices se mettent soudain à appliquer la config stockée : email, resetMode, FreqWakeUp…) → à valider explicitement avant activation.
- C3 : signer la réponse config (en-tête `X-Sig-*` vérifié firmware) + livrer le bundle CA (pinning).
- O4 : republier toutes les cibles aux versions courantes (flotte dégelée).
