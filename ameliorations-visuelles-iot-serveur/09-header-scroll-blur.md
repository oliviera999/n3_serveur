# 09 — Header au scroll (blur, optionnel)

## Contexte

Sur **index.olution.info**, le header est transparent en haut puis passe en mode “scrolled” après ~100px : hauteur réduite (80px→60px), fond semi-transparent et **backdrop-filter: blur(12px)**. Voir `style.css` lignes 153–169 et `main.js` (toggle `.header-scrolled`).

Le serveur IOT_n3 a une structure de header différente (Massively : #header + #nav). Cette amélioration a un **impact layout plus fort** et peut nécessiter des ajustements (hauteur, position du logo, nav).

## Snippets

Voir **snippets/header-scroll-blur.css**. Exemple minimal ; à adapter selon la structure réelle du header (id, classes).

## Cibles serveur

- **IOT_n3/serveur/public/assets/css/main.css** ou un fichier dédié (ex. realtime-styles.css) : ajouter les règles pour `#header.header-scrolled`.
- **JS** : s’assurer qu’un script (ex. existant ou nouveau) ajoute/retire la classe `header-scrolled` sur `#header` selon `window.scrollY > 100`. Le serveur a déjà `scroll-progress.js` ; on peut étendre le même handler ou un script commun.

## Prompt d’implémentation

> (Optionnel.) Fais réagir le header au scroll : au-delà de 100px, ajouter la classe `header-scrolled` sur `#header` et appliquer un fond semi-transparent avec `backdrop-filter: blur(12px)`. Adapter la structure actuelle du header (Massively) sans casser la nav : vérifier les sélecteurs dans `main.css` et les media queries. Si le header n’a pas de hauteur variable sur index.olution, on peut se limiter au blur + fond. Documenter les changements dans le CHANGELOG du serveur.
