# Inventaire appareils IoT n3 — boards S3 et modules

**Dernière mise à jour** : 2026-07-05 (plan BDD juillet 2026)

## Boards FFP3 (aquaponie)

| Board | ENV serveur | Table données | Table outputs | Usage |
|-------|-------------|---------------|---------------|-------|
| 1 | `prod` | `ffp3Data` | `ffp3Outputs` | Aquaponie production |
| 1 | `test` | `ffp3Data2` | `ffp3Outputs2` | Test WROOM |
| 4 | `test3` | `ffp3Data3` | `ffp3Outputs3` | Test3 |
| **5** | **`s3`** | **`ffp3DataS3`** | **`ffp3OutputsS3`** | **S3 production** (ex-`ffp3Data4` migré) |
| **6** | **`s3test`** | **`ffp3DataS3Test`** | **`ffp3OutputsS3Test`** | **S3 test** |

## Modules legacy MSP / N3PP

| Appareil | Board | Firmware | Endpoints |
|----------|-------|----------|-----------|
| n3-n3pp (serre) | 3 | n3pp **4.50** | `/n3pp/post-data`, `/n3pp/heartbeat` |
| n3-msp (météo) | 2 | msp **2.49** | `/msp1/post-data`, `/msp1/heartbeat` |

## Galeries photo (ESP32-CAM)

| Slug | Board outputs | Firmware |
|------|---------------|----------|
| msp1 | 6 | uploadphotosserver_msp |
| n3pp | 7 | uploadphotosserver_n3pp |
| ffp3 | 5 | uploadphotosserver_ffp3 |

## Banc énergie INA226 (mesure seule)

| Appareil | ENV serveur | Table données | Firmware | Endpoints |
|----------|-------------|---------------|----------|-----------|
| Banc énergie (ESP32-S3, 3 × INA226) | `energie_test` | `energieDataTest` | `energie` | `/energie-test/post-data`, `/energie-test/api/realtime/*` |
| Module énergie réel (à venir) | `prod` | `energieData` | `energie` | `/energie/post-data`, `/energie/api/realtime/*` |

Pas de table outputs ni de heartbeat ; contrat : [`API_ENERGIE.md`](API_ENERGIE.md).

## Poissonglouton

| Board ID | Route |
|----------|-------|
| `poissonglouton` | `/pgl/post-data`, `/pgl/heartbeat` |
