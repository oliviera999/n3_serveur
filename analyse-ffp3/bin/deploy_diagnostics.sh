#!/bin/bash

# Script de déploiement des outils de diagnostic
# Usage: ./deploy_diagnostics.sh

echo "=========================================="
echo "DÉPLOIEMENT OUTILS DIAGNOSTIC FFP3"
echo "Date: $(date)"
echo "=========================================="

# Vérifier qu'on est dans le bon répertoire
if [ ! -f "ffp3/tools/check_test_tables.php" ]; then
    echo "❌ Erreur: Exécuter ce script depuis la racine du projet FFP5CS"
    exit 1
fi

echo "✅ Répertoire de travail correct"

# Créer le dossier tools s'il n'existe pas
mkdir -p ffp3/tools

# Rendre les scripts exécutables
chmod +x ffp3/tools/test_post_data.sh 2>/dev/null || echo "⚠️  Impossible de rendre test_post_data.sh exécutable (Windows?)"

echo "✅ Scripts de diagnostic prêts"

# Afficher les instructions
echo ""
echo "=========================================="
echo "INSTRUCTIONS D'UTILISATION"
echo "=========================================="
echo ""
echo "1. Se connecter au serveur distant:"
echo "   ssh oliviera@toaster"
echo ""
echo "2. Naviguer vers le répertoire FFP3:"
echo "   cd /home4/oliviera/iot.olution.info/ffp3"
echo ""
echo "3. Exécuter les diagnostics dans l'ordre:"
echo "   php tools/check_env.php"
echo "   php tools/check_test_tables.php"
echo "   php tools/test_simple.php"
echo ""
echo "4. Analyser les logs:"
echo "   tail -f var/logs/post-data.log"
echo ""
echo "5. Si nécessaire, créer le fichier .env:"
echo "   cp env.test.example .env"
echo "   # Puis éditer .env avec les vraies valeurs"
echo ""
echo "6. Si les tables TEST manquent, exécuter:"
echo "   mysql -u [USER] -p [DATABASE] < CREATE_TEST_TABLES.sql"
echo ""
echo "=========================================="
echo "DÉPLOIEMENT TERMINÉ"
echo "=========================================="
