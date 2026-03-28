param(
    [string]$BaseUrl = "http://127.0.0.1:8082"
)

$ErrorActionPreference = "Stop"

function Assert-Status {
    param(
        [string]$Url,
        [string]$Method = "GET",
        [hashtable]$Headers = @{},
        [object]$Body = $null,
        [int[]]$AllowedStatus = @(200, 301, 302)
    )

    try {
        $params = @{
            Uri = $Url
            Method = $Method
            Headers = $Headers
            MaximumRedirection = 0
            TimeoutSec = 20
            UseBasicParsing = $true
            ErrorAction = "Stop"
        }
        if ($null -ne $Body) {
            $params["Body"] = $Body
        }
        $resp = Invoke-WebRequest @params
        $status = [int]$resp.StatusCode
    } catch {
        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode
        } else {
            throw $_
        }
    }

    if ($AllowedStatus -notcontains $status) {
        throw "Echec smoke test: $Method $Url => HTTP $status (attendu: $($AllowedStatus -join ', '))"
    }
    Write-Host "OK $Method $Url => HTTP $status" -ForegroundColor Green
}

Write-Host "== Smoke test local n3 serveur ==" -ForegroundColor Cyan

# Pages publiques
Assert-Status -Url "$BaseUrl/"
Assert-Status -Url "$BaseUrl/ping" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/aquaponie"
Assert-Status -Url "$BaseUrl/meteo"
Assert-Status -Url "$BaseUrl/serre"
Assert-Status -Url "$BaseUrl/gallery"

# Auth + controle
Assert-Status -Url "$BaseUrl/login" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/aquaponie-control?token=local_admin_token_change_me" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/meteo-control?token=local_admin_token_change_me" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/serre-control?token=local_admin_token_change_me" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/gallery/ffp3/control?token=local_admin_token_change_me" -AllowedStatus @(200)

# APIs lecture et heartbeat
Assert-Status -Url "$BaseUrl/api/outputs/state" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/msp1/api/outputs/state" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/n3pp/api/outputs/state" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/gallery/ffp3/api/outputs/state" -AllowedStatus @(200)

$heartbeatPayload = @{
    uptime = "1200"
    free = "100000"
    min = "90000"
    reboots = "1"
}
$heartbeatPayload["crc"] = "0A2F41EE"
Assert-Status -Url "$BaseUrl/heartbeat" -Method "POST" -Body $heartbeatPayload -AllowedStatus @(200)

# POST data FFP3
$postData = @{
    api_key = "local_api_key_change_me"
    sensor = "esp32-local"
    version = "local-dev"
    TempAir = "25.1"
    Humidite = "60.0"
    TempEau = "23.9"
    EauPotager = "50"
    EauAquarium = "55"
    EauReserve = "45"
    diffMaree = "0"
    Luminosite = "500"
    etatPompeAqua = "0"
    etatPompeTank = "0"
    etatHeat = "0"
    etatUV = "0"
    bouffeMatin = "8"
    bouffeMidi = "12"
    bouffeSoir = "19"
    bouffePetits = "0"
    bouffeGros = "0"
    aqThreshold = "18"
    tankThreshold = "80"
    chauffageThreshold = "18"
    mail = "dev@local.test"
    mailNotif = "checked"
    resetMode = "0"
    tempsGros = "2"
    tempsPetits = "2"
    tempsRemplissageSec = "5"
    limFlood = "8"
    WakeUp = "0"
    FreqWakeUp = "6"
    post_id = "smoke-post-id-1"
}
Assert-Status -Url "$BaseUrl/post-data" -Method "POST" -Body $postData -AllowedStatus @(200)

# Upload photo galerie (JPEG existant du projet)
$uploadFile = Join-Path (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)) "public/assets/images/aquaponie-description/introduction.jpg"
if (Test-Path $uploadFile) {
    $form = @{
        imageFile = Get-Item $uploadFile
    }
    Assert-Status -Url "$BaseUrl/gallery/ffp3/upload" -Method "POST" -Headers @{ "X-Api-Key" = "local_api_key_change_me" } -Body $form -AllowedStatus @(200)
} else {
    Write-Warning "Upload JPEG saute: fichier introuvable ($uploadFile)"
}

Write-Host "Smoke test termine avec succes." -ForegroundColor Cyan
