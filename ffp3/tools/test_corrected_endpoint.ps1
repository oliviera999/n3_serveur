# Test du fichier post-data-test-CORRECTED.php
# Vérifie que le fichier corrigé fonctionne sur le serveur

Write-Host "=== Test Endpoint Corrigé ===" -ForegroundColor Cyan
Write-Host ""

# Test POST avec toutes les données ESP32
Write-Host "Envoi POST complet vers serveur..." -ForegroundColor Yellow

$response = curl.exe -X POST "http://iot.olution.info/ffp3/post-data-test" `
  -H "Content-Type: application/x-www-form-urlencoded" `
  -d "api_key=fdGTMoptd5CD2ert3" `
  -d "sensor=esp32-wroom" `
  -d "version=11.35" `
  -d "TempAir=25.0" `
  -d "Humidite=60.0" `
  -d "TempEau=28.0" `
  -d "EauPotager=209" `
  -d "EauAquarium=209" `
  -d "EauReserve=209" `
  -d "diffMaree=0" `
  -d "Luminosite=1000" `
  -d "etatPompeAqua=0" `
  -d "etatPompeTank=0" `
  -d "etatHeat=1" `
  -d "etatUV=1" `
  -d "bouffeMatin=8" `
  -d "bouffeMidi=12" `
  -d "bouffeSoir=19" `
  -d "bouffePetits=0" `
  -d "bouffeGros=0" `
  -d "tempsGros=2" `
  -d "tempsPetits=2" `
  -d "aqThreshold=18" `
  -d "tankThreshold=80" `
  -d "chauffageThreshold=18" `
  -d "mail=test@test.com" `
  -d "mailNotif=checked" `
  -d "resetMode=0" `
  -d "tempsRemplissageSec=5" `
  -d "limFlood=8" `
  -d "WakeUp=0" `
  -d "FreqWakeUp=6" `
  -w "\n--- HTTP Code: %{http_code} ---\n" `
  -s

Write-Host ""
Write-Host "=== Réponse Serveur ===" -ForegroundColor Cyan

if ($response -like "*success*") {
    Write-Host "✓ SUCCESS - Données insérées avec succès!" -ForegroundColor Green
    Write-Host "  Réponse: $response" -ForegroundColor Gray
} elseif ($response -like "*500*" -or $response -like "*error*" -or $response -like "*Erreur*") {
    Write-Host "✗ ERREUR - Le serveur retourne toujours une erreur" -ForegroundColor Red
    Write-Host "  Réponse: $response" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Actions à faire:" -ForegroundColor Yellow
    Write-Host "  1. Vérifier que post-data-test.php a bien été remplacé sur le serveur" -ForegroundColor Gray
    Write-Host "  2. Vérifier les logs PHP sur le serveur" -ForegroundColor Gray
    Write-Host "  3. Tester avec le script diagnostic: curl.exe http://iot.olution.info/ffp3/public/diagnostic-post.php" -ForegroundColor Gray
} else {
    Write-Host "? RÉPONSE INATTENDUE" -ForegroundColor Yellow
    Write-Host "  Réponse: $response" -ForegroundColor Gray
}

Write-Host ""
Write-Host "=== Vérification Outputs ===" -ForegroundColor Cyan
Write-Host "Lecture états GPIO depuis serveur..." -ForegroundColor Yellow

$outputs = curl.exe -s "http://iot.olution.info/ffp3/api/outputs-test/state"

if ($outputs) {
    try {
        $json = $outputs | ConvertFrom-Json -ErrorAction Stop
        Write-Host "✓ API Outputs OK" -ForegroundColor Green
        Write-Host ""
        Write-Host "  États actuels:" -ForegroundColor Gray
        Write-Host "    - Chauffage (GPIO 2): $($json.'2' ?? $json.heat ?? 'N/A')" -ForegroundColor $(if(($json.'2' ?? $json.heat) -eq '1') {"Green"} else {"Gray"})
        Write-Host "    - UV (GPIO 15): $($json.'15' ?? $json.light ?? 'N/A')" -ForegroundColor Gray
        Write-Host "    - Pompe Aqua (GPIO 16): $($json.'16' ?? $json.pump_aqua ?? 'N/A')" -ForegroundColor Gray
        Write-Host "    - Pompe Tank (GPIO 18): $($json.'18' ?? $json.pump_tank ?? 'N/A')" -ForegroundColor Gray
        Write-Host "    - Temps Gros (GPIO 111): $($json.'111' ?? $json.tempsGros ?? 'N/A')" -ForegroundColor Gray
        Write-Host "    - Temps Petits (GPIO 112): $($json.'112' ?? $json.tempsPetits ?? 'N/A')" -ForegroundColor Gray
        Write-Host "    - Temps Remplissage (GPIO 113): $($json.'113' ?? $json.tempsRemplissageSec ?? 'N/A')" -ForegroundColor Gray
        Write-Host "    - Limite Flood (GPIO 114): $($json.'114' ?? $json.limFlood ?? 'N/A')" -ForegroundColor Gray
        Write-Host "    - WakeUp (GPIO 115): $($json.'115' ?? $json.WakeUp ?? 'N/A')" -ForegroundColor Gray
        Write-Host "    - Freq WakeUp (GPIO 116): $($json.'116' ?? $json.FreqWakeUp ?? 'N/A')" -ForegroundColor Gray
    } catch {
        Write-Host "✗ JSON invalide" -ForegroundColor Red
        Write-Host "  Réponse: $outputs" -ForegroundColor Gray
    }
} else {
    Write-Host "✗ Aucune réponse" -ForegroundColor Red
}

Write-Host ""
Write-Host "=== Fin Test ===" -ForegroundColor Cyan

