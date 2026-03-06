#!/bin/bash
#
# Script de déploiement FFP3 v4.0.0 sur serveur
# À exécuter sur le serveur via SSH
#
# Usage:
#   ssh oliviera@toaster
#   cd /home4/oliviera/iot.olution.info/ffp3
#   bash DEPLOY_NOW.sh
#

echo "=========================================="
echo "🚀 DÉPLOIEMENT FFP3"
echo "=========================================="
echo ""

# Vérifier qu'on est dans le bon dossier
if [ ! -f "composer.json" ]; then
    echo "❌ ERREUR: composer.json non trouvé"
    echo "Êtes-vous dans /home4/oliviera/iot.olution.info/ffp3 ?"
    exit 1
fi

echo "📍 Dossier: $(pwd)"
echo ""

# Étape 1: Pull depuis GitHub
echo "📥 [1/6] Pull depuis GitHub..."
git fetch origin
git pull origin main

if [ $? -ne 0 ]; then
    echo "❌ Erreur lors du git pull"
    echo ""
    echo "Essayez de résoudre avec:"
    echo "  git reset --hard origin/main"
    exit 1
fi

echo "✅ Code mis à jour"
echo ""

# Étape 2: Supprimer vendor/ corrompu
echo "🗑️  [2/6] Suppression vendor/ existant..."
if [ -d "vendor" ]; then
    rm -rf vendor/
    echo "✅ vendor/ supprimé"
else
    echo "⚠️  vendor/ n'existe pas (OK)"
fi
echo ""

# Étape 3: Installer les dépendances
echo "📦 [3/6] Installation des dépendances Composer..."
echo "Cela peut prendre 1-2 minutes..."
composer update --no-dev --optimize-autoloader

if [ $? -ne 0 ]; then
    echo "❌ Erreur lors de composer update"
    echo ""
    echo "Essayez:"
    echo "  composer clear-cache"
    echo "  composer update --no-dev"
    exit 1
fi

echo "✅ Dépendances installées"
echo ""

# Étape 4: Créer les liens symboliques pour les assets
echo "🔗 [4/6] Création des liens symboliques..."

# Supprimer d'abord s'ils existent déjà
rm -f assets manifest.json service-worker.js 2>/dev/null

# Créer les liens symboliques
ln -s public/assets assets
ln -s public/manifest.json manifest.json
ln -s public/service-worker.js service-worker.js

if [ $? -eq 0 ]; then
    echo "✅ Liens symboliques créés:"
    echo "   assets -> public/assets"
    echo "   manifest.json -> public/manifest.json"
    echo "   service-worker.js -> public/service-worker.js"
else
    echo "⚠️  Erreur lors de la création des liens symboliques"
    echo "   (peut nécessiter des permissions spéciales)"
fi
echo ""

# Étape 5: Vérifications
echo "🔍 [5/6] Vérifications..."

# PHP-DI
if [ -d "vendor/php-di" ]; then
    echo "  ✅ php-di installé"
else
    echo "  ❌ php-di MANQUANT !"
    exit 1
fi

# web-push
if [ -d "vendor/minishlink" ]; then
    echo "  ✅ web-push installé"
else
    echo "  ⚠️  web-push manquant (optionnel)"
fi

# bacon-qr-code
if [ -d "vendor/bacon" ]; then
    echo "  ✅ bacon-qr-code installé"
else
    echo "  ⚠️  bacon-qr-code manquant (optionnel)"
fi

# Test autoload
php -r "require 'vendor/autoload.php'; echo '';" 2>&1
if [ $? -eq 0 ]; then
    echo "  ✅ Autoload fonctionne"
else
    echo "  ❌ Autoload en ERREUR !"
    exit 1
fi

echo ""

# Étape 6: Vérifier version
echo "📌 [6/6] Version déployée:"
cat VERSION
echo ""

# Permissions
chmod -R 755 public/ 2>/dev/null || true
chmod -R 775 var/cache/ 2>/dev/null || true

echo ""
echo "=========================================="
echo "🎉 DÉPLOIEMENT RÉUSSI !"
echo "=========================================="
echo ""
echo "🧪 TESTEZ MAINTENANT:"
echo ""
echo "  1. Ouvrir navigateur:"
echo "     https://iot.olution.info/ffp3/aquaponie"
echo ""
echo "  2. Vérifier (F12 Console):"
echo "     ✅ Pas d'erreur 404 pour CSS/JS"
echo "     ✅ Badge LIVE devient vert après 15s"
echo "     ✅ Section 'Bilan Hydrique' visible"
echo "     ✅ Footer affiche 'Firmware ESP32: vX.X'"
echo ""
echo "=========================================="
