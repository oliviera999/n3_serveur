# 05 — Titres de section avec fond décoratif

## Contexte

Sur **index.olution.info**, les titres de section (`.section-title`) ont un **grand texte en arrière-plan** (élément `<span>` répétant le titre), estompé, qui apparaît au scroll via AOS (classe `.aos-animate`). Voir `style.css` lignes 684–718.

Le serveur IOT_n3 utilise **scroll-reveal.js** et l’attribut `data-aos` ; la classe ajoutée à la révélation est **`.sr-visible`**. Il faut donc utiliser `.sr-visible` sur le conteneur pour révéler le span décoratif.

## Snippets

- **snippets/section-title-decorative.html** — structure HTML exemple.
- **snippets/section-title-decorative.css** — styles (span en position absolute, opacity/scale au parent `.sr-visible`).

## Cibles serveur

- **IOT_n3/serveur/public/assets/css/common-data.css** : ajouter les règles pour `.section-title-decorative` (ou étendre `.section-header` si on préfère une variante).
- **Templates** : sur les pages où l’on veut cet effet (dashboard, page d’accueil), utiliser la structure avec `<span>` dupliquant le titre et les classes `section-title-decorative` + `data-aos="fade-up"`.

## Prompt d’implémentation

> Ajoute un style de titre de section “décoratif” : un grand texte en arrière-plan (dans un `<span>`) qui devient visible quand l’élément entre dans le viewport. Le serveur utilise scroll-reveal.js qui ajoute la classe `.sr-visible` à l’élément ayant `data-aos`. Donc le conteneur doit avoir `data-aos="fade-up"` et la classe `.section-title-decorative` ; le span doit être en `position: absolute`, z-index 1, opacity 0 par défaut, et opacity 0.5 + scale(1.02) quand le parent a `.sr-visible`. Intégrer le CSS dans `common-data.css`. Fournir un exemple de HTML dans un partial ou dans la doc pour les templates qui voudront l’utiliser.
