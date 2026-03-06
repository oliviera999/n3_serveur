#!/bin/bash
#
# Script de déploiement automatisé et test pour FFP3
# 
# Ce script :
# 1. Commit et push les corrections vers GitHub
# 2. Attend 2 minutes (délai cron)
# 3. Teste automatiquement tous les endpoints
# 4. Génère un rapport de succès/échec
# 5. Affiche les résultats en temps réel
#

set -e

echo "🚀 Déploiement automatisé FFP3"
echo "=============================="
echo ""

# Configuration
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="http://iot.olution.info/ffp3"
WAIT_TIME=120  # 2 minutes en secondes
REPORT_FILE="$REPO_DIR/var/log/deployment-report-$(date +%Y-%m-%d-%H-%M-%S).json"

# Vérifier qu'on est dans le bon répertoire
if [ ! -f "$REPO_DIR/composer.json" ]; then
    echo "❌ Erreur: composer.json non trouvé dans $REPO_DIR"
    exit 1
fi

echo "📍 Répertoire: $REPO_DIR"
echo "🌐 URL de test: $BASE_URL"
echo "⏱️ Délai d'attente: ${WAIT_TIME}s"
echo ""

# Étape 1: Commit et push
echo "📝 [1/4] Commit et push des corrections..."
cd "$REPO_DIR"

# Ajouter tous les fichiers modifiés
git add .

# Commit avec message descriptif
git commit -m "Fix: Correction des définitions de contrôleurs dans dependencies.php

- Correction AquaponieController: dépendances correctes (SensorReadRepository, StatisticsAggregatorService, ChartDataService, WaterBalanceService)
- Correction DashboardController: constructeur sans paramètres
- Correction ExportController: constructeur sans paramètres  
- Correction HeartbeatController: constructeur sans paramètres
- Ajout script de diagnostic automatisé bin/diagnose-controllers.php
- Ajout script de déploiement automatisé bin/auto-deploy-and-test.sh

Ces corrections devraient résoudre les erreurs 500 sur les endpoints:
- /aquaponie, /aquaponie-test
- /control, /control-test
- /api/outputs/state, /api/outputs-test/state
- /api/realtime/sensors/latest, /api/realtime-test/sensors/latest"

# Push vers GitHub
git push origin main

if [ $? -ne 0 ]; then
    echo "❌ Erreur lors du push vers GitHub"
    exit 1
fi

echo "✅ Corrections poussées vers GitHub"
echo ""

# Étape 2: Attendre le déploiement par cron
echo "⏳ [2/4] Attente du déploiement automatique par cron (${WAIT_TIME}s)..."
echo ""

# Compte à rebours
for i in $(seq $WAIT_TIME -1 1); do
    printf "\r⏱️  Attente: %02d:%02d" $((i/60)) $((i%60))
    sleep 1
done
echo ""
echo "✅ Délai écoulé, début des tests"
echo ""

# Étape 3: Tests automatisés
echo "🧪 [3/4] Tests automatisés des endpoints..."
echo ""

# Définir les endpoints à tester
declare -a ENDPOINTS=(
    # Pages principales PROD
    "/aquaponie"
    "/dashboard" 
    "/control"
    
    # Pages principales TEST
    "/aquaponie-test"
    "/dashboard-test"
    "/control-test"
    
    # API PROD
    "/api/outputs/state"
    "/api/realtime/sensors/latest"
    "/export-data"
    
    # API TEST
    "/api/outputs-test/state"
    "/api/realtime-test/sensors/latest"
    "/export-data-test"
    
    # Endpoints POST (doivent retourner 405 pour GET)
    "/post-data"
    "/post-data-test"
    "/heartbeat"
    "/heartbeat-test"
)

# Initialiser le rapport JSON
echo '{' > "$REPORT_FILE"
echo '  "timestamp": "'$(date -Iseconds)'",' >> "$REPORT_FILE"
echo '  "base_url": "'$BASE_URL'",' >> "$REPORT_FILE"
echo '  "endpoints": [' >> "$REPORT_FILE"

# Compteurs
TOTAL=0
SUCCESS=0
ERRORS=0
POST_405=0

echo "📊 Résultats des tests:"
echo "======================="
echo ""

