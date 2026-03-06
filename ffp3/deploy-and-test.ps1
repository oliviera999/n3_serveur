# Script de test automatique FFP3 - PowerShell
# Usage: .\deploy-and-test.ps1

Write-Host "=== TEST AUTOMATIQUE FFP3 - PRODUCTION ===" -ForegroundColor Cyan
Write-Host ""

# URL de base
$BASE_URL = "https://iot.olution.info/ffp3"

Write-Host "[1/6] Test des pages web..." -ForegroundColor Yellow
Write-Host ""

# Fonction de test de page
function Test-Page {
    param(
        [string]$Url,
        [string]$Name,
        [string]$ExpectedCode
    )
    
    Write-Host "Test $Name : " -NoNewline
    try {
        $response = Invoke-WebRequest -Uri $Url -Method GET -UseBasicParsing -TimeoutSec 10
        $httpCode = $response.StatusCode
        
        if ($httpCode -eq $ExpectedCode) {
            Write-Host "OK HTTP $httpCode" -ForegroundColor Green
            return $true
        } else {
            Write-Host "ERREUR HTTP $httpCode (attendu: $ExpectedCode)" -ForegroundColor Red
            return $false
        }
    } catch {
        Write-Host "ERREUR: $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

# Test des pages principales
$pageResults = @()
$pageResults += Test-Page "$BASE_URL/" "Home" "200"
$pageResults += Test-Page "$BASE_URL/dashboard" "Dashboard" "200"
$pageResults += Test-Page "$BASE_URL/aquaponie" "Aquaponie" "200"
$pageResults += Test-Page "$BASE_URL/tide-stats" "Tide Stats" "200"
$pageResults += Test-Page "$BASE_URL/control" "Control" "200"

Write-Host ""
Write-Host "[2/6] Test des API temps reel..." -ForegroundColor Yellow
Write-Host ""

# Test des API temps reel
$apiResults = @()
$apiResults += Test-Page "$BASE_URL/api/realtime/sensors/latest" "API Sensors Latest" "200"
$apiResults += Test-Page "$BASE_URL/api/realtime/outputs/state" "API Outputs State" "200"
$apiResults += Test-Page "$BASE_URL/api/realtime/system/health" "API System Health" "200"

Write-Host ""
Write-Host "[3/6] Test des endpoints ESP32..." -ForegroundColor Yellow
Write-Host ""

# Test des endpoints ESP32
$esp32Results = @()
$esp32Results += Test-Page "$BASE_URL/post-data" "Post Data" "405"
$esp32Results += Test-Page "$BASE_URL/post-ffp3-data.php" "Post FFP3 Data" "401"
$esp32Results += Test-Page "$BASE_URL/heartbeat" "Heartbeat" "405"

Write-Host ""
Write-Host "[4/6] Test des redirections legacy..." -ForegroundColor Yellow
Write-Host ""

# Test des redirections
Write-Host "Test redirection /ffp3-data : " -NoNewline
try {
    $response = Invoke-WebRequest -Uri "$BASE_URL/ffp3-data" -Method GET -UseBasicParsing -TimeoutSec 10
    $redirectCode = $response.StatusCode
    if ($redirectCode -eq 301 -or $redirectCode -eq 200) {
        Write-Host "OK HTTP $redirectCode" -ForegroundColor Green
    } else {
        Write-Host "ERREUR HTTP $redirectCode" -ForegroundColor Red
    }
} catch {
    Write-Host "ERREUR: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host "Test redirection /heartbeat.php : " -NoNewline
try {
    $response = Invoke-WebRequest -Uri "$BASE_URL/heartbeat.php" -Method GET -UseBasicParsing -TimeoutSec 10
    $redirectCode = $response.StatusCode
    if ($redirectCode -eq 301 -or $redirectCode -eq 200) {
        Write-Host "OK HTTP $redirectCode" -ForegroundColor Green
    } else {
        Write-Host "ERREUR HTTP $redirectCode" -ForegroundColor Red
    }
} catch {
    Write-Host "ERREUR: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""
Write-Host "[5/6] Test des ressources statiques..." -ForegroundColor Yellow
Write-Host ""

# Test des ressources statiques
$staticResults = @()
$staticResults += Test-Page "$BASE_URL/ota/metadata.json" "OTA Metadata" "200"
$staticResults += Test-Page "$BASE_URL/public/manifest.json" "PWA Manifest" "200"

Write-Host ""
Write-Host "[6/6] Test des environnements TEST..." -ForegroundColor Yellow
Write-Host ""

# Test des environnements TEST
$testResults = @()
$testResults += Test-Page "$BASE_URL/dashboard-test" "Dashboard TEST" "200"
$testResults += Test-Page "$BASE_URL/aquaponie-test" "Aquaponie TEST" "200"
$testResults += Test-Page "$BASE_URL/tide-stats-test" "Tide Stats TEST" "200"
$testResults += Test-Page "$BASE_URL/control-test" "Control TEST" "200"

Write-Host ""
Write-Host "=== RESUME ===" -ForegroundColor Cyan
Write-Host ""

# Compter les erreurs
$errorCount = 0
$allResults = $pageResults + $apiResults + $esp32Results + $staticResults + $testResults

foreach ($result in $allResults) {
    if (-not $result) {
        $errorCount++
    }
}

if ($errorCount -eq 0) {
    Write-Host "TOUS LES TESTS PASSENT" -ForegroundColor Green
    Write-Host ""
    Write-Host "Toutes les pages et API fonctionnent correctement !"
    Write-Host "Le systeme FFP3 est operationnel."
} else {
    Write-Host "$errorCount ERREUR(S) DETECTEE(S)" -ForegroundColor Red
    Write-Host ""
    Write-Host "Certaines pages ou API ne fonctionnent pas correctement."
    Write-Host "Consultez les details ci-dessus pour identifier les problemes."
}

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "Pour plus d'informations, consultez:"
Write-Host "  - Les logs d'erreur: var/log/php_errors.log"
Write-Host "  - Les scripts de diagnostic: tools/diagnostic_500_errors.php"
Write-Host "  - Le CHANGELOG.md pour l'historique des modifications"
Write-Host "================================================================" -ForegroundColor Cyan