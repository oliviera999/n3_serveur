# Timelapse galeries — lecture & export vidéo

**Version** : voir `VERSION` · Dernière mise à jour : 2026-10-26

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
| GET | `/gallery/{slug}/files/{filename}` | JPEG (ETag + Cache-Control) |

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
| Cadence | Encodage plafonné à **30 fps** côté capture (la vitesse de lecture peut être plus haute). Horloge `performance.now()` pour espacer les frames. |
| Mémoire | Préchargement de toutes les images de la plage chargée ; utiliser **Max images** / **1 image sur N** pour les longues périodes. |
| Annulation | Bouton **Annuler** pendant l’export. |
| Images manquantes | Ignorées à l’export (comme en lecture). |
| Résolution | 640 / 800 / 1280 px de large, ou native plafonnée à 1600. |
| Mode démo | Checkbox « Mode démo » : frames synthétiques sans appeler l’API. |

## Fichiers clés

- `src/Controller/Gallery/GalleryViewController.php` — `listPhotos`, `latestPhoto`, `serveImage`
- `public/assets/js/timelapse.js` — lecture + export
- `public/assets/css/timelapse-styles.css` — thème / options export
- `tests/Controller/Gallery/GalleryListPhotosTest.php` — limit / step / meta
