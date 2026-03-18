#!/bin/bash
#
# Script de diagnostic des erreurs 500 - FFP3
# À exécuter sur le serveur de production
#

echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║      DIAGNOSTIC ERREURS 500 - FFP3 AQUAPONIE                 ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""

# Couleurs
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Répertoire du projet
PROJECT_ROOT="/home4/oliviera/iot.olution.info/ffp3"
cd "$PROJECT_ROOT"

echo "📁 Répertoire projet: $PROJECT_ROOT"
echo ""

# ====================================================================
# 1. VÉRIFIER LES LOGS D'ERREUR
# ====================================================================
echo "🔍 [1/6] Analyse des logs d'erreur..."

# Logs PHP
if [ -f "var/log/php_errors.log" ]; then
    echo -e "${BLUE}📋 Logs PHP (var/log/php_errors.log):${NC}"
    ERROR_COUNT=$(tail -n 50 var/log/php_errors.log | grep -c "ERROR\|Fatal\|500")
    if [ "$ERROR_COUNT" -gt 0 ]; then
        echo -e "${RED}❌ Trouvé $ERROR_COUNT erreurs récentes${NC}"
        echo "Dernières erreurs:"
        tail -n 50 var/log/php_errors.log | grep "ERROR\|Fatal\|500" | tail -n 5 | while IFS= read -r line; do
            echo "  └─ $(echo "$line" | cut -c1-100)"
        done
    else
        echo -e "${GREEN}✓ Pas d'erreurs récentes${NC}"
    fi
else
    echo -e "${YELLOW}⚠ var/log/php_errors.log non trouvé${NC}"
fi

# Logs publics
if [ -f "public/error_log" ]; then
    echo -e "${BLUE}📋 Logs Public (public/error_log):${NC}"
    PUB_ERROR_COUNT=$(tail -n 50 public/error_log | grep -c "ERROR\|Fatal\|500")
    if [ "$PUB_ERROR_COUNT" -gt 0 ]; then
        echo -e "${RED}❌ Trouvé $PUB_ERROR_COUNT erreurs récentes${NC}"
        echo "Dernières erreurs:"
        tail -n 50 public/error_log | grep "ERROR\|Fatal\|500" | tail -n 3 | while IFS= read -r line; do
            echo "  └─ $(echo "$line" | cut -c1-100)"
        done
    else
        echo -e "${GREEN}✓ Pas d'erreurs récentes${NC}"
    fi
else
    echo -e "${YELLOW}⚠ public/error_log non trouvé${NC}"
fi

echo ""

# ====================================================================
# 2. TESTER LES COMPOSANTS INDIVIDUELS
# ====================================================================
echo "🔍 [2/6] Test des composants individuels..."

# Test de l'autoloader
echo -n "Autoloader: "
if php -r "require 'vendor/autoload.php'; echo 'OK';" 2>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗${NC}"
fi

# Test de la configuration .env
echo -n "Configuration .env: "
if php -r "require 'vendor/autoload.php'; App\Config\Env::load(); echo 'OK';" 2>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗${NC}"
fi

# Test de la connexion DB
echo -n "Connexion DB: "
if php -r "require 'vendor/autoload.php'; App\Config\Env::load(); \$pdo = new PDO('mysql:host='.\$_ENV['DB_HOST'].';dbname='.\$_ENV['DB_NAME'].';charset=utf8mb4', \$_ENV['DB_USER'], \$_ENV['DB_PASS']); echo 'OK';" 2>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗${NC}"
fi

# Test du container DI
echo -n "Container DI: "
if php -r "require 'vendor/autoload.php'; App\Config\Env::load(); \$container = require 'config/container.php'; echo 'OK';" 2>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗${NC}"
fi

echo ""

# ====================================================================
# 3. TESTER LES SERVICES SPÉCIFIQUES
# ====================================================================
echo "🔍 [3/6] Test des services spécifiques..."

# Test OutputService
echo -n "OutputService: "
if php -r "
require 'vendor/autoload.php';
App\Config\Env::load();
\$container = require 'config/container.php';
\$outputService = \$container->get('App\Service\OutputService');
\$outputs = \$outputService->getAllOutputs();
echo 'OK (' . count(\$outputs) . ' outputs)';
" 2>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗${NC}"
fi

