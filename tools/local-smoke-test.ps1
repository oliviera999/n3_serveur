[Diagnostics.CodeAnalysis.SuppressMessageAttribute('PSAvoidUsingPlainTextForPassword', 'AdminPassword')]
param(
    [string]$BaseUrl = "http://127.0.0.1:8082",
    [int]$TimeoutSec = 60,
    [ValidateSet("token", "session", "both")]
    [string]$AuthMode = "token",
    [string]$AdminToken = "local_admin_token_change_me",
    [string]$AdminUsername = "admin",
    [string]$AdminPassword = "",
    [string]$ApiKey = "local_api_key_change_me",
    [switch]$RunNegativeAuthChecks
)

$ErrorActionPreference = "Stop"
$protectedPath = "/aquaponie-control"
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

function Invoke-RequestStatus {
    param(
        [string]$Url,
        [string]$Method = "GET",
        [hashtable]$Headers = @{},
        [object]$Body = $null,
        [Microsoft.PowerShell.Commands.WebRequestSession]$WebSession = $null
    )

    try {
        $params = @{
            Uri = $Url
            Method = $Method
            Headers = $Headers
            MaximumRedirection = 0
            TimeoutSec = $TimeoutSec
            UseBasicParsing = $true
            ErrorAction = "Stop"
        }
        if ($null -ne $Body) {
            $params["Body"] = $Body
        }
        if ($null -ne $WebSession) {
            $params["WebSession"] = $WebSession
        }
        $resp = Invoke-WebRequest @params
        return @{
            StatusCode = [int]$resp.StatusCode
            Response = $resp
        }
    } catch {
        if ($_.Exception.Response) {
            return @{
                StatusCode = [int]$_.Exception.Response.StatusCode
                Response = $null
            }
        }
        throw $_
    }
}

function Assert-Status {
    param(
        [string]$Url,
        [string]$Method = "GET",
        [hashtable]$Headers = @{},
        [object]$Body = $null,
        [int[]]$AllowedStatus = @(200, 301, 302),
        [Microsoft.PowerShell.Commands.WebRequestSession]$WebSession = $null,
        [string]$Label = ""
    )

    $result = Invoke-RequestStatus -Url $Url -Method $Method -Headers $Headers -Body $Body -WebSession $WebSession
    $status = [int]$result.StatusCode
    if ($AllowedStatus -notcontains $status) {
        throw "Echec smoke test: $Method $Url => HTTP $status (attendu: $($AllowedStatus -join ', '))"
    }

    if ([string]::IsNullOrWhiteSpace($Label)) {
        Write-Host "OK $Method $Url => HTTP $status" -ForegroundColor Green
    } else {
        Write-Host "OK $Label => HTTP $status" -ForegroundColor Green
    }

    return $result
}

function Get-CsrfTokenFromLogin {
    param(
        [Microsoft.PowerShell.Commands.WebRequestSession]$WebSession
    )

    $loginResp = Assert-Status -Url "$BaseUrl/login" -AllowedStatus @(200) -WebSession $WebSession -Label "GET /login"
    $html = if ($loginResp.Response -and $loginResp.Response.Content) { $loginResp.Response.Content } else { "" }
    $match = [regex]::Match($html, 'name="_csrf_token"\s+value="([^"]+)"')
    if (-not $match.Success) {
        throw "Token CSRF introuvable sur /login."
    }
    return $match.Groups[1].Value
}

function Assert-TokenAuth {
    if ([string]::IsNullOrWhiteSpace($AdminToken)) {
        throw "AdminToken est requis pour les tests token."
    }
    Assert-Status -Url "$BaseUrl$protectedPath?token=$AdminToken" -AllowedStatus @(200) -Label "token valid"
}

function Assert-SessionAuth {
    if ([string]::IsNullOrWhiteSpace($AdminPassword)) {
        throw "AdminPassword est requis pour les tests session."
    }

    $csrfToken = Get-CsrfTokenFromLogin -WebSession $session
    $loginPayload = @{
        username = $AdminUsername
        password = $AdminPassword
        redirect = $protectedPath
        _csrf_token = $csrfToken
    }
    Assert-Status -Url "$BaseUrl/login" -Method "POST" -Body $loginPayload -AllowedStatus @(301, 302) -WebSession $session -Label "POST /login session"
    Assert-Status -Url "$BaseUrl$protectedPath" -AllowedStatus @(200) -WebSession $session -Label "session protected access"
}

function Assert-NegativeAuthChecks {
    # Sans auth
    Assert-Status -Url "$BaseUrl$protectedPath" -AllowedStatus @(301, 302) -Label "protected without auth"

    # Token invalide
    Assert-Status -Url "$BaseUrl$protectedPath?token=invalid-token" -AllowedStatus @(301, 302) -Label "token invalid"

    # Login invalide
    $negSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrfToken = Get-CsrfTokenFromLogin -WebSession $negSession
    $badLoginPayload = @{
        username = $AdminUsername
        password = "__wrong_password__"
        redirect = $protectedPath
        _csrf_token = $csrfToken
    }
    Assert-Status -Url "$BaseUrl/login" -Method "POST" -Body $badLoginPayload -AllowedStatus @(301, 302) -WebSession $negSession -Label "login invalid"
    Assert-Status -Url "$BaseUrl$protectedPath" -AllowedStatus @(301, 302) -WebSession $negSession -Label "protected after invalid login"
}

Write-Host "== Smoke test local n3 serveur ==" -ForegroundColor Cyan
Write-Host "Mode auth smoke: $AuthMode" -ForegroundColor Cyan

# Pages publiques
Assert-Status -Url "$BaseUrl/"
Assert-Status -Url "$BaseUrl/ping" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/aquaponie"
Assert-Status -Url "$BaseUrl/meteo"
Assert-Status -Url "$BaseUrl/serre"
Assert-Status -Url "$BaseUrl/gallery"
Assert-Status -Url "$BaseUrl/login" -AllowedStatus @(200)

switch ($AuthMode) {
    "token" {
        Assert-TokenAuth
    }
    "session" {
        Assert-SessionAuth
    }
    "both" {
        Assert-TokenAuth
        Assert-SessionAuth
    }
}

if ($RunNegativeAuthChecks) {
    Assert-NegativeAuthChecks
}

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
    api_key = $ApiKey
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
    Assert-Status -Url "$BaseUrl/gallery/ffp3/upload" -Method "POST" -Headers @{ "X-Api-Key" = $ApiKey } -Body $form -AllowedStatus @(200)
} else {
    Write-Warning "Upload JPEG saute: fichier introuvable ($uploadFile)"
}

Write-Host "Smoke test termine avec succes." -ForegroundColor Cyan
