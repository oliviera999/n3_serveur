# 07 — Section fond motif (pointillé discret)

## Contexte

Sur **index.olution.info**, la classe `.section-bg` applique un fond gris clair avec un **motif pointillé** (radial-gradient en cercle 1px, répété en grille 24×24px). Voir `style.css` lignes 619–624.

Le serveur IOT_n3 n’a pas ce motif sur les fonds de section.

## Snippets

Voir **snippets/section-bg-motif.css**. Une classe `.section-bg-motif` avec variables pour light/dark.

## Cibles serveur

- **IOT_n3/serveur/public/assets/css/common-data.css** : ajouter la classe `.section-bg-motif` (et variante dark avec `[data-theme="dark"]`).

## Prompt d’implémentation

> Ajoute une classe `.section-bg-motif` qui donne à une section un fond avec un motif pointillé discret : `background-color` avec `var(--bg-secondary)`, et `background-image: radial-gradient(circle at 1px 1px, rgba(0,0,0,0.03) 1px, transparent 0)` avec `background-size: 24px 24px`. En dark mode (`[data-theme="dark"]`), adapter la couleur du point pour rester subtile. Intégrer dans `common-data.css`. Utiliser cette classe sur les sections où un fond alternatif est souhaité (ex. encarts, dashboard).
