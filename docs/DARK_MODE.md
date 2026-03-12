# Mode sombre — Documentation

Le serveur n3 IoT prend en charge un mode sombre (dark mode) cohérent sur l'ensemble des pages principales.

## Architecture

- **Attribut** : `data-theme="dark"` sur `<html>` (défini par `theme-toggle.js`)
- **Variables CSS** : `theme-variables.css` définit les variables sémantiques (--bg-page, --text-primary, etc.) en clair et en sombre
- **Stockage** : `localStorage` clé `n3-iot-theme` : `"dark"` | `"light"` | absent (= préférence système)
- **Bouton** : icône lune/soleil dans la barre de navigation (`#theme-toggle-btn`)

## Fichiers concernés

| Fichier | Rôle |
|---------|------|
| `public/assets/js/theme-toggle.js` | Détection préférence système, toggle manuel, application thème |
| `public/assets/js/highcharts-theme.js` | Adaptation des graphiques Highcharts au thème (n3HighchartsApplyTheme) |
| `public/assets/js/chartjs-theme.js` | Adaptation des graphiques Chart.js au thème (n3ChartJsApplyTheme, tide_stats) |
| `public/assets/css/theme-variables.css` | Variables :root et [data-theme="dark"] |
| `public/assets/css/realtime-styles.css` | Surcharges main.css (body, #main, header, footer, toast, etc.) |
| `public/assets/css/common-data.css` | stat-card, chart-container, filter-section, data-table, etc. |
| `public/assets/css/control-styles.css` | Page contrôle (board-card, status-card, action-card, etc.) |
| `public/assets/css/login-styles.css` | Page de connexion |
| `public/assets/css/gallery-styles.css` | Galeries photos |
| `public/assets/css/supervision-styles.css` | Page supervision |
| `public/assets/css/home-styles.css` | Page d'accueil |
| `public/assets/css/aquaponie.css` | Composants aquaponie (balance-card, etc.) |
| `public/assets/css/timelapse-styles.css` | Surcharges dark mode pour page Timelapse |
| `public/assets/css/msp1-sheet-styles.css` | Cartes Google Sheets (msp-sheet-card) sur msp1_data |
| `public/assets/css/aquaponie-description-styles.css` | Page description aquaponie (.desc-section) |

## Pages couvertes

Toutes les pages utilisant `layout.twig` ont le support dark mode :
- Accueil, Login, Supervision
- Aquaponie (vue principale, vue classique), Dashboard, Tide stats
- Contrôle (aquaponie, météo, serre)
- Données MSP1, N3PP
- Galeries (msp1, n3pp, ffp3)
- Descriptions (aquaponie, météo, serre)

Le layout `layout_base.twig` inclut désormais theme-toggle et theme-variables pour cohérence future.

## Graphiques Highcharts

Les graphiques (aquaponie, aquaponie_alt, msp1_data, n3pp_data, tide_stats, dashboard) s'adaptent au thème via `n3HighchartsApplyTheme()`, appelée :
- lors du basculement de thème (bouton toggle)
- à l'initialisation de la page (délais 0, 1,2 s et 2,5 s pour couvrir les graphiques créés asynchronement)

Le script `highcharts-theme.js` est chargé dans le layout après les scripts des templates ; il définit la fonction et l'applique automatiquement.

## Graphiques Chart.js

Les graphiques **Chart.js** (page tide_stats) s'adaptent au thème via `n3ChartJsApplyTheme()`, appelée lors du basculement de thème et à l'init. Le script `chartjs-theme.js` est chargé dans la page tide_stats après Chart.js.

## Page Timelapse

La page Timelapse utilise des variables CSS locales (--surface, --surface2, --text, etc.). Le fichier `timelapse-styles.css` définit les surcharges `[data-theme="dark"] .timelapse-page` pour adapter sidebar, contrôles et toast au mode sombre.

## Éléments non pris en compte

- **Iframes Google Sheets** (graphiques chimie sur aquaponie/aquaponie_alt, msp-sheet-card sur msp1_data) : contenu externe, non contrôlable ; les cartes (msp-sheet-card) sont toutefois stylées en dark mode

## Écarts connus (audit)

- **stat-card** (msp1_data, n3pp_data) : couleurs sémantiques en inline (`border-left-color`, `color` sur icônes) pour distinguer les capteurs ; lisibles en dark, approche peu homogène
- **control-styles.css** : règles dark dupliquées pour `.quick-action-card`, `.esp32-sync-info`, `.context-stat`, `.callout-warning` ; mélange `--accent` / `--accent-primary`
- **gallery-hero** : gradient vert conservé en dark, texte blanc lisible, pas de surcharge explicite

## Ordre de chargement (layout.twig)

1. `theme-toggle.js` (head, synchrone)
2. `theme-variables.css`
3. `main.css`
4. `realtime-styles.css`
5. `head_css` du template (common-data, control-styles, etc.)
6. `head_scripts` du template (Highcharts, etc.)
7. `highcharts-theme.js`
