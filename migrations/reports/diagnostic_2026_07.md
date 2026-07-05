# Rapport diagnostic BDD — juillet 2026

**Date** : 2026-07-05  
**Dump** : `dump_bdd/oliviera_iot (1).sql` (719 Mo, export 05/07/2026)  
**Backup prod** : réalisé par l'utilisateur (prérequis scripts 01–03)

## Comptages volumétriques (audit 05/07)

| Table | ~Lignes | Fraîcheur |
|-------|---------|-----------|
| `ffp3Data` | ~1 035 574 | Actif 2026-07-05 |
| `ffp3Data4` | ~779 471 | Orpheline → migrer vers `ffp3DataS3` |
| `ffp3DataS3` | 0 | Vide avant migration |
| `n3ppData` | ~1 858 087 | Actif ; double-POST ~45 % bruit |
| `msp1Data` | ~16 295 | Stale depuis **2026-05-26** |
| `n3ppHeartbeat` / `msp1Heartbeat` | 0 | Firmware n'appelait pas (corrigé v4.50 / v2.49) |

## P0-U04 — MSP stale

- **Dernier `reading_time`** : 2026-05-26 12:57 (environ 40 jours avant audit)
- **Cause probable** : intervention terrain (WiFi, alimentation, reflash) — pas de correctif BDD
- **Action** : reflash firmware msp **2.49** + vérifier connectivité

## P0-U09 — Double-POST N3PP (estimation)

- Paires consécutives identiques à Δt ≤ 2 s : **~350 Mo** récupérables (script 03, niveau 1a)
- Requête de comptage : voir en-tête `2026_07_PROD_03_prune_noise_data.sql`

## GPIO prod (avant script 01)

| Module | Problème |
|--------|----------|
| N3PP | GPIO 2 legacy pompe ; GPIO 109 doublon arrosage |
| MSP | GPIO 2 fantôme « Pompe » ; GPIO 111 libellé « Pompe » au lieu de ServoModeAuto |
| Notifications | GPIO 101 `checked`/`false` vs GPIO 108 mode réel |

## Procédure prod recommandée

1. `00_diagnostic_prod.sql`
2. `2026_07_PROD_01_migrate_s3_and_gpio.sql`
3. Vérifier `COUNT(ffp3DataS3)` ≈ ancien `ffp3Data4`
4. `2026_07_PROD_02_drop_orphan_tables.sql`
5. `2026_07_PROD_03_prune_noise_data.sql` (plage creuse)
6. `2026_07_PROD_04_indexes.sql` (optionnel)
7. `99_validate_prod.sql`

## Serveur post-déploiement

- Version cible : **6.8.0**
- Firmwares alignés : n3pp **4.50**, msp **2.49**, ffp5cs **15.01**
