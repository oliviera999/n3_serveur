# 03 — Back-to-top

## Contexte

Sur **index.olution.info**, un bouton fixe en bas à droite permet de remonter en haut de page ; il n’apparaît qu’après un scroll > 100px (classe `.active`). Références : `index.php` ligne ~1040, `style.css` lignes 119–148, `main.js` (handler scroll qui toggles `.active`).

Le serveur IOT_n3 n’a pas ce bouton.

## Snippets

- **snippets/back-to-top.html** — à insérer avant `</body>` dans `layout.twig`.
- **snippets/back-to-top.css** — styles (couleur accent, hover, `.active`).
- **snippets/back-to-top.js** — toggle `.active` au scroll, clic → `scrollTo({ top: 0, behavior: 'smooth' })`, respect de `prefers-reduced-motion`.

## Cibles serveur

- **IOT_n3/serveur/templates/layout.twig** : ajouter le HTML du bouton avant la fermeture de `</body>`.
- **IOT_n3/serveur/public/assets/css/realtime-styles.css** (ou theme-variables.css) : ajouter les styles.
- **IOT_n3/serveur/public/assets/js/** : nouveau fichier `back-to-top.js` (ou intégration dans un script existant chargé sur toutes les pages), puis l’inclure dans `layout.twig` après les scripts vendor.

## Prompt d’implémentation

> Ajoute un bouton “back to top” fixe en bas à droite, visible quand le scroll dépasse 100px, avec transition d’opacité/visibility, icône flèche vers le haut (Font Awesome `fa-arrow-up`), couleur `var(--accent-primary)` (et hover avec `var(--accent-primary-hover)`). Intègre le HTML dans `layout.twig` avant `</body>`, le CSS dans `realtime-styles.css`, et le JS dans un fichier `back-to-top.js` chargé dans le layout. Au clic, faire un `scrollTo({ top: 0, behavior: 'smooth' })`. Respecte `prefers-reduced-motion` (masquer ou désactiver les transitions).
