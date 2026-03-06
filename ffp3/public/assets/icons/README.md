# Icônes PWA pour FFP3 Aquaponie

## Génération des icônes

Les icônes PWA sont nécessaires pour l'installation de l'application en mode standalone sur mobile et desktop.

### Option 1 : Génération automatique (si GD library disponible)

```bash
php generate-icons.php
```

### Option 2 : Génération manuelle recommandée

1. **Créer un logo source** :
   - Format : PNG avec fond transparent ou couleur #008B74
   - Taille minimale recommandée : 1024x1024px
   - Contenu : Logo "FFP3" ou icône représentative

2. **Générer les tailles requises** :
   
   Avec **ImageMagick** :
   ```bash
   # Depuis un fichier source logo-1024.png
   convert logo-1024.png -resize 72x72 icon-72.png
   convert logo-1024.png -resize 96x96 icon-96.png
   convert logo-1024.png -resize 128x128 icon-128.png
   convert logo-1024.png -resize 144x144 icon-144.png
   convert logo-1024.png -resize 152x152 icon-152.png
   convert logo-1024.png -resize 192x192 icon-192.png
   convert logo-1024.png -resize 384x384 icon-384.png
   convert logo-1024.png -resize 512x512 icon-512.png
   ```

   Avec **Photoshop/GIMP** :
   - Ouvrir le logo source
   - Redimensionner pour chaque taille
   - Exporter en PNG (qualité maximale)

### Option 3 : Outils en ligne

- **RealFaviconGenerator** : https://realfavicongenerator.net/
  - Upload votre logo
  - Génère automatiquement toutes les tailles
  - Télécharge un package complet

- **PWA Asset Generator** : https://www.pwabuilder.com/imageGenerator
  - Upload votre image
  - Génère icônes + splash screens
  - Format optimisé pour PWA

## Tailles requises

| Taille | Usage |
|--------|-------|
| 72x72 | Badge, raccourcis |
| 96x96 | Raccourcis, Android |
| 128x128 | Chrome Web Store |
| 144x144 | Windows |
| 152x152 | iOS iPad |
| 192x192 | Android home screen |
| 384x384 | Large Android screens |
| 512x512 | Splash screens, store listings |

## Design recommandé

- **Couleur de fond** : #008B74 (vert olution)
- **Texte/Logo** : Blanc (#FFFFFF)
- **Police** : Sans-serif, bold
- **Contenu** : "FFP3" ou icône poisson + plante
- **Padding** : 10-15% pour éviter la troncature sur iOS
- **Format** : PNG (pas de JPEG pour éviter artefacts)

## Vérification

Après génération, vérifiez que :
- [ ] Tous les fichiers icon-*.png sont présents (8 fichiers)
- [ ] Les tailles correspondent exactement (pas d'approximation)
- [ ] Les images sont nettes (pas de flou)
- [ ] Le design est visible sur fond blanc ET coloré
- [ ] Le fichier manifest.json pointe vers les bonnes icônes

## Test

1. Ouvrez https://votre-domaine/ffp3/ffp3datas/ sur Chrome mobile
2. Menu > "Installer l'application"
3. Vérifiez que l'icône s'affiche correctement
4. Testez le lancement depuis l'écran d'accueil

## Icônes temporaires

Si vous ne pouvez pas générer les icônes immédiatement, créez des icônes placeholder simples :

```bash
# Créer des icônes unicolores simples (requires ImageMagick)
for size in 72 96 128 144 152 192 384 512; do
  convert -size ${size}x${size} xc:#008B74 \
    -font Arial -pointsize $((size/4)) -fill white -gravity center \
    -annotate +0+0 "FFP3" icon-${size}.png
done
```

## Support

Pour plus d'aide, consultez :
- https://web.dev/add-manifest/
- https://developer.mozilla.org/en-US/docs/Web/Manifest

