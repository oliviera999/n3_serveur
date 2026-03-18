# 10 — Hero optionnel (image full-bleed, stagger, optionnel)

## Contexte

Sur **index.olution.info**, le hero occupe 100vh, avec image de fond aléatoire (PHP), overlay en gradient, animation **Ken Burns** (lent zoom/déplacement), et entrées **décalées** (heroStagger) pour le titre, logo, sous-titre, boutons. Voir `style.css` (#hero) et `index.php` (hero container).

Le serveur IOT_n3 a un hero “modern-header” (gradient + motif SVG) adapté aux pages de données, sans image full-bleed. Cette fiche sert pour une **future page d’accueil ou vitrine** du site IoT, pas pour les pages dashboard existantes.

## Snippets

Les extraits pertinents sont dans **moodle/index_olution/** :

- **Hero CSS** : `assets/css/style.css` — `#hero`, `#hero:before`, `.hero-container`, `.hero-title` à `.btn-scroll`, keyframes `heroFadeIn`, `kenBurns`, `heroStagger`, `up-down`.
- **Hero HTML** : `index.php` — section `#hero`, `.hero-container`, titres, boutons, lien scroll.
- **JS** : pas d’init spécifique hero (animations en CSS).

Pour le serveur, il faudrait : une route/page dédiée (ex. homepage), un template Twig avec une section hero optionnelle, un bloc CSS (dans un fichier ou partial) pour ne pas alourdir les pages données, et éventuellement une image par défaut ou un fond gradient si pas d’image dynamique.

## Cibles serveur

- **Nouvelle page ou template** : par ex. `templates/home.twig` ou variante de layout avec bloc hero full-bleed.
- **CSS** : nouveau fichier ou bloc dans realtime-styles.css (scopé à une classe type `.hero-full-bleed`).
- **Images** : dossier `public/assets/img/hero/` avec une ou plusieurs images, ou utilisation d’un gradient uniquement.

## Prompt d’implémentation

> (Optionnel, pour une future homepage.) Crée une variante de hero “full-bleed” pour une page d’accueil : section en 100vh, image de fond (ou gradient), overlay en gradient, titre/sous-titre avec animations d’entrée décalées (opacity + translateY), et optionnellement une animation Ken Burns sur le background (léger zoom/position en keyframes). Utilise des variables CSS du serveur pour les couleurs. Respecter `prefers-reduced-motion` (désactiver ou simplifier les animations). Ne pas modifier le hero actuel des pages données (modern-header).
