# Audit des graphiques Highcharts – Serveur n3 IoT

**Date :** 2026-03-30  
**Périmètre :** Pages FFP3 (aquaponie), MSP1 (météo), N3PP (serre) et composants associés.

---

## 1. Inventaire des usages Highcharts

| Page | Template | Contrôleur | Graphiques | Données |
|------|----------|------------|------------|--------|
| **FFP3 classique** | `aquaponie.twig` | AquaponieController | 2 Stock (eau, temp) | ChartDataService::prepareSeriesData + prepareTimestamps |
| **FFP3 paysage** | `aquaponie_alt.twig` | AquaponieController | 2 Stock (eau, temp) | Idem |
| **MSP1 météo** | `msp1_data.twig` | MspDataController | 4 (temperatures, lights, niveauxeaux, cycles) | prepareGenericSeries + getChartColumns() |
| **N3PP serre** | `n3pp_data.twig` | N3ppDataController | 3 (niveauxeaux, temperatures, cycles) | Idem |

- **FFP3** : données injectées en tableaux JS + boucle `[ts, value]` dans le template ; mise à jour temps réel via `chart-updater.js`.
- **MSP1 / N3PP** : variables `reading_time` + une par colonne ; `zipSeries(reading_time, Col)` fourni par `chart-helpers.js` ; mise à jour temps réel via `chart-updater-generic.js`.

---

## 2. Dépendances (fichiers et partials)

### Assets JS (public/assets/js/)

| Fichier | Rôle | Pages |
|---------|------|--------|
| `highcharts-defaults.js` | Config globale (timezone Africa/Casablanca, thème clair/sombre, lang FR) | FFP3, MSP1, N3PP |
| `chart-helpers.js` | `zipSeries`, `n3AreaGradientFill(r,g,b)` — dégradé sous courbe (aligné aquaponie) | MSP1, N3PP |
| `chart-updater.js` | Mise à jour temps réel des graphiques FFP3 | FFP3 |
| `chart-updater-generic.js` | Mise à jour temps réel MSP1/N3PP (chartIds + sensorMap, insertion triée, dédoublonnage timestamp) | MSP1, N3PP |
| `n3-stock-chart-layout.js` | Options/hauteurs Stock partagées + resize/orientation | FFP3, MSP1, N3PP |
| `n3-stock-chart-bootstrap.js` | Initialisation robuste (retry dimensions), création stockChart standardisée, reflow AOS | MSP1, N3PP |
| `realtime-updater.js` | Polling API temps réel ; appelle `window.chartUpdater.addNewReadings` | FFP3, MSP1, N3PP |

### Templates partiels (templates/partials/)

| Partial | Rôle | Inclus par |
|---------|------|------------|
| `_hero_data.twig` | Bloc hero (titre, sous-titre, synthèse, lien « En savoir plus ») | msp1_data, n3pp_data, aquaponie, aquaponie_alt |
| `_live_badge.twig` | Badge statut LIVE / HORS LIGNE | msp1_data, n3pp_data |
| `_chart_init_js.twig` | Init ChartUpdaterGeneric (chart_ids_json, sensor_map_json) | msp1_data, n3pp_data (si measure_count > 0) |
| `_realtime_init_js.twig` | Init RealtimeUpdater (realtime_api_base, poll_interval) | msp1_data, n3pp_data |

### Chargement Highcharts

- **Chargés en local (`public/assets/js`) sur les pages concernées :** `highstock.js`, `exporting.js`, `export-data.js`, `accessibility.js`.
- **FFP3 :** module Boost non chargé (problèmes de rendu mobile / courbes en colonnes).

---

## 3. Timezone

- **ChartDataService::prepareTimestamps** : interprète `reading_time` en **Europe/Paris**, renvoie timestamps en ms (epoch UTC).
- **FFP3** : `Highcharts.setOptions({ time: { timezone: 'Africa/Casablanca' } })` et `moment.tz.setDefault('Africa/Casablanca')` dans les templates.
- **MSP1 / N3PP** : timezone définie dans **highcharts-defaults.js** : `time: { useUTC: false, timezone: 'Africa/Casablanca' }` pour cohérence avec FFP3 et `docs/TIMEZONE_MANAGEMENT.md`.

---

## 4. Correctifs appliqués (2026-03-16)

| Élément | Statut |
|--------|--------|
| **templates/partials/** | Créés : `_hero_data.twig`, `_live_badge.twig`, `_chart_init_js.twig`, `_realtime_init_js.twig` |
| **chart-helpers.js** | Créé avec `zipSeries(timeArray, valueArray)` |
| **chart-updater-generic.js** | Créé (ChartUpdaterGeneric : chartIds, sensorMap, addNewReadings) |
| **highcharts-defaults.js** | Ajout `timezone: 'Africa/Casablanca'` dans `time` ; ruptures de série : `gapUnit: 'value'`, `gapSize: 21600000` (6 h) — éviter `gapUnit: 'relative'` (seuil basé sur la paire de points la plus proche, courbes fragmentées / invisibles sans marqueurs). |

---

## 5. Points d’attention (données et perf)

- **Valeurs aberrantes** (rapport 2026-03-09) : ex. 255.9 °C sur météo (DHT) ; à traiter côté firmware (validation / filtrage).
- **Serre** : nombreuses valeurs à 0 ou 1 (capteurs ou humidité) ; vérifier câblage / firmware.
- **Taille des pages** : serre avec beaucoup de mesures (ex. 4923) ; envisager pagination ou chargement dynamique pour les longues périodes.

---

## 6. Références

- `docs/TIMEZONE_MANAGEMENT.md` – Gestion des fuseaux (Europe/Paris, Africa/Casablanca).
- `docs/archive/rapport_verification_graphiques_highcharts_2026-03-09.md` – Vérification technique précédente.
- `src/Service/ChartDataService.php` – Préparation des séries et timestamps pour Highcharts.

---

## 7. Mise à jour architecture (2026-03-30)

- MSP1 et N3PP n’instancient plus directement tous les `Highcharts.stockChart` dans les templates : la création passe par `n3StockChartBootstrap.createStockChart(...)`.
- L’initialisation attend désormais des conteneurs réellement dimensionnés (`ensureContainersReady`) avant création des graphiques, avec retries bornés (notamment utile avec AOS/mobile).
- Le `ChartUpdaterGeneric` applique la même robustesse que l’aquaponie pour le live : mise à jour d’un timestamp existant, insertion triée pour points hors ordre, et redraw sans animation (`chart.redraw(false)`).
- `n3-stock-chart-layout.js` aligne le navigateur sur aquaponie avec `navigator.xAxis.ordinal = false` et améliore la gestion de légende dense (maxHeight + navigation).
