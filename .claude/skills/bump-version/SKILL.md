---
name: bump-version
description: Bump the FFP3 Datas project version (SemVer) and add a CHANGELOG entry. This is MANDATORY after every significant change (feature, bugfix, improvement) to the n3_serveur repo. Use whenever you finish a code change and before committing, or when asked to "bump the version", "update the changelog", or "release".
---

# Bump de version + CHANGELOG (obligatoire)

Le projet exige une incrémentation de version à **chaque** modification significative. La version
vit dans le fichier `VERSION` (racine) et est exposée par `App\Config\Version`.

## Procédure

1. **Lire** la version actuelle dans `VERSION` (format `MAJOR.MINOR.PATCH`, ex. `5.2.9`).
2. **Choisir** l'incrément selon la nature du changement :
   - **MAJOR** (`x.0.0`) : changement incompatible / breaking (ex. structure de BDD, contrat d'API).
   - **MINOR** (`0.x.0`) : nouvelle fonctionnalité rétrocompatible (ex. nouveau graphique, endpoint).
   - **PATCH** (`0.0.x`) : correction de bug, amélioration mineure, perf, style.
3. **Écrire** la nouvelle valeur dans `VERSION` (juste le numéro, ex. `5.3.0`, avec un saut de ligne final).
4. **Ajouter** une entrée en tête de `CHANGELOG.md` : numéro de version, date du jour, description claire
   (suivre le format des entrées existantes du fichier).
5. **Ne pas** coder la version en dur ailleurs — elle est lue dynamiquement par `App\Config\Version::get()`.

## Vérifications

- `VERSION` ne contient que le numéro (pas de préfixe `v`).
- L'entrée `CHANGELOG.md` correspond exactement au nouveau numéro.
- Si plusieurs changements sont groupés dans un même commit, une seule entrée cohérente suffit.

> Rappel : ce bump fait partie intégrante de la modification, ce n'est pas une étape optionnelle.
