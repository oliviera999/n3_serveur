# Compatibilité de la flotte déployée (rollbacks serveur)

> **Contexte.** Les modules sur le terrain ne peuvent pas être reflashés à court terme
> (pas d'OTA ni de flash prévu avant plusieurs semaines). Tout changement de contrat
> serveur doit donc être **réversible depuis le serveur seul**, et son comportement par
> défaut doit être celui que la flotte déployée sait déjà encaisser.
>
> Ce document recense les manettes de rollback disponibles, l'état d'origine et les
> conditions pour les rebasculer.

## Versions déployées (référence de cet audit — juillet 2026)

| Module | Firmware déployé | Dépôt / source de version | État |
|--------|------------------|---------------------------|------|
| n3pp (serre/élevage) | **4.57** (2026-07-14) | `n3pp/include/n3pp_config.h` | plus aucune donnée reçue |
| ffp5cs (aquaponie, FFP3) | **15.15** (2026-07-09) | `ffp5cs/VERSION.md` | données OK, faussement « silencieux » |
| uploadphotosserver (galerie ffp3) | **2.64** | `uploadphotosserver/include/config.h` | OK |
| msp1 (station météo) | ≤ 2.55 | `msp/include/msp_config.h` | même contrat que n3pp (voir ci-dessous) |

## 1. Clés plates de config n3pp/msp — `FIRMWARE_FLAT_STATE_MODE`

### Le contrat

Les firmwares n3pp/msp interrogent `GET /{module}/api/outputs/state` et lisent la config
sous forme de **clés plates à la racine** de la réponse (`myObject["107"]`,
`myObject["112"]`, `myObject["12"]`…) — pas dans le tableau `outputs` (format *nested*).

Jusqu'à la **v6.25.0** (commit `774cb76`, 19/07/2026), le serveur ne renvoyait que le
format nested : **aucune** clé plate. Conséquence — bien réelle mais silencieuse — la
flotte ignorait la config serveur et tournait sur ses valeurs locales, chaque lecture
retombant sur son défaut (`readIntByKey(myObject, "12", 0)` → `0`).

La v6.25.0 a fusionné les clés plates à la racine (correctif « C1/C2 »). Effet immédiat :
**toute la flotte adopte d'un coup la configuration stockée en BDD**, qui n'avait jamais
été confrontée au terrain.

### Les deux effets de bord qui rendent un module muet

1. **Boucle de rétroaction sur l'état pompe (n3pp, GPIO 12).**
   À chaque POST, le serveur recopie la mesure `etatPompe` dans la ligne de **commande**
   GPIO 12 (`N3ppPostDataController::insertData`). Dès que cette ligne vaut `1`, le
   firmware la relit en clé plate et exécute `digitalWrite(POMPE, 1)` à chaque réveil
   (`n3pp/src/n3pp_network.cpp`, `variablestoesp()`), avant même la logique d'arrosage.
   La pompe reste alimentée en continu, le firmware re-poste `etatPompe=1`, la ligne
   BDD reste à `1` : **la boucle s'auto-entretient**. Batterie vidée + arrosage permanent
   → le module finit par ne plus émettre, avec toutes les apparences d'une panne
   d'alimentation.
2. **Config appliquée sans garde-fou.** `SeuilPontDiv` (GPIO 103) est lu **sans bornage**
   par le firmware (`SeuilPontDiv = readIntByKey(myObject, "103", SeuilPontDiv)`), et le
   champ correspondant de l'interface de contrôle n'a pas de maximum. Une valeur haute
   rend la condition « batterie faible » toujours vraie ; combinée à `veilleInfinie`
   (GPIO 112, **défaut 1**), elle envoie le module en **veille GPIO-only** : plus aucune
   donnée, aucun réveil timer, récupération uniquement par action physique.
   Dans une moindre mesure : `FreqWakeUp` (107) à 86400 = un POST par jour ; `resetMode`
   (110) latché à 1 = redémarrage ; côté msp, `ServoHB`/`ServoGD` (104/105) appliqués en
   mode manuel.

### La manette

Réglage opérationnel `FIRMWARE_FLAT_STATE_MODE` (supervision → Maintenance, ou `.env`) :

| Mode | Comportement | Quand |
|------|--------------|-------|
| `off` **(défaut)** | Aucune clé plate fusionnée → la flotte conserve sa config locale. **Pas d'acquittement one-shot** sur `/api/outputs/state` (un ack sans clés plates mangeait reset/arrosage manuel). Les commandes one-shot restent en attente jusqu'à `safe`/`full` ou un GET sur `/api/firmware/outputs/state`. | Tant que les modules ne sont pas reflashés, ou en cas de doute sur les valeurs en BDD. |
| `safe` | Clés plates fusionnées **après nettoyage** : GPIO d'état miroir retirés (n3pp 12), valeurs de config hors plage omises — le firmware garde alors sa valeur locale et journalise `[SERVER][GET][WARN] Cle … absente`. Ack one-shot effectué. | Après avoir vérifié les valeurs en BDD (voir la checklist ci-dessous), pour retrouver le pilotage distant sans risque de brique logicielle. |
| `full` | Comportement v6.25.0 tel quel, aucun filtrage. Ack one-shot effectué. | Flotte flashée avec des firmwares qui bornent eux-mêmes les valeurs reçues. |

Le nettoyage `safe` s'applique **aussi** à la route firmware dédiée
`GET /{module}/api/firmware/outputs/state`, qui elle reste toujours servie (le mode `off`
ne la vide pas : c'est une route explicite, pas la fusion litigieuse).

Bornes appliquées en mode `safe` (`App\Service\FirmwareStateCompat`) :

| GPIO | Paramètre | Plage admise | Hors plage → |
|------|-----------|--------------|--------------|
| 102 | SeuilSec | 0–4095 (ADC 12 bits) | clé omise |
| 103 | SeuilPontDiv | 0–4095 | clé omise |
| 104 | HeureArrosage (n3pp) / ServoHB (msp) | 0–23 / 0–180 | clé omise |
| 105 | tempsArrosage (n3pp) / ServoGD (msp) | 1–20 / 0–180 | clé omise |
| 106 | WakeUp | 0–1 | clé omise |
| 107 | FreqWakeUp | 1–86400 s | clé omise |
| 110 | resetMode | 0–1 | clé omise |
| 111 | ServoModeAuto (msp) | 0–1 | clé omise |
| 112 | veilleInfinie | 0–1 | clé omise |
| 12 | etatPompe (n3pp) — **état mesuré, pas une commande** | — | clé retirée |

Les clés non répertoriées (actionneurs, GPIO d'un firmware plus récent) passent
inchangées : le garde-fou borne le risque connu, il ne réécrit pas le contrat.

### Checklist avant de repasser en `safe`

```sql
-- Valeurs qui pilotent le sommeil et la pompe (board 3 = n3pp)
SELECT gpio, name, state FROM n3ppOutputs
 WHERE board = 3 AND gpio IN (12, 13, 102, 103, 106, 107, 110, 112)
 ORDER BY gpio;

-- Dernière tension batterie mesurée : SeuilPontDiv DOIT rester nettement en dessous
SELECT reading_time, PontDiv, etatPompe FROM n3ppData ORDER BY id DESC LIMIT 5;
```

- `12` doit être à `0` avant tout passage en `safe`/`full` (sinon la pompe repart au
  premier réveil) ;
- `103` (SeuilPontDiv) doit être **inférieur** au `PontDiv` mesuré en fonctionnement
  normal, sinon la veille infinie se déclenche à chaque réveil ;
- `107` (FreqWakeUp) : valeur en **secondes** ;
- `110` (resetMode) doit être à `0`.

## 2. Alerte « appareil silencieux » — plancher + contre-preuve données

`DeviceHealthService` surveille la table **heartbeat** de chaque famille. Or heartbeat et
POST de mesures sont deux flux indépendants et de cadences très différentes : côté ffp5cs
le heartbeat part au plus une fois par cycle de réveil, en *fire and forget* (pas de file
de reprise, horodatage avancé même en cas d'échec, envoi déclenché pendant la fenêtre de
reconnexion WiFi), là où les mesures partent toutes les 30 s en éveil avec file de reprise
et rejeu SD.

Depuis la v6.19, le seuil de silence était dérivé de la veille (`FreqWakeUp × 2 + 60`,
≈ 21 min en journée) au lieu du forfait historique de 3600 s : **un seul heartbeat perdu
suffisait à déclarer silencieux un module dont les données arrivaient normalement**
(la v6.25.1 n'avait corrigé que le cas nocturne).

Deux garde-fous désormais :

1. **Contre-preuve** : si la dernière **mesure** est fraîche (même seuil), aucune alerte
   n'est émise — un module qui POSTe n'est pas silencieux. Lecture en erreur = ignorée
   (fail-safe : la contre-preuve ne peut jamais masquer une vraie panne).
2. **Plancher** : `HEARTBEAT_OFFLINE_THRESHOLD_SECONDS` (défaut 3600 s) est un minimum ;
   le seuil dérivé de la veille ne peut que l'**allonger** (facteur nuit FFP3 : 5460 s).

## 3. Ce qui n'a PAS été touché

- **Authentification** : le contrat HMAC (`X-Sig-*` sur le corps, repli `api_key`) et la
  politique `HMAC_STRICT_MODE` / `HMAC_NONCE_REQUIRED` restent inchangés. Aucun rejet 401
  n'a été introduit côté n3pp/msp/ffp3 par les versions récentes.
- **Rate limiting firmware** : `FIRMWARE_RATE_LIMIT_MAX` reste à `0` (désactivé) par
  défaut ; le rate-limit par IP ne s'applique qu'à `/login`.
- **Galerie caméra** (`uploadphotosserver` 2.64) : endpoints d'upload/sync inchangés.