# Test RealtimeDataService
echo -n "RealtimeDataService: "
if php -r "
require 'vendor/autoload.php';
App\Config\Env::load();
\$container = require 'config/container.php';
\$realtimeService = \$container->get('App\Service\RealtimeDataService');
\$data = \$realtimeService->getLatestReadings();
echo 'OK';
" 2>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗${NC}"
fi

# Test TemplateRenderer
echo -n "TemplateRenderer: "
if php -r "
require 'vendor/autoload.php';
App\Config\Env::load();
\$container = require 'config/container.php';
\$renderer = \$container->get('App\Service\TemplateRenderer');
echo 'OK';
" 2>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗${NC}"
fi

echo ""

# ====================================================================
# 4. TESTER LES CONTRÔLEURS
# ====================================================================
echo "🔍 [4/6] Test des contrôleurs..."

# Test OutputController
echo -n "OutputController: "
if php -r "
require 'vendor/autoload.php';
App\Config\Env::load();
\$container = require 'config/container.php';
\$controller = \$container->get('App\Controller\OutputController');
echo 'OK';
" 2>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗${NC}"
fi

# Test RealtimeApiController
echo -n "RealtimeApiController: "
if php -r "
require 'vendor/autoload.php';
App\Config\Env::load();
\$container = require 'config/container.php';
\$controller = \$container->get('App\Controller\RealtimeApiController');
echo 'OK';
" 2>/dev/null; then
    echo -e "${GREEN}✓${NC}"
else
    echo -e "${RED}✗${NC}"
fi

echo ""

# ====================================================================
# 5. TESTER LES TEMPLATES
# ====================================================================
echo "🔍 [5/6] Test des templates..."

if [ -f "templates/control.twig" ]; then
    echo -e "${GREEN}✓${NC} control.twig existe"
else
    echo -e "${RED}✗${NC} control.twig manquant"
fi

if [ -f "templates/aquaponie.twig" ]; then
    echo -e "${GREEN}✓${NC} aquaponie.twig existe"
else
    echo -e "${RED}✗${NC} aquaponie.twig manquant"
fi

echo ""

# ====================================================================
# 6. TESTER LES ENDPOINTS DIRECTEMENT
# ====================================================================
echo "🔍 [6/6] Test des endpoints..."

# Test avec curl local
echo -n "GET /control: "
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/ffp3/control" 2>/dev/null)
if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}✓ HTTP $HTTP_CODE${NC}"
elif [ "$HTTP_CODE" = "500" ]; then
    echo -e "${RED}✗ HTTP $HTTP_CODE${NC}"
else
    echo -e "${YELLOW}⚠ HTTP $HTTP_CODE${NC}"
fi

echo -n "GET /api/realtime/sensors/latest: "
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/ffp3/api/realtime/sensors/latest" 2>/dev/null)
if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}✓ HTTP $HTTP_CODE${NC}"
elif [ "$HTTP_CODE" = "500" ]; then
    echo -e "${RED}✗ HTTP $HTTP_CODE${NC}"
else
    echo -e "${YELLOW}⚠ HTTP $HTTP_CODE${NC}"
fi

echo ""

# ====================================================================
# RÉSUMÉ ET RECOMMANDATIONS
# ====================================================================
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║                         RÉSUMÉ                                ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""

echo "📋 Actions recommandées:"
echo ""
echo "1. Vérifier les logs d'erreur détaillés:"
echo "   tail -f var/log/php_errors.log"
echo "   tail -f public/error_log"
echo ""
echo "2. Tester les composants individuellement:"
echo "   php -r \"require 'vendor/autoload.php'; App\Config\Env::load(); \$container = require 'config/container.php'; \$controller = \$container->get('App\Controller\OutputController');\""
echo ""
echo "3. Vérifier les permissions des fichiers:"
echo "   ls -la templates/"
echo "   ls -la var/log/"
echo ""
echo "4. Redémarrer les services si nécessaire:"
echo "   sudo systemctl restart apache2"
echo "   sudo systemctl restart mysql"
echo ""

echo "═══════════════════════════════════════════════════════════════"
echo "Pour plus d'informations, consultez:"
echo "  - var/log/php_errors.log (logs PHP détaillés)"
echo "  - public/error_log (logs Apache)"
echo "  - Les logs de debug ajoutés dans les contrôleurs"
echo "═══════════════════════════════════════════════════════════════"
