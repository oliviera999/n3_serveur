# Maintenance du changelog

Ce dossier contient la politique et les archives du `CHANGELOG.md`.

## Strategie

- `CHANGELOG.md` reste court (fenetre recente).
- Les anciennes entrees sont deplacees dans `docs/changelog/archive/`.
- La verification est automatisee avant commit via le hook `pre-commit`.

## Commandes utiles

Depuis la racine `serveur/` :

```powershell
# Verification stricte (UTF-8, doublons de versions, taille)
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\changelog-maintenance.ps1 -CheckOnly

# Rotation des anciennes entrees vers docs/changelog/archive
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\changelog-maintenance.ps1 -Rotate
```

## Garde-fous

Le script `tools/changelog-maintenance.ps1` controle :

- encodage UTF-8 valide ;
- unicite des versions `## [x.y.z] - YYYY-MM-DD` ;
- taille max configurable (`300KB` par defaut) ;
- rotation automatique (conservation des `40` dernieres entrees par defaut).
