# Hooks Git (versionnés)

Ces fichiers sont des **copies** des hooks à installer dans `.git/hooks/` sur le serveur. Git ne versionne pas `.git/hooks/`, donc on garde ici la référence pour installation manuelle.

## Hook disponible

### `post-merge`

Vide automatiquement les caches (Twig, DI, OpCache) après chaque `git pull` ou `git merge`.

## Installation côté serveur

**Méthode recommandée (une commande)** — après un `git pull` :

```bash
# Depuis la racine du projet serveur
bash bin/install-hook-post-merge.sh
```

**Méthode manuelle** :

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

Pour un déploiement avec vidage de cache sans installer le hook : `bash bin/deploy.sh`

### Déploiement par cron (git pull automatique)

Le hook fonctionne aussi lorsque le pull est déclenché par un cron : après un `git pull` réussi, Git exécute le hook et les caches sont vidés. Le cron doit exécuter la commande **depuis la racine du dépôt** (ex. `cd /chemin/vers/serveur && git pull`). Le hook étend le `PATH` pour trouver `php` même en environnement cron minimal.
