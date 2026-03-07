#!/bin/bash
#
# Script de déploiement du serveur unifié n3 IoT avec vidage automatique des caches.
# À exécuter sur le serveur de production (depuis la racine du dépôt serveur).
#
# Usage:
#   cd /chemin/vers/serveur   # ex. /home4/oliviera/iot.olution.info
#   bash bin/deploy.sh
#
# Le vidage des caches est toujours exécuté après le pull.
# Pour un vidage automatique à chaque 'git pull' sans ce script, installez le hook :
#   bash bin/install-hook-post-merge.sh

set -e

echo "=========================================="
echo "🚀 DÉPLOIEMENT serveur n3 IoT + vidage cache"
echo "=========================================="
echo ""

# Vérifier qu'on est à la racine du projet (composer.json présent)
if [ ! -f "composer.json" ]; then
    echo "❌ ERREUR: composer.json non trouvé. Exécutez ce script depuis la racine du projet serveur."
    exit 1
fi

echo "📍 Dossier: $(pwd)"
echo ""

# Étape 1: Pull
echo "📥 [1/4] Pull depuis le dépôt distant..."
git fetch origin
if ! git pull origin; then
    echo "❌ Erreur lors du git pull"
    echo "   Essayez: git status && git pull origin \$(git branch --show-current)"
    exit 1
fi
echo "✅ Code mis à jour"
echo ""

# Étape 2: Vidage des caches (toujours exécuté à chaque déploiement)
echo "🧹 [2/4] Vidage des caches Twig et DI..."
if [ -f "bin/clear-cache.php" ]; then
    if php bin/clear-cache.php; then
        echo "✅ Caches vidés"
    else
        echo "⚠️  Erreur lors du vidage (non bloquant)"
    fi
else
    echo "⚠️  bin/clear-cache.php introuvable, vidage manuel..."
    rm -rf var/cache/twig/* var/cache/di/* 2>/dev/null || true
    echo "✅ Caches vidés manuellement"
fi
echo ""

# Étape 3: Composer install (si besoin)
echo "📦 [3/4] Dépendances Composer..."
if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader 2>/dev/null || true
    echo "✅ Composer à jour"
else
    echo "ℹ️  Composer non appelé (commande absente ou déjà à jour)"
fi
echo ""

# Étape 4: Permissions
echo "🔧 [4/4] Permissions..."
chmod -R 755 public/ 2>/dev/null || true
chmod -R 775 var/cache/ 2>/dev/null || true
chmod +x bin/clear-cache.php bin/deploy.sh bin/install-hook-post-merge.sh 2>/dev/null || true
echo "✅ Terminé"
echo ""

if [ -f "VERSION" ]; then
    echo "📌 Version : $(cat VERSION)"
fi
echo ""
echo "=========================================="
echo "🎉 DÉPLOIEMENT RÉUSSI"
echo "=========================================="
echo "💡 Pour vider les caches à chaque 'git pull' sans exécuter ce script :"
echo "   bash bin/install-hook-post-merge.sh"
echo "=========================================="
