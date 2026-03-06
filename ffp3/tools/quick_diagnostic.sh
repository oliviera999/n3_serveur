#!/bin/bash
#
# Quick Diagnostic Script for ESP32 Communication Issue
# Usage: bash tools/quick_diagnostic.sh
#

echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║      DIAGNOSTIC RAPIDE ESP32 - FFP3 AQUAPONIE                ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get project root
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

echo "📁 Répertoire projet: $PROJECT_ROOT"
echo ""

# ====================================================================
# 1. CHECK API ENDPOINT
# ====================================================================
echo "🔍 [1/5] Test de l'endpoint POST..."

API_KEY=$(grep "^API_KEY=" .env 2>/dev/null | cut -d'=' -f2 | tr -d '"' | tr -d "'")

if [ -z "$API_KEY" ]; then
    echo -e "${RED}❌ API_KEY non trouvée dans .env${NC}"
else
    echo -e "${GREEN}✓${NC} API Key: ${API_KEY:0:5}***"
    
    # Test POST
    RESPONSE=$(curl -s -w "\n%{http_code}" -X POST \
        "https://iot.olution.info/ffp3/post-data" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -d "api_key=${API_KEY}&sensor=DIAG-SHELL&version=1.0&TempAir=22.5" \
        2>&1)
    
    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
    BODY=$(echo "$RESPONSE" | head -n-1)
    
    if [ "$HTTP_CODE" = "200" ]; then
        echo -e "${GREEN}✓${NC} Serveur répond: HTTP $HTTP_CODE"
        echo "  └─ Réponse: $BODY"
    else
        echo -e "${RED}✗${NC} Serveur erreur: HTTP $HTTP_CODE"
        echo "  └─ Réponse: $BODY"
    fi
fi

echo ""

# ====================================================================
# 2. CHECK DATABASE LAST ENTRY
# ====================================================================
echo "🔍 [2/5] Vérification dernières données..."

DB_USER=$(grep "^DB_USER=" .env 2>/dev/null | cut -d'=' -f2 | tr -d '"' | tr -d "'")
DB_PASS=$(grep "^DB_PASS=" .env 2>/dev/null | cut -d'=' -f2 | tr -d '"' | tr -d "'")
DB_NAME=$(grep "^DB_NAME=" .env 2>/dev/null | cut -d'=' -f2 | tr -d '"' | tr -d "'")

if [ -z "$DB_USER" ] || [ -z "$DB_PASS" ] || [ -z "$DB_NAME" ]; then
    echo -e "${RED}❌ Configuration BDD manquante dans .env${NC}"
else
    # Query last data
    LAST_DATA=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e \
        "SELECT 
            reading_time,
            sensor,
            TIMESTAMPDIFF(MINUTE, reading_time, NOW()) as minutes_ago
        FROM ffp3Data 
        ORDER BY reading_time DESC 
        LIMIT 1" 2>/dev/null)
    
    if [ $? -eq 0 ]; then
        if [ -n "$LAST_DATA" ]; then
            READING_TIME=$(echo "$LAST_DATA" | awk '{print $1, $2}')
            SENSOR=$(echo "$LAST_DATA" | awk '{print $3}')
            MINUTES_AGO=$(echo "$LAST_DATA" | awk '{print $4}')
            
            echo "  📊 Dernière lecture: $READING_TIME"
            echo "  📡 Capteur: $SENSOR"
            
            if [ "$MINUTES_AGO" -lt 5 ]; then
                echo -e "  ${GREEN}✓${NC} Données RÉCENTES (il y a $MINUTES_AGO min)"
            elif [ "$MINUTES_AGO" -lt 15 ]; then
                echo -e "  ${YELLOW}⚠${NC} Données un peu anciennes (il y a $MINUTES_AGO min)"
            else
                HOURS_AGO=$(echo "scale=1; $MINUTES_AGO/60" | bc)
                echo -e "  ${RED}✗${NC} DONNÉES ANCIENNES (il y a $MINUTES_AGO min / ${HOURS_AGO}h)"
                echo -e "  ${RED}  └─ L'ESP32 ne publie plus depuis plus d'une heure!${NC}"
            fi
        else
            echo -e "${RED}❌ Aucune donnée trouvée dans la table ffp3Data${NC}"
        fi
    else
        echo -e "${RED}❌ Erreur de connexion à la BDD${NC}"
    fi
fi

echo ""

# ====================================================================
# 3. CHECK LOG FILES
# ====================================================================
echo "🔍 [3/5] Vérification des logs..."

if [ -f "error_log" ]; then
    ERROR_COUNT=$(tail -n 100 error_log | grep -c "ERROR\|Fatal\|500")
    if [ "$ERROR_COUNT" -gt 0 ]; then
        echo -e "${YELLOW}⚠${NC} Trouvé $ERROR_COUNT erreurs dans error_log"
        echo "  Dernières erreurs:"
        tail -n 100 error_log | grep "ERROR\|Fatal\|500" | tail -n 3 | while IFS= read -r line; do
            echo "    └─ $(echo "$line" | cut -c1-80)"
        done
    else
        echo -e "${GREEN}✓${NC} Pas d'erreurs récentes dans error_log"
    fi
