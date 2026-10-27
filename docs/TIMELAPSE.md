# Timelapse galeries — lecture & export vidéo

**Version** : voir `VERSION` · Dernière mise à jour : 2026-10-27

Page : `/gallery/{slug}/timelapse` (`msp1`, `n3pp`, `ffp3`).  
Code UI : `templates/gallery_timelapse.twig` + `public/assets/js/timelapse.js`.

## Architecture

L’export vidéo est **entièrement côté navigateur** :

1. API JSON liste les photos d’une plage.
2. Le JS précharge les JPEG et les affiche sur un canvas.
3. `canvas.captureStream` + `MediaRecorder` produisent un Blob (MP4 ou WebM).
4. Téléchargement local (`n3-timelapse-YYYY-MM-DD.mp4|webm`).

Il n’y a **pas** d’encodage serveur (ffmpeg) : l’hébergement mutualisé ne garantit pas le binaire, et la charge CPU resterait côté prod. Améliorer l’export client reste la voie supportée.

## API

| Méthode | Route | Rôle |
|---------|-------|------|
| GET | `/api/gallery/{slug}/latest` | Bornes (plus récente / plus ancienne) + `count` |
| GET | `/api/gallery/{slug}/photos?from&to[&limit][&step]` | Liste pour le timelapse |
| GET | `/gallery/{slug}/files/{filename}` | JPEG (ETag + Cache-Control) — copie `.normalized/` seulement si l'original est encore dans la galerie |

### Réponse `photos`

```json
{
  "photos": [{ "url": "/gallery/msp1/files/….jpg", "timestamp": "2026-06-01T12:00:00+02:00" }],
  "meta": {
    "matched": 1200,
    "returned": 800,
    "truncated": true,
    "limit": 800,
    "step": 1
  }
}
```

- `limit` : défaut **800**, max **3000**.
- `step` : garder 1 image sur N (défaut 1, max 60) **avant** le plafond `limit`.

## Export navigateur — limites connues

| Sujet | Comportement |
|--------|----------------|
| Format | MP4 H.264 si `MediaRecorder` le propose, sinon WebM (souvent Firefox). Le libellé UI reflète le format réel. |
| Cadence | Plafond d’encode **paramétrable** (15 / 24 / 30 fps, dur max 30). La vitesse de lecture peut être plus haute ; pas de vrai 60 fps à l’export. |
| Mémoire | Préchargement de toutes les images de la plage chargée ; utiliser **Max images** / **1 image sur N** pour les longues périodes. |
| Annulation | Bouton **Annuler** pendant l’export. |
| Images manquantes | Ignorées à l’export (comme en lecture). |
| Résolution | 640 / 800 / 1280 px de large, ou native plafonnée à 1600. |
| Mode démo | Checkbox « Mode démo » : frames synthétiques sans appeler l’API. |
| Conseils UI | Panneau dynamique (ok / warn / danger) + confirmation si charge ≥ ~1000 images ou max ≥ 1500. |

### Presets

| Preset | Max | Step | Résolution | Encode |
|--------|-----|------|------------|--------|
| **Export fiable** | 800 | 1 | 800 px | 24 fps |
| **Longue période** | 1500 | 5 | 640 px | 24 fps |
| **Qualité** | 800 | 1 | 1280 px | 30 fps |

Après un changement de Max / step, **recharger la plage** pour que l’API applique les nouveaux plafonds.

## Normalisation serveur (qualité timelapse)

Les photos conservées (hors corbeille) peuvent être normalisées en douceur
(luminosité, balance des blancs, contraste) par le CRON / Supervision :

- Original intact : `uploads/{slug}/foo.jpg`
- Copie : `uploads/{slug}/.normalized/foo.jpg` (non servie en statique)
- Serving via `/gallery/{slug}/files/…` : préfère la copie **seulement si l'original est encore dans la galerie**. Une photo en corbeille ou supprimée répond 404 ; sa copie est retirée.
- Réglages : Supervision → groupe « Galeries photo » (`GALLERY_NORMALIZE_*`)
- Purge optionnelle des originaux encore présents (remplacement en place + marqueur `.done`). Une copie sans original n'est pas republée.

Voir aussi `docs/deployment/CRON.md` et `src/Service/GalleryNormalizeService.php`.

## Fichiers clés

- `src/Controller/Gallery/GalleryViewController.php` — `listPhotos`, `latestPhoto`, `serveImage`
- `src/Service/GalleryNormalizeService.php` — normalisation douce + batch
- `public/assets/js/timelapse.js` — lecture + export
- `public/assets/css/timelapse-styles.css` — thème / options export
- `tests/Controller/Gallery/GalleryListPhotosTest.php` — limit / step / meta
- `tests/Service/GalleryNormalizeServiceTest.php` — sidecar / anti-retraitement / purge
