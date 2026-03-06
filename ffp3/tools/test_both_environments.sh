#!/bin/bash

# Script de test des deux environnements (PROD et TEST)
# Date: 2025-10-15

echo "========================================"
echo "TEST ENVIRONNEMENTS PROD ET TEST"
echo "Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"

# Configuration
BASE_URL="http://localhost/ffp3"
API_KEY="fdGTMoptd5CD2ert3"

# Données de test identiques pour les deux environnements
DATA="api_key=$API_KEY&sensor=esp32-wroom&version=11.37&TempAir=28.0&Humidite=61.0&TempEau=28.0&EauPotager=209&EauAquarium=210&EauReserve=209&diffMaree=-2&Luminosite=228&etatPompeAqua=1&etatPompeTank=0&etatHeat=0&etatUV=0&bouffeMatin=8&bouffeMidi=12&bouffeSoir=19&tempsGros=2&tempsPetits=2&aqThreshold=18&tankThreshold=80&chauffageThreshold=15&tempsRemplissageSec=5&limFlood=6&WakeUp=0&FreqWakeUp=6&bouffePetits=0&bouffeGros=0&mail=oliv.arn.lau@gmail.com&mailNotif=checked&resetMode=0"

echo "1. TEST ENVIRONNEMENT PROD"
echo "=========================="
echo "URL: $BASE_URL/post-data"
echo "Envoi de la requête..."

RESPONSE_PROD=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/post-data" -d "$DATA")
HTTP_CODE_PROD=$(echo "$RESPONSE_PROD" | tail -n1)
RESPONSE_BODY_PROD=$(echo "$RESPONSE_PROD" | head -n -1)

echo "HTTP Code: $HTTP_CODE_PROD"
echo "Response: $RESPONSE_BODY_PROD"

if [ "$HTTP_CODE_PROD" = "200" ]; then
    echo "✅ PROD: SUCCÈS - Code HTTP: $HTTP_CODE_PROD"
else
    echo "❌ PROD: ÉCHEC - Code HTTP: $HTTP_CODE_PROD"
fi

echo ""
echo "2. TEST ENVIRONNEMENT TEST"
echo "=========================="
echo "URL: $BASE_URL/post-data-test"
echo "Envoi de la requête..."

RESPONSE_TEST=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/post-data-test" -d "$DATA")
HTTP_CODE_TEST=$(echo "$RESPONSE_TEST" | tail -n1)
RESPONSE_BODY_TEST=$(echo "$RESPONSE_TEST" | head -n -1)

echo "HTTP Code: $HTTP_CODE_TEST"
echo "Response: $RESPONSE_BODY_TEST"

if [ "$HTTP_CODE_TEST" = "200" ]; then
    echo "✅ TEST: SUCCÈS - Code HTTP: $HTTP_CODE_TEST"
else
    echo "❌ TEST: ÉCHEC - Code HTTP: $HTTP_CODE_TEST"
fi

echo ""
echo "3. VÉRIFICATION DES TABLES"
echo "=========================="
echo "Vérification des insertions dans les bonnes tables..."

# Vérifier que PROD utilise ffp3Data
echo "📊 PROD - Dernières entrées dans ffp3Data:"
mysql -h localhost -u oliviera_iot -p'**************' oliviera_iot -e "
SELECT COUNT(*) as 'Entrées PROD' FROM ffp3Data 
WHERE reading_time > NOW() - INTERVAL 2 MINUTE 
AND sensor = 'esp32-wroom';
"

# Vérifier que TEST utilise ffp3Data2
echo "📊 TEST - Dernières entrées dans ffp3Data2:"
mysql -h localhost -u oliviera_iot -p'**************' oliviera_iot -e "
SELECT COUNT(*) as 'Entrées TEST' FROM ffp3Data2 
WHERE reading_time > NOW() - INTERVAL 2 MINUTE 
AND sensor = 'esp32-wroom';
"

echo ""
echo "4. RÉSUMÉ"
echo "========"
if [ "$HTTP_CODE_PROD" = "200" ] && [ "$HTTP_CODE_TEST" = "200" ]; then
    echo "🎉 SUCCÈS COMPLET:"
    echo "   ✅ PROD fonctionne (HTTP $HTTP_CODE_PROD)"
    echo "   ✅ TEST fonctionne (HTTP $HTTP_CODE_TEST)"
    echo "   ✅ Les deux environnements sont séparés"
else
    echo "⚠️  PROBLÈME DÉTECTÉ:"
    echo "   PROD: HTTP $HTTP_CODE_PROD"
    echo "   TEST: HTTP $HTTP_CODE_TEST"
fi

echo ""
echo "========================================"
echo "TEST TERMINÉ"
echo "========================================"
