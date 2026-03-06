#!/bin/bash
# Script de diagnostic de la structure du serveur
# À exécuter sur le serveur pour comprendre la configuration

echo "=========================================="
echo "🔍 DIAGNOSTIC STRUCTURE SERVEUR FFP3"
echo "=========================================="
echo ""

# 1. Dossier actuel
echo "📍 Dossier actuel:"
pwd
echo ""

# 2. Version déployée
echo "📌 Version actuellement déployée:"
if [ -f "VERSION" ]; then
    cat VERSION
else
    echo "❌ Fichier VERSION non trouvé"
fi
echo ""

# 3. Structure des dossiers
echo "📁 Structure des dossiers (niveau racine):"
ls -lh | grep "^d"
echo ""

# 4. Vérifier si public/assets existe
echo "📂 Contenu de public/:"
if [ -d "public" ]; then
    ls -lh public/
else
    echo "❌ Dossier public/ non trouvé"
fi
echo ""

# 5. Vérifier si public/assets existe
echo "📂 Contenu de public/assets/:"
if [ -d "public/assets" ]; then
    ls -lh public/assets/
else
    echo "❌ Dossier public/assets/ non trouvé"
fi
echo ""

# 6. Derniers commits Git
echo "📝 Derniers commits:"
git log --oneline -5
echo ""

# 7. Status git
echo "🔄 Status Git:"
git status --short
echo ""

# 8. Vérifier si vendor existe
echo "📦 Dépendances Composer:"
if [ -d "vendor" ]; then
    echo "✅ vendor/ existe"
    if [ -f "vendor/autoload.php" ]; then
        echo "✅ vendor/autoload.php existe"
    else
        echo "❌ vendor/autoload.php MANQUANT"
    fi
else
    echo "❌ vendor/ MANQUANT"
fi
echo ""

# 9. Configuration Apache (si accessible)
echo "🌐 Configuration Document Root (si .htaccess existe):"
if [ -f ".htaccess" ]; then
    echo "✅ .htaccess racine existe:"
    head -n 5 .htaccess
else
    echo "⚠️  Pas de .htaccess à la racine"
fi
echo ""

if [ -f "public/.htaccess" ]; then
    echo "✅ public/.htaccess existe:"
    head -n 5 public/.htaccess
else
    echo "⚠️  Pas de .htaccess dans public/"
fi
echo ""

echo "=========================================="
echo "✅ Diagnostic terminé"
echo "=========================================="
echo ""
echo "📤 Envoyez la sortie complète de ce script"

