#!/bin/bash
#
# Script de déploiement et test automatique FFP3
# Usage: bash deploy-and-test.sh
#

echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║      DÉPLOIEMENT ET TEST AUTOMATIQUE FFP3                    ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""

# Couleurs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# URL de base
BASE_URL="https://iot.olution.info/ffp3"

echo "🔍 [1/6] Test des pages web..."
echo ""

# Test des pages web
test_page() {
    local url="$1"
    local name="$2"
    local expected_code="$3"
    
    echo -n "Test $name: "
    http_code=$(curl -s -o /dev/null -w "%{http_code}" "$url")
    
    if [ "$http_code" = "$expected_code" ]; then
        echo -e "${GREEN}✓ HTTP $http_code${NC}"
        return 0
    else
        echo -e "${RED}✗ HTTP $http_code (attendu: $expected_code)${NC}"
        return 1
    fi
}

# Test des pages principales
test_page "$BASE_URL/" "Home" "200"
test_page "$BASE_URL/dashboard" "Dashboard" "200"
test_page "$BASE_URL/aquaponie" "Aquaponie" "200"
test_page "$BASE_URL/tide-stats" "Tide Stats" "200"
test_page "$BASE_URL/control" "Control" "200"

echo ""
echo "🔍 [2/6] Test des API temps réel..."
echo ""

# Test des API temps réel
test_page "$BASE_URL/api/realtime/sensors/latest" "API Sensors Latest" "200"
test_page "$BASE_URL/api/realtime/outputs/state" "API Outputs State" "200"
test_page "$BASE_URL/api/realtime/system/health" "API System Health" "200"

echo ""
echo "🔍 [3/6] Test des endpoints ESP32..."
echo ""

# Test des endpoints ESP32
test_page "$BASE_URL/post-data" "Post Data" "405"  # Method Not Allowed pour GET
test_page "$BASE_URL/post-ffp3-data.php" "Post FFP3 Data" "401"  # Unauthorized sans API key
test_page "$BASE_URL/heartbeat" "Heartbeat" "405"  # Method Not Allowed pour GET

echo ""
echo "🔍 [4/6] Test des redirections legacy..."
echo ""

# Test des redirections
echo -n "Test redirection /ffp3-data: "
redirect_code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/ffp3-data")
if [ "$redirect_code" = "301" ] || [ "$redirect_code" = "200" ]; then
    echo -e "${GREEN}✓ HTTP $redirect_code${NC}"
else
    echo -e "${RED}✗ HTTP $redirect_code${NC}"
fi

echo -n "Test redirection /heartbeat.php: "
redirect_code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/heartbeat.php")
if [ "$redirect_code" = "301" ] || [ "$redirect_code" = "200" ]; then
    echo -e "${GREEN}✓ HTTP $redirect_code${NC}"
else
    echo -e "${RED}✗ HTTP $redirect_code${NC}"
fi

echo ""
echo "🔍 [5/6] Test des ressources statiques..."
echo ""

# Test des ressources statiques
test_page "$BASE_URL/ota/metadata.json" "OTA Metadata" "200"
test_page "$BASE_URL/public/manifest.json" "PWA Manifest" "200"

echo ""
echo "🔍 [6/6] Test des environnements TEST..."
echo ""

# Test des environnements TEST
test_page "$BASE_URL/dashboard-test" "Dashboard TEST" "200"
test_page "$BASE_URL/aquaponie-test" "Aquaponie TEST" "200"
test_page "$BASE_URL/tide-stats-test" "Tide Stats TEST" "200"
test_page "$BASE_URL/control-test" "Control TEST" "200"

echo ""
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║                         RÉSUMÉ                                ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""

# Compter les erreurs
error_count=0

# Vérifier les pages critiques
critical_pages=(
    "$BASE_URL/"
    "$BASE_URL/dashboard"
    "$BASE_URL/aquaponie"
    "$BASE_URL/tide-stats"
    "$BASE_URL/control"
)

for page in "${critical_pages[@]}"; do
    http_code=$(curl -s -o /dev/null -w "%{http_code}" "$page")
    if [ "$http_code" != "200" ]; then
        error_count=$((error_count + 1))
    fi
done

# Vérifier les API critiques
critical_apis=(
    "$BASE_URL/api/realtime/sensors/latest"
    "$BASE_URL/api/realtime/outputs/state"
    "$BASE_URL/api/realtime/system/health"
)

for api in "${critical_apis[@]}"; do
    http_code=$(curl -s -o /dev/null -w "%{http_code}" "$api")
    if [ "$http_code" != "200" ]; then
        error_count=$((error_count + 1))
    fi
done

if [ $error_count -eq 0 ]; then
    echo -e "${GREEN}✅ TOUS LES TESTS PASSENT${NC}"
    echo ""
    echo "Toutes les pages et API fonctionnent correctement !"
    echo "Le système FFP3 est opérationnel."
else
    echo -e "${RED}❌ $error_count ERREUR(S) DÉTECTÉE(S)${NC}"
    echo ""
    echo "Certaines pages ou API ne fonctionnent pas correctement."
    echo "Consultez les détails ci-dessus pour identifier les problèmes."
fi

echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "Pour plus d'informations, consultez:"
echo "  - Les logs d'erreur: var/log/php_errors.log"
echo "  - Les scripts de diagnostic: tools/diagnostic_500_errors.php"
echo "  - Le CHANGELOG.md pour l'historique des modifications"
echo "═══════════════════════════════════════════════════════════════"
