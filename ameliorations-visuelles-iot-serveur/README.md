# Améliorations visuelles — Serveur IOT n³

Dossier de livrable contenant les éléments et instructions (type prompt) pour améliorer le visuel du serveur **IOT_n3** ([iot.olution.info](https://iot.olution.info)) en s’inspirant des effets modernes d’**index.olution.info** (code dans `moodle/index_olution/`).

## Contenu

- **README.md** (ce fichier) : vue d’ensemble, ordre d’implémentation, conventions.
- **01-variables-css.md** à **10-hero-optionnel.md** : fiches d’implémentation (contexte, snippets, prompt).
- **snippets/** : extraits de code réutilisables (HTML, CSS, JS).

## Ordre d’implémentation recommandé

1. **Variables CSS** (transitions, shadows) — base pour le reste.
2. **Barre de progression en gradient** — changement mineur, impact visuel clair.
3. **Back-to-top** — indépendant, gain UX rapide.
4. **Liens underline animé** — ciblés (contenu principal / footer).
5. **Titres de section décoratifs** — optionnel, à limiter aux pages “vitrine” ou dashboard.
6. **Blobs + section-bg** — décoratif, à appliquer sur sections choisies.
7. **Cards (variables shadow)** — unification avec theme-variables.
8. **Header scroll** et **hero optionnel** — seulement si alignement avec une refonte header/homepage.

## Conventions

- **Cibles serveur** : `IOT_n3/serveur/` (submodule n3_serveur). Fichiers principaux : `templates/layout.twig`, `public/assets/css/theme-variables.css`, `public/assets/css/realtime-styles.css`, `public/assets/css/common-data.css`, `public/assets/js/`.
- **Cohérence** : utiliser les variables existantes (`--accent-primary`, `--text-primary`, etc.) dans `theme-variables.css` et `common-data.css`.
- **Accessibilité** : respecter `prefers-reduced-motion` pour toutes les animations.
- **Périmètre** : le serveur est orienté données/dashboard ; les effets “vitrine” (hero full-bleed, blobs partout) sont à doser.

## Références

- Code index.olution : `moodle/index_olution/` (style.css, main.js, index.php).
- Plan détaillé : voir le plan Cursor « Comparaison visuelle index.olution.info vs serveur IOT_n3 ».
