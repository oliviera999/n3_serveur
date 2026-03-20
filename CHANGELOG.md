# Changelog FFP3 Datas

Toutes les modifications notables de ce projet sont documentees dans ce fichier.
Le format est base sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
et ce projet adhere a [Semantic Versioning](https://semver.org/lang/fr/).

## Politique de maintenance

- Ce fichier reste volontairement court (fenetre recente).
- Les entrees anciennes sont archivees dans `docs/changelog/archive/`.
- Les garde-fous automatiques sont assures par `tools/changelog-maintenance.ps1`.

## [5.0.206] - 2026-03-20

### Modifie - fermeture lightbox au clic sur l'image
- **Resume** : ajout de la fermeture de la lightbox quand l'utilisateur clique directement sur la photo agrandie, en complement de la fermeture via fond, bouton et touche Echap.

---

## [5.0.205] - 2026-03-20

### Modifie - micro-amelioration UX de la galerie classique
- **Resume** : ajout d'indices visuels de zoom (curseur zoom-in sur vignettes, zoom-out en lightbox) et d'une transition douce au survol pour clarifier l'interaction de clic sur les photos.

---

## [5.0.204] - 2026-03-20

### Corrige - photos cliquables dans les galeries classiques
- **Resume** : restauration du template `gallery.twig` et ajout d'un comportement lightbox (ouverture au clic, navigation precedent/suivant, fermeture via fond ou touche Echap) afin de permettre l'affichage agrandi des photos dans les galeries classiques.

---

## [5.0.203] - 2026-03-20

### Modifie - maintenance durable du changelog (rotation + garde-fous)
- **Resume** : mise en place d'une strategie "rolling window" pour le changelog, ajout d'un script de verification/rotation (UTF-8 strict, doublons de versions, taille max), ajout d'un hook `pre-commit` versionne pour bloquer les anomalies, et documentation du workflow de maintenance.

---

## [5.0.202] - 2026-03-20

### Modifie - harmonisation des hauts de page et titres (audit style global)
- **Resume** : correction de la hierarchie des titres principaux (`h1`) sur dashboard/tide-stats et pages description, suppression des styles inline des titres/headers, externalisation des styles des pages galerie/timelapse/description vers les fichiers CSS, ajout des styles manquants du hero galerie en mode clair, et nettoyage des classes CSS orphelines liees aux en-tetes.

