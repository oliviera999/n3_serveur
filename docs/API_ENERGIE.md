# API serveur — banc énergie INA226 (famille `energie`)

Contrat HTTP entre le firmware **`energie`** (dépôt n3_firmwires, dossier `energie/`,
ESP32-S3 + 3 × INA226) et le serveur Slim 4. Disponible depuis le serveur **6.40.0**.

Le banc mesure trois canaux INA226 :

| Canal | Rôle | Convention |
|-------|------|------------|
| **Panneau** | Production du panneau solaire | courant ≥ 0 |
| **Batterie** | Batterie plomb/AGM 12 V, **bidirectionnelle** | courant **> 0 = charge**, **< 0 = décharge** (idem `BatterieP`) |
| **Conso** | Consommation des périphériques | courant ≥ 0 |

Cadence : mesure **toutes les secondes**, **POST agrégé toutes les 10 s**.
Famille « mesure seule » : **pas** de table outputs (GPIO), **pas** d'endpoint heartbeat,
pas d'alertes. Le banc actuel écrit en **TEST** ; la prod est réservée au futur module réel.

---

## 1. Vue d'ensemble

| Domaine | Prod | Test (banc) |
|---------|------|-------------|
| Env Slim (`TableConfig`) | `prod` | `energie_test` (`isTest()` = true) |
| Préfixe URL | `/energie` | `/energie-test` |
| Table BDD | `energieData` | `energieDataTest` |
| Page publique | `GET /energie` | `GET /energie-test` |
| POST firmware | `POST /energie/post-data` | `POST /energie-test/post-data` |
| API temps réel | `/energie/api/realtime/*` | `/energie-test/api/realtime/*` |

Code : [`EnergiePostDataController`](../src/Controller/Energie/EnergiePostDataController.php),
[`EnergieSensorRepository`](../src/Repository/EnergieSensorRepository.php),
[`EnergieRealtimeDataProvider`](../src/Service/Realtime/EnergieRealtimeDataProvider.php),
routes [`config/routes_energie.php`](../config/routes_energie.php),
migration [`migrations/2026_10_energie_tables.sql`](../migrations/2026_10_energie_tables.sql).

---

## 2. POST données (`/energie[-test]/post-data`)

**Content-Type :** `application/x-www-form-urlencoded` (envoyé par la lib partagée `n3DataPost`).

### 2.1 Authentification (identique MSP1 / N3PP)

Par ordre de priorité ([`HmacAuthTrait`](../src/Controller/Concerns/HmacAuthTrait.php)) :

1. **Body-signing** — en-têtes `X-Sig-Timestamp`, `X-Sig-Nonce`, `X-Sig-Hmac` :
   `X-Sig-Hmac = hex(HMAC-SHA256(timestamp + "\n" + nonce + "\n" + corps_brut, API_SIG_SECRET))`.
   Une signature invalide **ne rejette pas** à elle seule (repli sur 2 puis 3), sauf `HMAC_STRICT_MODE=true`.
2. **HMAC legacy** — champs `timestamp` + `signature` dans le corps :
   `signature = hex(HMAC-SHA256(timestamp, API_SIG_SECRET))` (ou `timestamp|post_id` si `HMAC_NONCE_REQUIRED`).
   Un seul des deux champs → **401** ; signature fausse → **401**.
3. **Clé API** — champ `api_key` comparé (`hash_equals`) à `API_KEY`.

Secrets **partagés** avec les autres familles : `API_KEY`, `API_SIG_SECRET` ; fenêtre `SIG_VALID_WINDOW`
(défaut 300 s). Signature envoyée alors qu'`API_SIG_SECRET` est vide côté serveur → **500**.
Mode strict / nonce / fenêtre pilotables depuis la supervision (BDD `serverSettings`, repli `.env`).

### 2.2 Champs

Obligatoires :

| Champ | Type | Description |
|-------|------|-------------|
| `sensor` | string ≤ 30 | Identifiant firmware — `energie` (tronqué à 30) |
| `version` | string ≤ 30 | Version firmware (tronquée à 30) |

Numériques — **tous optionnels** : absent, vide ou non numérique → `NULL` en BDD (la ligne est
quand même enregistrée). Colonne BDD = nom du champ.

| Champ | Type BDD | Unité | Description |
|-------|----------|-------|-------------|
| `PanneauV` | DOUBLE | V | Tension panneau (agrégat de la fenêtre) |
| `PanneauI` | DOUBLE | A | Courant panneau |
| `PanneauP` | DOUBLE | W | Puissance panneau |
| `PanneauImax` | DOUBLE | A | Courant panneau max. sur la fenêtre |
| `BatterieV` | DOUBLE | V | Tension batterie |
| `BatterieVmin` | DOUBLE | V | Tension batterie min. sur la fenêtre |
| `BatterieI` | DOUBLE | A | Courant batterie (**> 0 charge, < 0 décharge**) |
| `BatterieImin` | DOUBLE | A | Courant batterie min. (décharge la plus forte) |
| `BatterieImax` | DOUBLE | A | Courant batterie max. (charge la plus forte) |
| `BatterieP` | DOUBLE | W | Puissance batterie (même signe que `BatterieI`) |
| `BatterieVadc` | DOUBLE | V | Tension batterie lue via ADC (contrôle croisé de `BatterieV`) |
| `ConsoV` | DOUBLE | V | Tension côté consommation |
| `ConsoI` | DOUBLE | A | Courant consommé |
| `ConsoP` | DOUBLE | W | Puissance consommée |
| `ConsoImax` | DOUBLE | A | Courant conso max. sur la fenêtre |
| `EnergiePanneauWh` | DOUBLE | Wh | Énergie produite **pendant la fenêtre de 10 s** (delta) |
| `EnergieConsoWh` | DOUBLE | Wh | Énergie consommée **pendant la fenêtre de 10 s** (delta) |
| `BatterieAh` | DOUBLE | Ah | Compteur **net cumulé** de la batterie (coulomb-mètre) |
| `BatterieSoc` | DOUBLE | % | État de charge estimé (0–100) |
| `InaStatus` | INT | bitmask | Voir § 2.3 |
| `I2cErreurs` | INT | — | Compteur d'erreurs I²C remonté par le firmware |
| `Rssi` | INT | dBm | Signal WiFi |
| `FreeHeap` | INT | octets | Heap libre ESP32 |
| `BootCount` | INT | — | Nombre de démarrages |
| `Uptime` | BIGINT | s | Temps depuis le démarrage |

