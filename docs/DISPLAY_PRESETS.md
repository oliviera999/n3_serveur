# Presets d'affichage des pages de suivi

**Depuis** : serveur **6.41.0**  
**Périmètre** : MSP1 (`/meteo`), N3PP (`/serre`), ENERGIE (`/energie`). FFP3 / PGL : phase 2.

Les pages de suivi sont pilotées par un **catalogue déclaratif** (`src/Display/`) qui décrit
tous les cadres, cartes et séries possibles. Un **preset** n'est qu'une surcouche
(ordre + visibilité) appliquée à ce catalogue.

## Concepts

| Notion | Rôle |
|--------|------|
| Catalogue (`PageCatalog`) | Source de vérité : ce que la page *sait* afficher |
| Preset intégré | `simple` / `etendu` définis en PHP (repli si BDD vide) |
| Preset enregistré | Ligne de `displayPresets` (personnalisation ou preset custom) |
| `LayoutResolver` | Fusion catalogue + preset (identifiants inconnus ignorés) |

États d'une **série** : `shown` (tracée), `legend` (présente mais décochée), `hidden` (absente).  
États d'une **carte** : `shown` / `hidden`.

## Choix visiteur (page de suivi)

1. Pastilles « Affichage : Simple | Étendu | … » (`partials/_preset_selector.twig`).
2. Un clic recharge avec `?preset=slug` et mémorise dans `localStorage` (`n3.preset.<page>`).
3. `period-nav.js` conserve le paramètre `preset` d'une fenêtre temporelle à l'autre.
4. Sans `?preset`, un choix mémorisé différent du défaut est réappliqué (GET uniquement).

## Édition (opérateur+)

| Page | URL |
|------|-----|
| MSP1 | `/meteo-control` (section « Affichage de la page de suivi ») |
| N3PP | `/serre-control` |
| ENERGIE | `/energie-control` (page dédiée, pas de GPIO) |

Variantes test : `/msp1-test/msp1control/`, `/n3pp-test/n3ppcontrol/`, `/energie-control-test`.

## API JSON

Toutes les routes exigent un rôle **operator** (ou admin) et un jeton **CSRF**
(`X-CSRF-Token`) pour POST/DELETE.

| Méthode | URL | Action |
|---------|-----|--------|
| `GET` | `/api/display-presets/{page}` | Catalogue + presets (`page` = `msp1` \| `n3pp` \| `energie`) |
| `POST` | `/api/display-presets/{page}` | Créer / mettre à jour un preset |
| `DELETE` | `/api/display-presets/{page}/{slug}` | Supprimer (ou réinitialiser un intégré) |

Corps POST (JSON) :

```json
{
  "slug": "eleves",
  "label": "Élèves",
  "description": "Vue pédagogique",
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

## Persistance

- Table globale `displayPresets` (`migrations/2026_10_display_presets.sql`).
- Créée aussi à la volée par `DisplayPresetRepository` (MySQL / SQLite).
- Partagée prod/test d'une même famille (mêmes colonnes capteurs).
- Sans ligne en base : presets intégrés uniquement ; la page de suivi reste utilisable.

## Fichiers clés

- `src/Display/` — catalogues, `LayoutResolver`, registre
- `src/Service/DisplayPresetService.php` — fusion, sélection, validation
- `templates/monitoring_page.twig` — rendu générique
- `public/assets/js/n3-layout-charts.js` — graphiques depuis JSON
- `public/assets/js/preset-selector.js` / `display-presets-editor.js`
