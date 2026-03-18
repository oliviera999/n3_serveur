# ==============================================================================
# Script de Test des Endpoints Serveur Distant
# Version: 1.0
# Description: Teste les endpoints POST-DATA (production et test)
# ==============================================================================

Write-Host "`n=== TEST ENDPOINTS SERVEUR DISTANT ===" -ForegroundColor Cyan
Write-Host "Date: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')`n" -ForegroundColor Gray

# Configuration - URLs conformes au routage serveur (sans /public/)
$url_prod = "http://iot.olution.info/ffp3/post-data"
$url_test = "http://iot.olution.info/ffp3/post-data-test"
$api_key = "fdGTMoptd5CD2ert3"

# Payload de test
$payload = @{
    api_key = $api_key
    sensor = "test-powershell"
    version = "11.30"
    TempAir = 25.0
    Humidite = 60.0
    TempEau = 28.0
    EauPotager = 200
    EauAquarium = 150
    EauReserve = 180
    diffMaree = 0
    Luminosite = 1000
    etatPompeAqua = 1
    etatPompeTank = 0
    etatHeat = 0
    etatUV = 1
}

Write-Host "Payload de test:" -ForegroundColor Yellow
$payload | Format-Table -AutoSize

# ==============================================================================
# Test Endpoint PRODUCTION
# ==============================================================================
Write-Host "`n[1/2] Test endpoint PRODUCTION..." -ForegroundColor Cyan
Write-Host "URL: $url_prod" -ForegroundColor Gray

try {
    $response_prod = Invoke-WebRequest -Uri $url_prod -Method POST -Body $payload -TimeoutSec 10
    
    Write-Host "✓ Status: HTTP $($response_prod.StatusCode)" -ForegroundColor Green
    Write-Host "  Content-Length: $($response_prod.Content.Length) bytes" -ForegroundColor Gray
    Write-Host "  Content-Type: $($response_prod.Headers['Content-Type'])" -ForegroundColor Gray
    
    # Détecter si c'est du HTML (erreur) ou du texte (succès)
    $content = $response_prod.Content
    if ($content -match "<!DOCTYPE|<html|<HTML") {
        Write-Host "  ⚠️ ALERTE: Réponse HTML détectée (attendu: texte brut)" -ForegroundColor Yellow
        Write-Host "  Aperçu (100 premiers caractères):" -ForegroundColor Gray
        Write-Host "  $($content.Substring(0, [Math]::Min(100, $content.Length)))" -ForegroundColor DarkGray
    } else {
        Write-Host "  ✓ Réponse: $content" -ForegroundColor Green
    }
} catch {
    Write-Host "✗ ERREUR: $($_.Exception.Message)" -ForegroundColor Red
    if ($_.Exception.Response) {
        Write-Host "  Status: $($_.Exception.Response.StatusCode.value__)" -ForegroundColor Red
    }
}

# ==============================================================================
# Test Endpoint TEST
# ==============================================================================
Write-Host "`n[2/2] Test endpoint TEST..." -ForegroundColor Cyan
Write-Host "URL: $url_test" -ForegroundColor Gray

try {
    $response_test = Invoke-WebRequest -Uri $url_test -Method POST -Body $payload -TimeoutSec 10
    
    Write-Host "✓ Status: HTTP $($response_test.StatusCode)" -ForegroundColor Green
    Write-Host "  Content-Length: $($response_test.Content.Length) bytes" -ForegroundColor Gray
    Write-Host "  Content-Type: $($response_test.Headers['Content-Type'])" -ForegroundColor Gray
    
    # Détecter si c'est du HTML (erreur) ou du texte (succès)
    $content = $response_test.Content
    if ($content -match "<!DOCTYPE|<html|<HTML") {
        Write-Host "  ⚠️ ALERTE: Réponse HTML détectée (attendu: texte brut)" -ForegroundColor Yellow
        Write-Host "  Aperçu (100 premiers caractères):" -ForegroundColor Gray
        Write-Host "  $($content.Substring(0, [Math]::Min(100, $content.Length)))" -ForegroundColor DarkGray
    } else {
        Write-Host "  ✓ Réponse: $content" -ForegroundColor Green
    }
} catch {
    Write-Host "✗ ERREUR: $($_.Exception.Message)" -ForegroundColor Red
    if ($_.Exception.Response) {
        Write-Host "  Status: $($_.Exception.Response.StatusCode.value__)" -ForegroundColor Red
    }
}

# ==============================================================================
# Résumé
# ==============================================================================
Write-Host "`n=== RÉSUMÉ ===" -ForegroundColor Cyan
Write-Host "Endpoint PRODUCTION: " -NoNewline
if ($response_prod -and $response_prod.StatusCode -eq 200) {
    Write-Host "✓ OK" -ForegroundColor Green
} else {
    Write-Host "✗ ÉCHEC" -ForegroundColor Red
}

Write-Host "Endpoint TEST:       " -NoNewline
if ($response_test -and $response_test.StatusCode -eq 200) {
    Write-Host "✓ OK" -ForegroundColor Green
} else {
    Write-Host "✗ ÉCHEC" -ForegroundColor Red
}

Write-Host "`n=== RECOMMANDATIONS ===" -ForegroundColor Cyan
if ($response_prod -and $response_prod.Content -match "<!DOCTYPE|<html") {
    Write-Host "⚠️ Endpoint PROD renvoie du HTML → Vérifier configuration serveur" -ForegroundColor Yellow
}
if ($response_test -and $response_test.Content -match "<!DOCTYPE|<html") {
    Write-Host "⚠️ Endpoint TEST renvoie du HTML → Créer/corriger post-data-test.php" -ForegroundColor Yellow
}

Write-Host ""

