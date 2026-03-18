# 01 — Variables CSS (transitions, shadows)

## Contexte

Sur **index.olution.info**, le fichier `assets/css/style.css` définit en `:root` des variables d’animation et d’ombres utilisées partout (hero, cartes, boutons) :

- `--transition-smooth`, `--transition-bounce`
- `--shadow-card`, `--shadow-card-hover`

Le serveur IOT_n3 a déjà des variables sémantiques dans `theme-variables.css` (couleurs, fonds) mais pas ces variables d’animation/ombres.

## Snippets

Voir **snippets/variables-animations.css**.

## Cibles serveur

- **IOT_n3/serveur/public/assets/css/theme-variables.css** : ajouter les variables dans le bloc `:root` (et éventuellement les redéfinir dans `[data-theme="dark"]` si besoin).

## Prompt d’implémentation

> Dans `theme-variables.css`, ajoute dans `:root` les variables CSS suivantes (inspirées d’index.olution) : `--transition-smooth: 0.3s cubic-bezier(0.4, 0, 0.2, 1)`, `--transition-bounce: 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)`, `--shadow-card: 0 10px 29px 0 rgba(68, 88, 144, 0.1)`, `--shadow-card-hover: 0 16px 40px 0 rgba(68, 88, 144, 0.18)`. Tu peux adapter les couleurs des ombres pour rester cohérent avec la palette n³ (teinte verte). Ne pas casser les variables existantes.
