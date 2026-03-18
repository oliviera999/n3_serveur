# 08 — Cards avec variables shadow

## Contexte

Sur **index.olution.info**, les cartes (icon-box, services) utilisent `box-shadow: var(--shadow-card)` et au hover `var(--shadow-card-hover)`. Le serveur IOT_n3 a des cartes (stat-card, chart-container, quick-link-card) avec des ombres en dur ; l’ajout des variables (fiche 01) permet d’unifier et d’utiliser ces variables ici.

## Snippets

Voir **snippets/cards-shadows-variables.css**. Exemple d’application sur `.stat-card`, `.chart-container`, `.quick-link-card`.

## Cibles serveur

- **IOT_n3/serveur/public/assets/css/theme-variables.css** : s’assurer que `--shadow-card` et `--shadow-card-hover` sont définis (fiche 01).
- **IOT_n3/serveur/public/assets/css/common-data.css** : remplacer les `box-shadow` en dur des cartes par `var(--shadow-card)` et au hover `var(--shadow-card-hover)`, avec un fallback pour compatibilité.

## Prompt d’implémentation

> Une fois les variables `--shadow-card` et `--shadow-card-hover` présentes dans `theme-variables.css`, applique-les aux cartes du serveur : `.stat-card`, `.chart-container`, `.quick-link-card` (et éventuellement `.filter-section`, `.chemistry-chart-card`). Utilise `box-shadow: var(--shadow-card, ...)` avec un fallback (ombre actuelle) et au hover `var(--shadow-card-hover, ...)`. Conserver les transitions existantes (transform, box-shadow) et la cohérence dark mode dans `common-data.css`.
