# 06 — Blobs décoratifs en arrière-plan de sections

## Contexte

Sur **index.olution.info**, certaines sections ont une forme organique en `::before` (grand cercle déformé avec `border-radius` en pourcentages variés) en arrière-plan, pour donner de la profondeur. Ex. `#about::before` (haut droite), `#results::before` (bas gauche). Voir `style.css` lignes 754–768 et 644–654.

Le serveur IOT_n3 n’a pas ces formes décoratives.

## Snippets

Voir **snippets/blobs-section.css**. Deux variantes : `.blob-top-right` et `.blob-bottom-left`, à appliquer avec la classe `.section-with-blob` sur un conteneur de section.

## Cibles serveur

- **IOT_n3/serveur/public/assets/css/common-data.css** ou **realtime-styles.css** : ajouter les règles. Adapter les couleurs (rgba avec teinte #008B74 / var(--accent-primary)) pour la cohérence n³.

## Prompt d’implémentation

> Ajoute des “blobs” décoratifs en arrière-plan pour certaines sections : une classe `.section-with-blob` avec `position: relative` et `overflow: hidden`, et des variantes `.blob-top-right` / `.blob-bottom-left` qui ajoutent un `::before` (grande forme avec border-radius organique, couleur rgba accent très légère, z-index 0). Les enfants directs de la section doivent avoir `position: relative; z-index: 1`. Intégrer le CSS dans `common-data.css`. Appliquer ces classes uniquement sur les sections choisies (ex. encart synthèse, dashboard) pour ne pas surcharger les pages de données.