for i in "${!ENDPOINTS[@]}"; do
    endpoint="${ENDPOINTS[$i]}"
    url="$BASE_URL$endpoint"
    TOTAL=$((TOTAL + 1))
    
    echo -n "📍 $endpoint: "
    
    # Test HTTP
    response=$(curl -s -w "%{http_code}" -o /tmp/response_body "$url" --connect-timeout 10 --max-time 30)
    http_code="${response: -3}"
    body=$(cat /tmp/response_body)
    
    # Déterminer le statut
    if [ "$http_code" = "200" ]; then
        status="success"
        echo "✅ 200 OK"
        SUCCESS=$((SUCCESS + 1))
    elif [ "$http_code" = "405" ] && [[ "$endpoint" =~ ^/(post-data|heartbeat) ]]; then
        status="expected_405"
        echo "✅ 405 Method Not Allowed (attendu)"
        POST_405=$((POST_405 + 1))
    elif [ "$http_code" = "500" ]; then
        status="error_500"
        echo "❌ 500 Internal Server Error"
        ERRORS=$((ERRORS + 1))
    else
        status="error_$http_code"
        echo "⚠️ $http_code"
        ERRORS=$((ERRORS + 1))
    fi
    
    # Ajouter au rapport JSON
    if [ $i -gt 0 ]; then
        echo "," >> "$REPORT_FILE"
    fi
    
    echo "    {" >> "$REPORT_FILE"
    echo "      \"endpoint\": \"$endpoint\"," >> "$REPORT_FILE"
    echo "      \"url\": \"$url\"," >> "$REPORT_FILE"
    echo "      \"http_code\": $http_code," >> "$REPORT_FILE"
    echo "      \"status\": \"$status\"," >> "$REPORT_FILE"
    echo "      \"response_size\": ${#body}" >> "$REPORT_FILE"
    
    # Ajouter l'erreur si c'est une 500
    if [ "$http_code" = "500" ]; then
        # Extraire le message d'erreur principal
        error_msg=$(echo "$body" | grep -o '<b>Fatal error</b>:[^<]*' | head -1 | sed 's/<[^>]*>//g' | xargs)
        echo "," >> "$REPORT_FILE"
        echo "      \"error\": \"$error_msg\"" >> "$REPORT_FILE"
    fi
    
    echo -n "    }" >> "$REPORT_FILE"
done

# Finaliser le rapport JSON
echo "" >> "$REPORT_FILE"
echo "  ]," >> "$REPORT_FILE"
echo "  \"summary\": {" >> "$REPORT_FILE"
echo "    \"total_tests\": $TOTAL," >> "$REPORT_FILE"
echo "    \"successful\": $SUCCESS," >> "$REPORT_FILE"
echo "    \"expected_405\": $POST_405," >> "$REPORT_FILE"
echo "    \"errors\": $ERRORS," >> "$REPORT_FILE"
echo "    \"success_rate\": \"$(echo "scale=1; $SUCCESS * 100 / $TOTAL" | bc)%\"" >> "$REPORT_FILE"
echo "  }" >> "$REPORT_FILE"
echo "}" >> "$REPORT_FILE"

echo ""
echo "📊 Résumé des tests:"
echo "==================="
echo "✅ Succès (200): $SUCCESS"
echo "✅ 405 attendus: $POST_405" 
echo "❌ Erreurs: $ERRORS"
echo "📈 Taux de succès: $(echo "scale=1; $SUCCESS * 100 / $TOTAL" | bc)%"
echo ""

# Étape 4: Rapport final
echo "📄 [4/4] Génération du rapport final..."
echo ""

if [ $ERRORS -eq 0 ]; then
    echo "🎉 DÉPLOIEMENT RÉUSSI !"
    echo "======================"
    echo ""
    echo "✅ Tous les endpoints fonctionnent correctement"
    echo "✅ Les erreurs 500 ont été résolues"
    echo "✅ Le système est opérationnel"
    echo ""
    exit_code=0
else
    echo "⚠️ DÉPLOIEMENT PARTIEL"
    echo "====================="
    echo ""
    echo "❌ $ERRORS endpoint(s) encore en erreur"
    echo "✅ $SUCCESS endpoint(s) fonctionnent"
    echo ""
    echo "🔧 Actions recommandées:"
    echo "  1. Vérifier les logs serveur pour les erreurs restantes"
    echo "  2. Exécuter bin/diagnose-controllers.php pour un diagnostic détaillé"
    echo "  3. Vérifier les dépendances manquantes"
    echo ""
    exit_code=1
fi

echo "📄 Rapport détaillé: $REPORT_FILE"
echo ""

# Afficher un extrait du rapport JSON
echo "📋 Extrait du rapport:"
echo "====================="
jq '.summary' "$REPORT_FILE" 2>/dev/null || echo "Impossible de lire le rapport JSON"

echo ""
echo "🏁 Déploiement automatisé terminé"
echo ""

exit $exit_code
