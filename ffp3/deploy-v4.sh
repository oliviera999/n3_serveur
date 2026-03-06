#!/bin/bash
#
# Script de déploiement FFP3 v4.0.0 sur serveur
# Usage: bash deploy-v4.sh
#

set -e  # Arrêter en cas d'erreur

echo "🚀 Déploiement FFP3 v4.0.0 - Temps Réel & PWA"
echo "=============================================="
echo ""

# 1. Vérifier qu'on est dans le bon dossier
if [ ! -f "composer.json" ]; then
    echo "❌ Erreur: composer.json non trouvé. Êtes-vous dans ffp3/ ?"
    exit 1
fi

echo "📍 Dossier actuel: $(pwd)"
echo ""

# 2. Pull dernières modifications
echo "📥 Pull depuis GitHub..."
git fetch origin
git pull origin main
echo "✅ Code à jour"
echo ""

# 3. Supprimer vendor/ corrompu
echo "🗑️  Suppression vendor/ existant..."
if [ -d "vendor" ]; then
    rm -rf vendor/
    echo "✅ vendor/ supprimé"
else
    echo "⚠️  vendor/ n'existe pas (OK)"
fi
echo ""

# 4. Installer les dépendances
echo "📦 Installation des dépendances Composer..."
composer update --no-dev --optimize-autoloader
echo "✅ Dépendances installées"
echo ""

# 5. Vérifications
echo "🔍 Vérifications..."

# Vérifier PHP-DI
if [ -d "vendor/php-di" ]; then
    echo "✅ PHP-DI installé"
else
    echo "❌ PHP-DI manquant !"
    exit 1
fi

# Vérifier web-push
if [ -d "vendor/minishlink/web-push" ]; then
    echo "✅ web-push installé"
else
    echo "❌ web-push manquant !"
    exit 1
fi

# Vérifier bacon-qr-code
if [ -d "vendor/bacon/bacon-qr-code" ]; then
    echo "✅ bacon-qr-code installé"
else
    echo "❌ bacon-qr-code manquant !"
    exit 1
fi

# Tester autoload
php -r "require 'vendor/autoload.php'; echo 'OK';" 2>&1
if [ $? -eq 0 ]; then
    echo "✅ Autoload fonctionne"
else
    echo "❌ Autoload en erreur !"
    exit 1
fi
echo ""

# 6. Vérifier la version
echo "📌 Version actuelle:"
cat VERSION
echo ""

# 7. Permissions (si nécessaire)
echo "🔧 Ajustement des permissions..."
chmod -R 755 public/ 2>/dev/null || true
chmod -R 775 var/cache/ 2>/dev/null || true
echo "✅ Permissions OK"
echo ""

# 8. Succès
echo "=============================================="
echo "🎉 Déploiement v4.0.0 réussi !"
echo ""
echo "🧪 Testez maintenant:"
echo "   https://iot.olution.info/ffp3/ffp3datas/"
echo ""
echo "📋 Vérifiez:"
echo "   - Badge LIVE s'affiche en haut à droite"
echo "   - Dashboard système affiche les métriques"
echo "   - Aucune erreur PHP"
echo ""
echo "📚 Documentation:"
echo "   - QUICKSTART_V4.md (démarrage rapide)"
echo "   - IMPLEMENTATION_REALTIME_PWA.md (guide technique)"
echo ""
echo "✨ Prochaines étapes (optionnel):"
echo "   - Générer les icônes PWA (voir public/assets/icons/README.md)"
echo "   - Tester l'installation PWA sur mobile"
echo "=============================================="
