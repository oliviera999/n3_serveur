# Script de diagnostic endpoint serveur
# Teste l'endpoint post-data-test avec données minimales

Write-Host "=== Test Endpoint POST Data ===" -ForegroundColor Cyan
Write-Host ""

# Test 1: Données minimales
Write-Host "Test 1: Payload minimal..." -ForegroundColor Yellow
$response1 = curl.exe -X POST "http://iot.olution.info/ffp3/post-data-test" `
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
  -w "\nHTTP Code: %{http_code}\n" `
  -s

Write-Host "Réponse: $response1" -ForegroundColor $(if($response1 -like "*success*") {"Green"} else {"Red"})
Write-Host ""

# Test 2: Vérifier que le fichier existe
Write-Host "Test 2: Vérification existence fichier..." -ForegroundColor Yellow
$response2 = curl.exe -s -o NUL -w "%{http_code}" "http://iot.olution.info/ffp3/post-data-test"
Write-Host "GET http://iot.olution.info/ffp3/post-data-test" -ForegroundColor Gray
Write-Host "HTTP Code: $response2" -ForegroundColor $(if($response2 -eq "200") {"Green"} elseif($response2 -eq "405") {"Yellow"} else {"Red"})

if ($response2 -eq "405") {
    Write-Host "  └─ 405 = Method Not Allowed (fichier existe, n'accepte que POST) ✓" -ForegroundColor Green
} elseif ($response2 -eq "404") {
    Write-Host "  └─ 404 = Not Found (fichier n'existe PAS sur serveur!) ✗" -ForegroundColor Red
} elseif ($response2 -eq "500") {
    Write-Host "  └─ 500 = Internal Server Error ✗" -ForegroundColor Red
}
Write-Host ""

# Test 3: Vérifier outputs state
Write-Host "Test 3: Vérification GET outputs state..." -ForegroundColor Yellow
$response3 = curl.exe -s "http://iot.olution.info/ffp3/api/outputs-test/state"
Write-Host "GET http://iot.olution.info/ffp3/api/outputs-test/state" -ForegroundColor Gray

if ($response3) {
    $json = $response3 | ConvertFrom-Json -ErrorAction SilentlyContinue
    if ($json) {
        Write-Host "JSON valide ✓" -ForegroundColor Green
        Write-Host "  - heat (GPIO 2): $($json.'2' ?? $json.heat ?? 'N/A')" -ForegroundColor Gray
        Write-Host "  - light (GPIO 15): $($json.'15' ?? $json.light ?? 'N/A')" -ForegroundColor Gray
        Write-Host "  - pump_aqua (GPIO 16): $($json.'16' ?? $json.pump_aqua ?? 'N/A')" -ForegroundColor Gray
        Write-Host "  - pump_tank (GPIO 18): $($json.'18' ?? $json.pump_tank ?? 'N/A')" -ForegroundColor Gray
    } else {
        Write-Host "JSON invalide ou erreur ✗" -ForegroundColor Red
        Write-Host "Réponse brute: $response3" -ForegroundColor Gray
    }
} else {
    Write-Host "Aucune réponse ✗" -ForegroundColor Red
}

Write-Host ""
Write-Host "=== Fin Diagnostic ===" -ForegroundColor Cyan

