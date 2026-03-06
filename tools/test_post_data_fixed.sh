#!/bin/bash

# Script de test POST-DATA-TEST après correction
# Date: 2025-10-15

echo "========================================"
echo "TEST POST-DATA-TEST APRÈS CORRECTION"
echo "Date: $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================"

# URL de test
URL="http://localhost/ffp3/post-data-test"

# Données de test (simulant l'ESP32)
DATA="api_key=fdGTMoptd5CD2ert3&sensor=esp32-wroom&version=11.37&TempAir=28.0&Humidite=61.0&TempEau=28.0&EauPotager=209&EauAquarium=210&EauReserve=209&diffMaree=-2&Luminosite=228&etatPompeAqua=1&etatPompeTank=0&etatHeat=0&etatUV=0&bouffeMatin=8&bouffeMidi=12&bouffeSoir=19&tempsGros=2&tempsPetits=2&aqThreshold=18&tankThreshold=80&chauffageThreshold=15&tempsRemplissageSec=5&limFlood=6&WakeUp=0&FreqWakeUp=6&bouffePetits=0&bouffeGros=0&mail=oliv.arn.lau@gmail.com&mailNotif=checked&resetMode=0"

echo "URL: $URL"
echo "Données: $DATA"
echo ""
echo "Envoi de la requête..."

# Envoi de la requête
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$URL" -d "$DATA")

# Séparer le contenu de la réponse et le code HTTP
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
RESPONSE_BODY=$(echo "$RESPONSE" | head -n -1)

echo "========================================"
echo "RÉSULTATS"
echo "========================================"
echo "HTTP Code: $HTTP_CODE"
echo "Response:"
echo "$RESPONSE_BODY"

if [ "$HTTP_CODE" = "200" ]; then
    echo "✅ SUCCÈS - Code HTTP: $HTTP_CODE"
    echo "✅ L'environnement TEST fonctionne correctement"
else
    echo "❌ ÉCHEC - Code HTTP: $HTTP_CODE"
    echo "❌ Problème persistant"
fi

echo ""
echo "========================================"
echo "TEST TERMINÉ"
echo "========================================"
