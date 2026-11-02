# Timelapse galeries — lecture & export vidéo

**Version** : voir `VERSION` · Dernière mise à jour : 2026-11-02

Page : `/gallery/{slug}/timelapse` (`msp1`, `n3pp`, `ffp3`).  
Code UI : `templates/gallery_timelapse.twig` + `public/assets/js/timelapse.js`
(chargé avec `?v=<version>` : un déploiement invalide le cache navigateur de 30 jours).

## Architecture

L’export vidéo est **entièrement côté navigateur** :

1. API JSON liste les photos d’une plage (échantillonnage uniforme côté serveur).
2. Le JS précharge les JPEG et les affiche sur un canvas.
3. `canvas.captureStream(0)` + `requestFrame()` + `MediaRecorder` produisent un Blob (MP4 ou WebM) :
   chaque image n’est capturée qu’une fois dessinée (repli `captureStream(fps)` si `requestFrame`
   est indisponible). Réf. MDN : [CanvasCaptureMediaStreamTrack.requestFrame](https://developer.mozilla.org/docs/Web/API/CanvasCaptureMediaStreamTrack/requestFrame).
4. Téléchargement local (`n3-timelapse-YYYY-MM-DD.mp4|webm`).

Il n’y a **pas** d’encodage serveur (ffmpeg) : l’hébergement mutualisé ne garantit pas le binaire, et la charge CPU resterait côté prod. Améliorer l’export client reste la voie supportée.

## Plages rapides

Boutons **1 j · 1 sem · 2 sem · 1 mois · 6 mois** (chargement immédiat), se terminant à la
dernière photo. Les mois sont **calendaires** ; le début est ramené à la plus ancienne photo
disponible si besoin (signalé dans le bandeau). Défaut à l’ouverture : **1 j** (repli sur toute
la galerie si vide). La saisie manuelle Début / Fin + « Charger la plage » reste possible.

Un **bandeau** sous la visionneuse (hors panneau replié) indique la couverture réelle :
« 6 mois · 800 images affichées sur 26 280 photos (1 sur ~32,9, réparties sur toute la plage) ».

## API

| Méthode | Route | Rôle |
|---------|-------|------|
| GET | `/api/gallery/{slug}/latest` | Bornes (plus récente / plus ancienne) + `count` |
| GET | `/api/gallery/{slug}/photos?from&to[&limit][&step]` | Liste pour le timelapse |
| GET | `/gallery/{slug}/files/{filename}[?v=]` | JPEG (ETag) — sert la copie `.normalized/` courante si présente |

### Réponse `photos`

```json
{
  "photos": [{ "url": "/gallery/msp1/files/….jpg?v=6ae87102-c99", "timestamp": "2026-06-01T12:00:00+02:00" }],
  "meta": {
    "matched": 26280,
    "returned": 800,
    "truncated": true,
    "limit": 800,
    "step": 1,
    "effective_step": 32.9
  }
}
```

- `limit` : défaut **800**, max **3000**.
- `step` : ~1 image sur N (défaut 1, max 60).
- **Échantillonnage uniforme** : les photos retenues sont réparties sur **toute** la plage
  (première et dernière incluses). Si `step` donnerait plus de `limit` images, le pas est
  augmenté automatiquement (`truncated: true`, `effective_step` = pas réel) — la fin de la
  plage n’est plus coupée.
- Tri chronologique numérique (correct au changement d’heure).

### Cache des images

- URL versionnée `?v=<mtime-taille du fichier servi>` (fournie par `photos`) :
  `Cache-Control: public, max-age=31536000, immutable`. Quand le CRON produit la copie
  normalisée, le jeton change → nouvelle URL : jamais de mélange ancienne / nouvelle version.
- URL sans `v` (grille, landing) : `public, max-age=3600` + `ETag` (304 si `If-None-Match`
  correspond, listes et ETags faibles `W/` acceptés).
- Photo dont l’original a disparu (corbeille) : **404**, même s’il reste une copie normalisée.

## Export navigateur

| Sujet | Comportement |
|--------|----------------|
| Format | MP4 si `MediaRecorder` le propose, sinon WebM (souvent Firefox). « MP4 H.264 » n’est affiché que si le codec `avc1` est explicitement supporté (Chromium sans codecs propriétaires met du VP9 dans le MP4). |
| Cadence | Encode = min(vitesse de lecture, plafond 15 / 24 / 30 fps — dur max 30). |
| Rythme | Horloge `performance.now()` ; en cas de retard, l’horloge est recalée (pas de rafale d’images perdues). |
| Arrière-plan | Onglet caché → encodeur **en pause** (`MediaRecorder.pause()`), reprise au retour. |
| Mémoire | Préchargement (4 en parallèle, délai max 20 s par image) des images de la plage chargée. |
| Annulation | Bouton **Annuler** (visible seulement pendant l’export). |
| Erreur encodeur | Export interrompu, **aucun** fichier tronqué téléchargé. |
| Images manquantes | Ignorées à l’export (comme en lecture). |
| Résolution | 640 / 800 / 1280 px ou native (max 1600) — **jamais d’agrandissement** au-delà de la source. |
| Mode démo | Checkbox « Mode démo » : frames synthétiques sans appeler l’API. |
| Conseils UI | Panneau ok / warn / danger calculé sur les images **réellement chargées** ; charge = images × largeur / 800 ; confirmation si charge ≥ 1000 (≥ 2000 : danger). Mis à jour seulement quand son contenu change (zone `aria-live`). |
| Réglages | Changer **Max images** ou **1 image sur** recharge la plage automatiquement. |
| Vitesse | Boutons en images/seconde : 2 · 12 · 24 · 30 · 60 · 120. |
| Mode live | « Dernière image » : vérification immédiate puis chaque minute ; les bornes sont relues (nouvelles photos ajoutées en fin de plage). |

### Presets

| Preset | Max | Step | Résolution | Encode |
|--------|-----|------|------------|--------|
| **Export fiable** | 800 | 1 | 800 px | 24 fps |
| **Longue période** | 1200 | 5 | 640 px | 24 fps |
| **Qualité** | 600 | 1 | 1280 px | 30 fps (lecture réglée à 30 fps) |

Aucun preset ne déclenche à lui seul l’alerte de charge (charge ≤ 960 équivalent 800 px).

## Normalisation serveur (qualité timelapse) — algorithme v2

Les photos conservées (hors corbeille) sont normalisées en douceur par le CRON / Supervision :

- Original intact : `uploads/{slug}/foo.jpg`
- Copie : `uploads/{slug}/.normalized/foo.jpg` (+ EXIF de l’original recopié)
- Méta : `uploads/{slug}/.normalized/foo.jpg.v2.json` (statut, paramètres appliqués).
  Une copie **sans méta de la version courante** (ex. produite par l’algorithme v1 du 26/10)
  est **périmée** : ni servie ni promue, recalculée automatiquement.
- Serving transparent via `/gallery/{slug}/files/…`
- Réglages : Supervision → groupe « Galeries photo » (`GALLERY_NORMALIZE_*`)

Pipeline (`src/Service/GalleryNormalizeService.php`) :

1. Statistiques sur une grille **déterministe** 50×50 (plus d’aléatoire).
2. Photo **de nuit** (luma moyenne < 32) : laissée telle quelle (méta `skipped_dark`).
3. Balance des blancs **gray-world robuste** : seulement les pixels quasi neutres (saturation
   faible, ni bouchés ni brûlés) ; pas de correction si la scène n’en contient pas assez
   (végétation). Gains bornés ±12 %. Inspiration : Huo et al., *Robust automatic white balance
   algorithm using gray color points in images*, IEEE TCE 52(2), 2006.
4. Luminosité : décalage borné ±10 %.
5. Contraste : **auto-levels partiel** calculé après gains / deflicker / luminosité, facteur
   d’étirement plafonné à ×1,35 (une image peu contrastée n’est plus amplifiée ×15).
6. **Lissage temporel (deflicker)** : statistiques moyennées sur 5 photos voisines (poids
   triangulaires, écart ≤ 2 h, nuits exclues) + gain d’exposition par photo (±15 %) vers la
   luminance de la fenêtre. Principe du filtre [`deflicker` de FFmpeg](https://ffmpeg.org/ffmpeg-filters.html#deflicker).
7. Application par **tables de correspondance 3×256** bornées à [0, 255] (plus aucun débordement
   de canal : la v1 noircissait les hautes lumières et les couleurs saturées), puis mélange avec
   l’original selon la force (défaut 0,35). ~6× plus rapide que la v1.

Batch : **plus récentes d’abord**, 20 photos max par passage, **budget de temps 25 s** (le CRON
tourne dans le verrou de l’orchestrateur qui émet les alertes chaque minute ; le batch manuel
s’arrête avant `max_execution_time`). Intervalle par défaut **10 min**.

### Purge des originaux (Supervision, irréversible)

- Remplace l’original par sa copie **courante** (rename atomique) et pose `foo.jpg.done`.
- Copie **orpheline** (original absent : photo en corbeille / supprimée) → copie supprimée,
  jamais « ressuscitée » dans la galerie.
- Copie **périmée** (algorithme antérieur) → ignorée.
- Perte de génération JPEG inhérente (réencodage qualité 90 par défaut).

### Corbeille

Mise en corbeille → copie + méta supprimées (la photo n’est plus servie ; recalculée si
restauration). Suppression définitive → marqueur `.done` retiré.

Voir aussi `docs/deployment/CRON.md`.

## Fichiers clés

- `src/Controller/Gallery/GalleryViewController.php` — `listPhotos`, `latestPhoto`, `serveImage`
- `src/Service/GalleryNormalizeService.php` — normalisation v2 + batch + purge
- `src/Service/GalleryNormalizedStore.php` — emplacements `.normalized/` (partagé avec la corbeille)
- `src/Util/GalleryTimestamp.php` — date de capture d’après le nom de fichier
- `public/assets/js/timelapse.js` — lecture + export
- `public/assets/css/timelapse-styles.css` — thème / options export
- `tests/Controller/Gallery/GalleryListPhotosTest.php` — échantillonnage uniforme / meta / `?v=`
- `tests/Service/GalleryNormalizeServiceTest.php` — exactitude couleur, nuit, deflicker, batch, purge, EXIF
