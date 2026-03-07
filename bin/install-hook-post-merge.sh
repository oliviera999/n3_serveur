#!/bin/sh
#
# Installe le hook Git post-merge pour vider automatiquement les caches
# après chaque 'git pull'. À exécuter une seule fois sur le serveur (ou après
# une mise à jour du hook dans le dépôt).
#
# Usage: bash bin/install-hook-post-merge.sh
#        (depuis la racine du projet serveur)

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
HOOK_SOURCE="$PROJECT_ROOT/bin/hooks/post-merge"
HOOK_DEST="$PROJECT_ROOT/.git/hooks/post-merge"

if [ ! -f "$HOOK_SOURCE" ]; then
    echo "❌ Fichier source introuvable : bin/hooks/post-merge"
    exit 1
fi

if [ ! -d "$PROJECT_ROOT/.git" ]; then
    echo "❌ Ce répertoire n'est pas un dépôt Git : .git/ introuvable"
    exit 1
fi

cp "$HOOK_SOURCE" "$HOOK_DEST"
chmod +x "$HOOK_DEST"
echo "✅ Hook post-merge installé : .git/hooks/post-merge"
echo "   À chaque 'git pull', les caches (Twig, DI) seront vidés automatiquement."
echo ""
echo "Test manuel : .git/hooks/post-merge"
