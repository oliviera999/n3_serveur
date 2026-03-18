# 04 — Liens avec underline animé

## Contexte

Sur **index.olution.info**, les liens dans les zones `.about`, `.contact`, `.services .description`, `.section-title` ont un soulignement qui apparaît au survol (effet scaleX 0→1, `transform-origin` right→left). Voir `style.css` lignes 64–94.

Le serveur IOT_n3 n’applique pas cet effet sur les liens de contenu.

## Snippets

Voir **snippets/liens-underline.css**. Les sélecteurs sont adaptés au serveur : `.hero-data a`, `.section-header a`, `#main .post a`, `.contact a` (à ajuster selon les classes réelles des zones de contenu et du footer).

## Cibles serveur

- **IOT_n3/serveur/public/assets/css/realtime-styles.css** ou **common-data.css** : ajouter les règles (sans cibler les liens de la nav pour éviter les conflits).

## Prompt d’implémentation

> Ajoute un soulignement animé au survol des liens dans le contenu principal : utiliser un `::after` avec `transform: scaleX(0)` par défaut et `scaleX(1)` au `:hover`, `transform-origin: right` puis `left` pour l’animation de gauche à droite. Cibler les liens dans `.hero-data`, `.section-header`, `#main .post` et le footer/contact, sans toucher à la navigation. Transition 0.3s ; respecter `prefers-reduced-motion` (pas de transition si activé).
