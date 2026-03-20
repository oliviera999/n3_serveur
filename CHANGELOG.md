# Changelog FFP3 Datas

Toutes les modifications notables de ce projet sont documentees dans ce fichier.
Le format est base sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et ce projet adhere a [Semantic Versioning](https://semver.org/lang/fr/).

## Politique de maintenance

- Ce fichier reste volontairement court (fenetre recente).
- Les entrees anciennes sont archivees dans `docs/changelog/archive/`.
- Les garde-fous automatiques sont assures par `tools/changelog-maintenance.ps1`.
- Rotation recommandee : conserver les 40 dernieres entrees, taille cible <= 300KB.

## [5.0.214] - 2026-03-20

### Correctif - timelapse sans frames grises sur images manquantes
- **Resume** : les images introuvables (supprimees ou deplacees en corbeille) sont retirees de la sequence du timelapse au chargement, en lecture et en navigation manuelle. Les frames de fallback grises ne sont plus affichees.

---

## [5.0.211] - 2026-03-20

## [5.0.212] - 2026-03-20

### Corrige - authentification requise sur les uploads photo ESP32-CAM
- **Resume** : durcissement de `GalleryUploadController` avec verification obligatoire du header `X-Api-Key` contre `API_KEY` serveur, en mode fail-closed. Les endpoints `/msp1gallery/upload.php`, `/n3ppgallery/upload.php` et `/ffp3/ffp3gallery/upload.php` retournent desormais `401` si la cle est absente/invalide.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.211] - 2026-03-20

### Ajout - acces central corbeille + tri global des galeries
- **Resume** : ajout d'une tuile "Galerie trash (toutes)" dans la supervision (route `/admin/gallery-trash`) et d'une action admin "Lancer le tri global" qui rejoue en un clic l'algorithme de tri qualite sur toutes les galeries (MSP1, N3PP, FFP3) via le nouvel endpoint `/admin/api/gallery/auto-sort-all`. Le tri reste actif a l'upload et peut maintenant aussi etre force manuellement sur l'existant.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.210] - 2026-03-20

### Modifie - Factorisation et unification du code serveur
- **Resume** : montee des methodes dupliquees `updateByGpio` et `getOutputByGpioAndBoard` dans `AbstractOutputRepository`. Ajout de `normalizeOutputState()` dans `AbstractOutputController`. Extraction de 3 partials Twig communs (`_filter_health_row.twig`, `_set_period_js.twig`, `_control_init_js.twig`) pour supprimer les doublons entre pages MSP1/N3PP. Creation de `GalleryConfig` centralisant slugs, labels, env keys et repertoires des galeries. Nettoyage des imports inutilises. Aucun changement d'URL, de contrat API, ni de rendu visuel.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.209] - 2026-03-20

### Modifie - Audit complet du mode sombre
- **Resume** : migration CSS vers variables semantiques, dark mode complet pour main.css, module-description-styles.css, aquaponie.css, supervision, timelapse, control, home, common-data. Creation de chartjs-theme.js pour le theming Chart.js. Remplacement des couleurs en dur et background:white par des variables. Unification des variables locales avec les variables globales. Ajout fallback @media prefers-color-scheme pour navigateurs sans JS. Ajout prefers-reduced-motion pour l'accessibilite. Suppression des !important superflus.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.208] - 2026-03-20

### Ajout - 4 especes chimiques supplementaires dans les parametres surveilles aquaponie
- **Resume** : ajout de l'oxygene dissous (O2), des phosphates (PO4), du potassium (K+) et du fer (Fe2+/Fe3+) dans la section « Parametres surveilles » des pages aquaponie (templates aquaponie.twig et aquaponie_alt.twig), avec styles CSS dedies (mode clair et sombre).

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.207] - 2026-03-20

### Modifie - maintenance du changelog et garde-fous automatises
- **Resume** : mise en place de la maintenance durable du changelog (script de verification UTF-8, detection des doublons de version, limite de taille, rotation vers archive), ajout d'un hook `pre-commit` versionne, commandes composer dediees et documentation du workflow.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.206] - 2026-03-20

### Modifie - fermeture lightbox au clic sur l'image
- **Resume** : ajout de la fermeture de la lightbox quand l'utilisateur clique directement sur la photo agrandie, en complement de la fermeture via fond, bouton et touche Echap.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.205] - 2026-03-20

### Ajout - corbeille photo admin avec tri automatique
- **Resume** : ajout d'une corbeille photo pour les galeries ESP32-CAM (msp1, n3pp, ffp3), accessible uniquement depuis la page supervision (admin). Les photos trop claires, trop sombres ou corrompues sont automatiquement classees en corbeille a l'upload (analyse de luminosite GD). L'interface admin permet la restauration (unitaire, par selection, ou totale) et la suppression definitive. Nouveau service GalleryTrashService, controleur GalleryTrashController, template gallery_trash.twig et routes sous /admin/gallery/{slug}/trash.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.204] - 2026-03-20

### Modifie - micro-amelioration UX de la galerie classique
- **Resume** : ajout d'indices visuels de zoom (curseur zoom-in sur vignettes, zoom-out en lightbox) et d'une transition douce au survol pour clarifier l'interaction de clic sur les photos.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.203] - 2026-03-20

### Corrige - photos cliquables dans les galeries classiques
- **Resume** : restauration du template `gallery.twig` et ajout d'un comportement lightbox (ouverture au clic, navigation precedent/suivant, fermeture via fond ou touche Echap) afin de permettre l'affichage agrandi des photos dans les galeries classiques.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.202] - 2026-03-20

### Modifie - maintenance durable du changelog (rotation + garde-fous)
- **Resume** : mise en place d'une strategie "rolling window" pour le changelog, ajout d'un script de verification/rotation (UTF-8 strict, doublons de versions, taille max), ajout d'un hook `pre-commit` versionne pour bloquer les anomalies, et documentation du workflow de maintenance.

---

## [5.0.213] - 2026-03-20

### Correctif - Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos
- **Résumé** : Audit UX approfondi : corrections P0/P1/P2 accessibilite, ARIA, contrastes, modale confirmation, focus trap, skip link, copyright dynamique, typos.

---
## [5.0.201] - 2026-03-20

### Modifie - harmonisation des hauts de page et titres (audit style global)
- **Resume** : correction de la hierarchie des titres principaux (`h1`) sur dashboard/tide-stats et pages description, suppression des styles inline des titres/headers, externalisation des styles des pages galerie/timelapse/description vers les fichiers CSS, ajout des styles manquants du hero galerie en mode clair, et nettoyage des classes CSS orphelines liees aux en-tetes.