`reading_time` est fixé par le serveur à la réception (heure `APP_TIMEZONE`).

### 2.3 `InaStatus` (bitmask)

Index de canal `i` : **0 = panneau**, **1 = batterie**, **2 = conso**.

| Bits | Signification |
|------|---------------|
| `i` (0–2) | INA226 présent / configuré |
| `4 + i` (4–6) | Saturation du shunt observée pendant la fenêtre |
| `8 + i` (8–10) | Ré-initialisation de l'INA pendant la fenêtre |

Exemple : `7` = les trois INA présents, rien à signaler ; `0x107` (263) = trois INA présents,
INA panneau ré-initialisé. La page `/energie[-test]` décode ce champ sous les cartes.

### 2.4 Réponses

| Code | Corps (text/plain) | Cas |
|------|--------------------|-----|
| **200** | `Donnees enregistrees avec succes` | Mesure insérée |
| **400** | `Donnees manquantes` / `Champs sensor et version requis` | Corps vide, `sensor` ou `version` absent/vide |
| **401** | `Cle API invalide` / `Signature incorrecte` / `Signature incomplete` | Auth refusée |
| **405** | `POST requis` | Autre méthode |
| **429** | `Trop de requetes` | Rate-limit firmware (si `FIRMWARE_RATE_LIMIT_MAX` > 0) |
| **500** | `Configuration serveur manquante` / `Erreur serveur` | Secret absent, erreur SQL… |

### 2.5 Exemple

```bash
curl -X POST https://iot.olution.info/energie-test/post-data \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data 'api_key=<API_KEY>&sensor=energie&version=0.3.1' \
  --data 'PanneauV=18.42&PanneauI=1.234&PanneauP=22.73&PanneauImax=1.5' \
  --data 'BatterieV=12.81&BatterieVmin=12.70&BatterieI=-0.512&BatterieImin=-0.9&BatterieImax=0.2&BatterieP=-6.56&BatterieVadc=12.75' \
  --data 'ConsoV=12.60&ConsoI=0.480&ConsoP=6.05&ConsoImax=0.61' \
  --data 'EnergiePanneauWh=0.0631&EnergieConsoWh=0.0168&BatterieAh=-1.234&BatterieSoc=78' \
  --data 'InaStatus=7&I2cErreurs=0&Rssi=-61&FreeHeap=183456&BootCount=4&Uptime=86400'
# → 200 Donnees enregistrees avec succes
```

---

## 3. API temps réel (lecture seule, publique)

Préfixe `/energie/api/realtime` (prod) ou `/energie-test/api/realtime` (test) —
[`EnergieRealtimeApiController`](../src/Controller/Energie/EnergieRealtimeApiController.php).

| Route | Réponse |
|-------|---------|
| `GET …/sensors/latest` | `{timestamp, reading_time, sensors: {PanneauV: …, …, Uptime: …}}` (toutes les colonnes numériques) |
| `GET …/sensors/since/{timestamp}` | `{count, readings: [{timestamp, reading_time, sensors}, …]}` (ordre chronologique) |
| `GET …/system/health` | `{online, last_reading, last_reading_ts, last_reading_ago_seconds, uptime_percentage, readings_today, average_latency_seconds, device_ip: null, module_uptime_seconds}` |
| `GET …/outputs/state` | `{timestamp, outputs: []}` (aucune sortie) |
| `GET …/alerts/active` | `{timestamp, count: 0, alerts: []}` |

Santé : **en ligne** si la dernière mesure date de **moins de 90 s** ; `uptime_percentage` =
lectures reçues sur **24 h** / 8640 attendues (86400 s ÷ 10 s), plafonné à 100 %.

---

## 4. Page publique

`GET|POST /energie` et `/energie-test` ([`EnergieDataController`](../src/Controller/Energie/EnergieDataController.php),
template `energie_data.twig`) : cartes live (puissances, tensions, courants, état de charge,
compteur Ah, énergie par fenêtre, RSSI, erreurs I²C), polling temps réel 15 s et 4 graphiques
Highstock — **Puissances** (W), **Tensions** (V), **Courants** (A, ligne du zéro pour le signe
batterie), **Batterie & énergie** (axes %, Ah et Wh). Le formulaire de période (POST + jeton CSRF)
et l'export CSV fonctionnent comme sur `/meteo`. Lien de menu « Énergie (banc) » → `/energie-test`
(clé `energie-test`, table `navPages`) ; le lien prod `/energie` (clé `energie`) s'active depuis la
supervision quand le module réel postera en production.

---

## 5. Déploiement

1. Appliquer `migrations/2026_10_energie_tables.sql` (tables + lien de menu ; idempotent).
   Docker local : `docker/mysql/init/92-energie.sql`.
2. Vérifier `API_KEY` / `API_SIG_SECRET` dans le `.env` (mêmes valeurs que les autres firmwares).
3. Firmware : URL `https://iot.olution.info/energie-test/post-data` (banc) ou `/energie/post-data` (module réel).
