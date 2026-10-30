# Presets d'affichage des pages de suivi

**Depuis** : serveur **6.41.0** (enrichi **6.46.0** : FFP3, presets publics, reorder cartes/séries)  
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
| `etendu` | Complet (défaut opérateur) | Visibilités par défaut du catalogue |
| `technique` | Maintenance | Idem `etendu` |

Sans `?preset=`, un **lecteur** (ou session anonyme) reçoit `simple` tant qu'aucun défaut
personnalisé n'est enregistré en base. Les **opérateurs / admins** gardent `etendu`.

## Choix visiteur (page de suivi)

1. Pastilles « Affichage : Simple | Visite | Élèves | … » (`partials/_preset_selector.twig`).
2. Un clic recharge avec `?preset=slug` et mémorise dans `localStorage` (`n3.preset.<page>`).
3. `period-nav.js` conserve le paramètre `preset` d'une fenêtre temporelle à l'autre.
4. Sans `?preset`, un choix mémorisé différent du défaut est réappliqué (GET uniquement).

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

## FFP3 — particularités

- Catalogue : `src/Display/Catalog/Ffp3Catalog.php`
- Template : `templates/aquaponie_data.twig` (étend `monitoring_page.twig`)
- Blocs dédiés : `tide_panel`, `water_balance`
- `/aquaponie-alt` force le preset `simple` si aucun `?preset=`
- Niveaux d'eau convertis mm→cm dans `AquaponieController` avant les séries

## Fichiers clés

- `src/Display/` — catalogues, `LayoutResolver`, registre
- `src/Service/DisplayPresetService.php` — fusion, sélection, validation
- `templates/monitoring_page.twig` — rendu générique
- `public/assets/js/n3-layout-charts.js` — graphiques depuis JSON
- `public/assets/js/preset-selector.js` / `display-presets-editor.js`
