# Presets d'affichage des pages de suivi

**Depuis** : serveur **6.41.0** (enrichi **6.46.0** : FFP3, presets publics, reorder cartes/séries ;
**6.49.0** : défaut « Étendu » pour tous, pastilles dédoublonnées, cookie, marqueurs ON, chimie FFP3)  
**Périmètre** : MSP1 (`/meteo`), N3PP (`/serre`), ENERGIE (`/energie`), FFP3 (`/aquaponie`).

Les pages de suivi sont pilotées par un **catalogue déclaratif** (`src/Display/`) qui décrit
tous les cadres, cartes et séries possibles. Un **preset** n'est qu'une surcouche
(ordre + visibilité) appliquée à ce catalogue.

## Concepts

| Notion | Rôle |
|--------|------|
| Catalogue (`PageCatalog`) | Source de vérité : ce que la page *sait* afficher |
| Preset intégré | `simple` / `visite` / `eleves` / `etendu` / `technique` (repli si BDD vide) |
| Preset enregistré | Ligne de `displayPresets` (personnalisation ou preset custom) |
| `LayoutResolver` | Fusion catalogue + preset (identifiants inconnus ignorés) |

États d'une **série** : `shown` (tracée), `legend` (présente mais décochée), `hidden` (absente).  
États d'une **carte** : `shown` / `hidden`.

### Presets publics intégrés (audiences)

| Slug | Public | Base |
|------|--------|------|
| `simple` | Vue essentielle | Drapeaux `simple` du catalogue |
| `visite` | Parents / visiteurs | Idem `simple` |
| `eleves` | Collège / lycée | Idem `simple` |
| `etendu` | Complet (**défaut**) | Visibilités par défaut du catalogue |
| `technique` | Maintenance | Idem `etendu` |

Sans `?preset=` ni choix mémorisé, **tous les visiteurs** reçoivent `etendu` (affichage
historique complet), sauf si un autre preset est marqué « par défaut » dans l'éditeur.
(La préférence « simple » pour lecteurs / anonymes de 6.46.0 a été retirée en 6.49.0.)

**Pastilles dédoublonnées** : un preset intégré non personnalisé dont le rendu est
identique à un preset déjà proposé n'apparaît pas (tant qu'ils ne sont pas modifiés,
Visite / Élèves = Simple et Technique = Étendu → seules « Simple » et « Étendu » sont
proposées). Le preset actif et le défaut sont toujours affichés. Personnaliser « Élèves »
dans l'éditeur le fait apparaître.

## Choix visiteur (page de suivi)

1. Pastilles « Affichage : Simple | Étendu | … » (`partials/_preset_selector.twig`).
2. Un clic ouvre `?preset=slug` et mémorise le choix dans le cookie `n3_preset_<page>`
   (1 an, `SameSite=Lax`), lu **côté serveur** : la visite suivante est directement rendue
   avec ce preset (pas de rechargement). L'ancien `localStorage` (`n3.preset.<page>`) est
   migré une fois vers le cookie.
3. `period-nav.js` conserve le paramètre `preset` d'une fenêtre temporelle à l'autre.
4. Priorité : `?preset=` (ou preset forcé, ex. `/aquaponie-alt`) > cookie > défaut.

## Édition (opérateur+)

| Page | URL |
|------|-----|
| MSP1 | `/meteo-control` (section « Affichage de la page de suivi ») |
| N3PP | `/serre-control` |
| ENERGIE | `/energie-control` (page dédiée, pas de GPIO) |
| FFP3 | `/aquaponie-control` |

Variantes test : `/msp1-test/msp1control/`, `/n3pp-test/n3ppcontrol/`, `/energie-control-test`,
`/aquaponie-control-test`.

L'éditeur permet de **réordonner** les cadres **et** les cartes / séries (glisser-déposer),
et de régler la visibilité (séries : Affichée / Légende seule / Masquée).

## API JSON

Toutes les routes exigent un rôle **operator** (ou admin) et un jeton **CSRF**
(`X-CSRF-Token`) pour POST/DELETE.

| Méthode | URL | Action |
|---------|-----|--------|
| `GET` | `/api/display-presets/{page}` | Catalogue + presets (`page` = `msp1` \| `n3pp` \| `energie` \| `ffp3`) |
| `POST` | `/api/display-presets/{page}` | Créer / mettre à jour un preset |
| `DELETE` | `/api/display-presets/{page}/{slug}` | Supprimer (ou réinitialiser un intégré) |

Corps POST (JSON) :

