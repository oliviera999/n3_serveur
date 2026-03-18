# 02 — Barre de progression au scroll (gradient)

## Contexte

Sur **index.olution.info**, la barre de progression en haut de page utilise un **dégradé** (primary → success) et une hauteur de 4px. Le serveur IOT_n3 a déjà une barre (`#scroll-progress`, `scroll-progress.js`, styles dans `realtime-styles.css`) mais en **couleur unie** (#008B74).

## Snippets

Voir **snippets/scroll-progress-gradient.css**.

## Cibles serveur

- **IOT_n3/serveur/public/assets/css/realtime-styles.css** : remplacer ou compléter les règles de `#scroll-progress.scroll-progress` pour utiliser un `linear-gradient(90deg, var(--accent-primary), var(--accent-primary-hover))` et une hauteur de 4px.

## Prompt d’implémentation

> Dans `realtime-styles.css`, modifie la barre de progression au scroll (`#scroll-progress.scroll-progress`) pour qu’elle utilise un dégradé horizontal : `linear-gradient(90deg, var(--accent-primary), var(--accent-primary-hover))`, avec une hauteur de 4px. Conserve le comportement actuel (width en %, transition, masquage si prefers-reduced-motion).
