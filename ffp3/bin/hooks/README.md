# Hooks Git (versionnés)

Ces fichiers sont des **copies** des hooks à installer dans `.git/hooks/` sur le serveur. Git ne versionne pas `.git/hooks/`, donc on garde ici la référence pour installation manuelle.

## Hook disponible

### `post-merge`

Vide automatiquement les caches (Twig, DI, OpCache) après chaque `git pull` ou `git merge`.

## Installation côté serveur

Après vous être connecté au serveur et avoir fait un `git pull` (pour récupérer ce fichier) :

```bash
# Depuis la racine du projet sur le serveur
cp bin/hooks/post-merge .git/hooks/post-merge
chmod +x .git/hooks/post-merge
```

Vérification :

```bash
ls -la .git/hooks/post-merge
# Doit afficher -rwxr-xr-x ... post-merge
```

Test manuel :

```bash
.git/hooks/post-merge
```

Documentation complète : `docs/deployment/INSTALL_HOOKS.md`