else
    echo -e "${YELLOW}⚠${NC} error_log non trouvé"
fi

if [ -f "public/error_log" ]; then
    PUB_ERROR_COUNT=$(tail -n 50 public/error_log | grep -c "ERROR\|Fatal\|500")
    if [ "$PUB_ERROR_COUNT" -gt 0 ]; then
        echo -e "${YELLOW}⚠${NC} Trouvé $PUB_ERROR_COUNT erreurs dans public/error_log"
    else
        echo -e "${GREEN}✓${NC} Pas d'erreurs récentes dans public/error_log"
    fi
else
    echo -e "${YELLOW}⚠${NC} public/error_log non trouvé"
fi

if [ -f "cronlog.txt" ]; then
    CRON_LAST_LINE=$(tail -n 1 cronlog.txt)
    echo -e "${GREEN}✓${NC} cronlog.txt présent"
    echo "  └─ Dernière ligne: $(echo "$CRON_LAST_LINE" | cut -c1-70)..."
else
    echo -e "${YELLOW}⚠${NC} cronlog.txt non trouvé"
fi

echo ""

# ====================================================================
# 4. CHECK DISK SPACE
# ====================================================================
echo "🔍 [4/5] Vérification espace disque..."

DISK_USAGE=$(df -h . | tail -n 1 | awk '{print $5}' | sed 's/%//')

if [ "$DISK_USAGE" -lt 90 ]; then
    echo -e "${GREEN}✓${NC} Espace disque: ${DISK_USAGE}% utilisé"
else
    echo -e "${RED}✗${NC} Espace disque critique: ${DISK_USAGE}% utilisé"
fi

echo ""

# ====================================================================
# 5. CHECK FILES EXIST
# ====================================================================
echo "🔍 [5/5] Vérification fichiers critiques..."

CRITICAL_FILES=(
    "public/index.php"
    "public/post-data.php"
    "src/Controller/PostDataController.php"
    "src/Config/Database.php"
    "src/Config/Env.php"
    ".env"
)

MISSING=0
for file in "${CRITICAL_FILES[@]}"; do
    if [ -f "$file" ]; then
        echo -e "${GREEN}✓${NC} $file"
    else
        echo -e "${RED}✗${NC} $file MANQUANT"
        MISSING=$((MISSING + 1))
    fi
done

if [ $MISSING -gt 0 ]; then
    echo -e "${RED}❌ $MISSING fichier(s) critique(s) manquant(s)${NC}"
fi

echo ""

# ====================================================================
# SUMMARY
# ====================================================================
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║                         RÉSUMÉ                                ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""

# Determine overall status
if [ "$HTTP_CODE" = "200" ] && [ "$MINUTES_AGO" -lt 15 ] && [ $MISSING -eq 0 ]; then
    echo -e "${GREEN}✅ SYSTÈME OPÉRATIONNEL${NC}"
    echo ""
    echo "Tout fonctionne correctement!"
    echo ""
elif [ "$HTTP_CODE" = "200" ] && [ "$MINUTES_AGO" -gt 60 ]; then
    echo -e "${RED}❌ PROBLÈME ESP32${NC}"
    echo ""
    echo "Le serveur fonctionne mais l'ESP32 ne publie plus."
    echo ""
    echo "Actions recommandées:"
    echo "1. Vérifier que l'ESP32 est allumé"
    echo "2. Vérifier la connexion WiFi de l'ESP32"
    echo "3. Vérifier les logs série de l'ESP32 (USB)"
    echo "4. Vérifier l'URL dans le code ESP32:"
    echo "   └─ Doit être: https://iot.olution.info/ffp3/post-data"
    echo "5. Vérifier l'API Key dans le code ESP32:"
    echo "   └─ Doit être: $API_KEY"
    echo ""
elif [ "$HTTP_CODE" != "200" ]; then
    echo -e "${RED}❌ PROBLÈME SERVEUR${NC}"
    echo ""
    echo "Le serveur ne répond pas correctement (HTTP $HTTP_CODE)."
    echo ""
    echo "Actions recommandées:"
    echo "1. Vérifier les logs d'erreurs:"
    echo "   └─ tail -f error_log"
    echo "   └─ tail -f public/error_log"
    echo "2. Vérifier la configuration .env"
    echo "3. Vérifier que MySQL est démarré"
    echo "4. Vérifier les permissions des fichiers"
    echo ""
else
    echo -e "${YELLOW}⚠️  ÉTAT INCERTAIN${NC}"
    echo ""
    echo "Certains tests ont échoué. Consulter les détails ci-dessus."
    echo ""
fi

echo "═══════════════════════════════════════════════════════════════"
echo "Pour un diagnostic complet, exécutez:"
echo "  php tools/diagnostic_esp32.php"
echo ""
echo "Pour plus d'informations, consultez:"
echo "  DIAGNOSTIC_ESP32_TROUBLESHOOTING.md"
echo "═══════════════════════════════════════════════════════════════"

