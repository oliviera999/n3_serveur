#!/bin/sh
#
# Installe le hook Git pre-commit versionne du depot
#

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
HOOK_SOURCE="$PROJECT_ROOT/bin/hooks/pre-commit"
HOOK_DEST="$PROJECT_ROOT/.git/hooks/pre-commit"

if [ ! -f "$HOOK_SOURCE" ]; then
    echo "❌ Fichier source introuvable : bin/hooks/pre-commit"
    exit 1
fi

if [ ! -d "$PROJECT_ROOT/.git" ]; then
    echo "❌ Ce repertoire n'est pas un depot Git : .git/ introuvable"
    exit 1
fi

cp "$HOOK_SOURCE" "$HOOK_DEST"
chmod +x "$HOOK_DEST"

echo "✅ Hook pre-commit installe : .git/hooks/pre-commit"
echo "   Verification automatique du changelog activee avant commit."
