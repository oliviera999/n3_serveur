#!/bin/bash

# Script de déploiement de la correction HTTP 500 TEST
# Date: 2025-10-15

echo "========================================"
echo "DÉPLOIEMENT CORRECTION HTTP 500 TEST"
echo "Date: $(date '+%Y-m-%d %H:%M:%S')"
echo "========================================"

# Configuration serveur
SERVER="iot.olution.info"
USER="oliviera"
REMOTE_PATH="/home4/oliviera/iot.olution.info/ffp3"

echo "1. DÉPLOIEMENT DES FICHIERS CORRIGÉS"
echo "===================================="

# Déployer le fichier post-data.php corrigé
echo "📤 Déploiement de post-data.php..."
scp ffp3/public/post-data.php $USER@$SERVER:$REMOTE_PATH/public/

# Déployer les scripts de diagnostic
echo "📤 Déploiement des scripts de diagnostic..."
scp ffp3/tools/fix_test_environment.php $USER@$SERVER:$REMOTE_PATH/tools/
scp ffp3/tools/test_post_data_fixed.sh $USER@$SERVER:$REMOTE_PATH/tools/
scp ffp3/tools/test_both_environments.sh $USER@$SERVER:$REMOTE_PATH/tools/
scp ffp3/tools/verify_environments.php $USER@$SERVER:$REMOTE_PATH/tools/

echo "✅ Fichiers déployés"

echo ""
echo "2. EXÉCUTION DES TESTS SUR LE SERVEUR"
echo "====================================="

# Exécuter le script de correction
echo "🔧 Exécution du script de correction..."
ssh $USER@$SERVER "cd $REMOTE_PATH/tools && php fix_test_environment.php"

echo ""
echo "🔍 Test de l'endpoint /post-data-test..."
ssh $USER@$SERVER "cd $REMOTE_PATH/tools && chmod +x test_post_data_fixed.sh && ./test_post_data_fixed.sh"

echo ""
echo "🧪 Test des deux environnements (PROD et TEST)..."
ssh $USER@$SERVER "cd $REMOTE_PATH/tools && chmod +x test_both_environments.sh && ./test_both_environments.sh"

echo ""
echo "🔍 Vérification des environnements..."
ssh $USER@$SERVER "cd $REMOTE_PATH/tools && php verify_environments.php"

echo ""
echo "3. VÉRIFICATION DES LOGS"
echo "========================"
echo "📋 Consultation des logs post-data..."
ssh $USER@$SERVER "tail -20 $REMOTE_PATH/var/logs/post-data.log"

echo ""
echo "========================================"
echo "DÉPLOIEMENT TERMINÉ"
echo "========================================"
echo ""
echo "Prochaines étapes:"
echo "1. Vérifier que l'ESP32 reçoit maintenant HTTP 200"
echo "2. Monitorer les logs pour confirmer l'insertion dans ffp3Data2"
echo "3. Valider que les GPIO sont mis à jour dans ffp3Outputs2"
