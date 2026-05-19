# Maintenance du CHANGELOG serveur

Le dépôt applique une stratégie « rolling window » pour conserver un `CHANGELOG.md` court et lisible :

- `CHANGELOG.md` (racine `serveur/`) ne contient que les **40 dernières entrées** (taille cible ≤ 300 KB).
- Les entrées plus anciennes sont **archivées** dans `docs/changelog/archive/CHANGELOG_<plage>.md`.
- Le script PowerShell `tools/changelog-maintenance.ps1` automatise la vérification (UTF-8, doublons, taille) et la rotation.

## Commandes utiles

Depuis `serveur/` :

```powershell
# Vérification stricte (UTF-8, doublons, taille)
composer changelog:check

# Rotation des anciennes entrées vers l'archive
composer changelog:rotate
```

## Hook pre-commit local (optionnel)

Le hook bloque les commits qui :

- introduisent une entrée mal formée dans `CHANGELOG.md` (titre `## [version]` manquant),
- dépassent le seuil de taille configuré,
- ajoutent une entrée dupliquée (même version déjà présente).

Installation :

```bash
bash bin/install-hook-pre-commit.sh
```

Le hook délègue à `tools/changelog-maintenance.ps1 -CheckOnly` (Windows) ou à la version Bash équivalente (POSIX) si disponible.

## Convention d'entrée

Chaque entrée suit ce format (compatible avec `tools/changelog-maintenance.ps1`) :

```
## [X.Y.Z] - YYYY-MM-DD

### Type (Ajout|Modifié|Correctif|Documentation|Sécurité|Refactor)
- **Résumé court** : description en une ou deux phrases du changement.
- Détails optionnels en bullet points.

---
```

- Le `---` final est requis (séparateur de rotation).
- `Type` est libre mais la liste ci-dessus couvre la grande majorité des cas.

## Archivage manuel

Si vous souhaitez archiver une plage explicite (ex. tout < 5.0.250) :

```powershell
# Crée docs/changelog/archive/CHANGELOG_5.0.0-5.0.250.md
composer changelog:rotate
```

Le script déplace en ordre les entrées les plus anciennes, en respectant le seuil cible.

## Références

- Format : [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/)
- Sémantique : [Semantic Versioning](https://semver.org/lang/fr/)
- Cycle de publication serveur : `scripts/publish-cycle.ps1` (à la racine `IOT_n3`)