```json
{
  "slug": "atelier",
  "label": "Atelier",
  "description": "Vue courte pour un atelier",
  "is_default": false,
  "show_on_page": true,
  "sort_order": 5,
  "layout": {
    "blocks": [
      {
        "id": "sol",
        "visible": true,
        "chart": true,
        "cards": { "HumidMoy": "shown", "Humid1": "hidden" },
        "series": { "HumidMoy": "shown", "Humid1": "legend" }
      }
    ]
  }
}
```

Le layout est validé **strictement** contre le catalogue (bloc inconnu → HTTP 400).
L'ordre des clés de `cards` et `series` est l'ordre d'affichage.

## Persistance

- Table globale `displayPresets` (`migrations/2026_10_display_presets.sql`).
- Créée aussi à la volée par `DisplayPresetRepository` (MySQL / SQLite).
- Partagée prod/test d'une même famille (mêmes colonnes capteurs).
- Sans ligne en base : presets intégrés uniquement ; la page de suivi reste utilisable.

## Options de séries (catalogue)

| Clé | Effet |
|-----|-------|
| `step` | Ligne en escalier (`true` = `'left'`) |
| `on_markers` `{ lane, symbol, radius }` | Série d'**événements** : un marqueur par mesure > 0, posé à la hauteur fixe `lane`. À placer sur un axe masqué `min 0 / max 1` (bande en bas du graphique) ou sur un axe à `categories` (chronogramme, `lane` = index de ligne). En live, une valeur ≤ 0 n'ajoute rien. |
| `trend_of` / `trend_round` | Tendance d'une autre colonne, non live : droite des moindres carrés **sur le temps**, recalculée sur la plage zoomée |
| `trend_kind: moving_avg` / `trend_window` | Moyenne mobile centrée sur `trend_window` minutes (défaut 30) au lieu de la droite |

Cartes : `format: 'onoff'` affiche **Marche / Arrêt** (rendu serveur et mise à jour live via `data-format`).
`stuck_check: true` signale « capteur figé ? » si toutes les mesures de la période sont identiques
(≥ 20 mesures sur ≥ 2 h). Une carte dont la mesure courante est vide affiche la **dernière valeur
valide** et sa date (recherche bornée à 30 jours, `getLastNonNull()`).

Points « réchauffés » au réveil : `payload.stale` liste, par colonne, les points identiques au précédent
juste après une veille (≥ 5 min) ; ils sont omis du tracé pour éviter l'effet d'escalier
(`ChartDataService::staleWakeIndexes`). Cartes et statistiques utilisent toutes les lectures.

Ne jamais tracer un état 0/1 sur l'axe d'une mesure : il force l'axe à 0 et écrase la courbe.

## Mise à jour live

`ChartUpdaterGeneric` lit les colonnes `x` / `y` via `series.dataTable` (Highcharts 12 n'expose plus
`xData`) ; un point identique déjà présent est ignoré, un relevé plus récent est ajouté en fin de série.

`sensor_map_json` associe chaque colonne à sa 1re série (`chartId`, `seriesIndex`, format
historique) et liste **toutes** ses séries dans `targets` (une colonne peut alimenter plusieurs
graphiques, ex. `etatHeat` : marqueurs du graphique physique + chronogramme). `onLane` marque
une série d'événements. `n3LayoutCharts.onReady(fn)` permet d'ajouter des séries (ex. marées)
après la création différée des graphiques, **en fin de liste** pour ne pas décaler les index.

## FFP3 — particularités

- Catalogue : `src/Display/Catalog/Ffp3Catalog.php`
- Template : `templates/aquaponie_data.twig` (étend `monitoring_page.twig`)
- Blocs dédiés : `tide_panel`, `water_balance`, custom `_ffp3_intro`, `_ffp3_chemistry`
  (analyses chimiques des élèves, Google Sheets ; `requires_data: false`, visible partout)
- Marqueurs pleine mer / basse mer ajoutés au graphique qui trace `EauAquarium`
  (`AquaponieController::tideChartId`), via `onReady`
- Pompes / chauffage / lumière / nourrissage : marqueurs « ON » sur axe d'événements masqué
  + chronogramme « Actionneurs » (Étendu)
- `/aquaponie-alt` force le preset `simple` si aucun `?preset=`
- Niveaux d'eau convertis mm→cm dans `AquaponieController` avant les séries

## Fichiers clés

- `src/Display/` — catalogues, `LayoutResolver`, registre
- `src/Service/DisplayPresetService.php` — fusion, sélection, validation
- `templates/monitoring_page.twig` — rendu générique
- `public/assets/js/n3-layout-charts.js` — graphiques depuis JSON
- `public/assets/js/preset-selector.js` / `display-presets-editor.js`
