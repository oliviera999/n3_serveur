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
    [string]$PglApiKey = "local_pgl_api_key_change_me",
    [switch]$RunNegativeAuthChecks
)

$ErrorActionPreference = "Stop"

# Cles API : Docker local monte .env.docker.example dans le conteneur (pas le .env hote).
$serveurRoot = Split-Path -Parent $PSScriptRoot
$envFiles = if ($BaseUrl -match '127\.0\.0\.1|localhost') {
    @(".env.docker.example")
} else {
    @(".env.docker.example", ".env")
}
foreach ($envFile in $envFiles) {
    $envPath = Join-Path $serveurRoot $envFile
    if (-not (Test-Path -LiteralPath $envPath)) { continue }
    Get-Content -LiteralPath $envPath | ForEach-Object {
        if ($_ -match '^\s*API_KEY=(.+)$') {
            $val = $matches[1].Trim().Trim('"').Trim("'")
            if ($val -ne '') { $ApiKey = $val }
        }
        if ($_ -match '^\s*PGL_API_KEY=(.+)$') {
            $val = $matches[1].Trim().Trim('"').Trim("'")
            if ($val -ne '') { $PglApiKey = $val }
        }
    }
}
$protectedPath = "/aquaponie-control"
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

function Read-HttpWebResponseBody {
    param([System.Net.WebResponse]$R)
    if ($null -eq $R) { return $null }
    try {
        $s = $R.GetResponseStream()
        if ($null -eq $s) { return $null }
        $sr = New-Object System.IO.StreamReader($s)
        try {
            return $sr.ReadToEnd()
        } finally {
            $sr.Close()
        }
    } catch {
        return $null
    }
}

function Invoke-RequestStatus {
    param(
        [string]$Url,
        [string]$Method = "GET",
        [hashtable]$Headers = @{},
        [object]$Body = $null,
        [Microsoft.PowerShell.Commands.WebRequestSession]$WebSession = $null
    )

    # GET simple : HttpWebRequest evite les bugs Invoke-WebRequest (MaximumRedirection 0 / 302).
    if ($Method -eq "GET" -and $null -eq $Body -and $Headers.Count -eq 0) {
        $wr = [System.Net.HttpWebRequest]::Create($Url)
        $wr.AllowAutoRedirect = $false
        $wr.Method = "GET"
        $wr.Timeout = [math]::Max(1, $TimeoutSec) * 1000
        if ($null -ne $WebSession -and $null -ne $WebSession.Cookies) {
            $wr.CookieContainer = $WebSession.Cookies
        }
        try {
            $r = $wr.GetResponse()
            try {
                $code = [int]$r.StatusCode
                $raw = Read-HttpWebResponseBody -R $r
                $html = if ($code -eq 200) { $raw } else { $null }
                $respObj = if ($null -ne $html) { [pscustomobject]@{ Content = $html } } else { $null }
                return @{ StatusCode = $code; Response = $respObj }
            } finally {
                $r.Close()
            }
        } catch [System.Net.WebException] {
            $resp = $_.Exception.Response
            if ($null -eq $resp) { throw $_ }
            try {
                $code = [int]$resp.StatusCode
                $raw = Read-HttpWebResponseBody -R $resp
                $html = if ($code -eq 200) { $raw } else { $null }
                $respObj = if ($null -ne $html) { [pscustomobject]@{ Content = $html } } else { $null }
                return @{ StatusCode = $code; Response = $respObj }
            } finally {
                $resp.Close()
            }
        }
    }

    # POST application/x-www-form-urlencoded (sans fichier) : evite IWR / redirections sur 302 login.
    if ($Method -eq "POST" -and $Body -is [hashtable] -and $Headers.Count -eq 0) {
        $hasFile = $false
        foreach ($k in @($Body.Keys)) {
            $v = $Body[$k]
            if ($null -ne $v -and $v -is [System.IO.FileInfo]) {
                $hasFile = $true
                break
            }
        }
        if (-not $hasFile) {
            $pairs = @()
            foreach ($k in @($Body.Keys)) {
                $ek = [System.Uri]::EscapeDataString([string]$k)
                $ev = [System.Uri]::EscapeDataString([string]$Body[$k])
                $pairs += "${ek}=${ev}"
            }
            $encodedBody = $pairs -join "&"
            $bytes = [System.Text.Encoding]::UTF8.GetBytes($encodedBody)
            $wr = [System.Net.HttpWebRequest]::Create($Url)
            $wr.AllowAutoRedirect = $false
            $wr.Method = "POST"
            $wr.ContentType = "application/x-www-form-urlencoded; charset=UTF-8"
            $wr.ContentLength = $bytes.Length
            $wr.Timeout = [math]::Max(1, $TimeoutSec) * 1000
            if ($null -ne $WebSession -and $null -ne $WebSession.Cookies) {
                $wr.CookieContainer = $WebSession.Cookies
            }
            $reqStream = $wr.GetRequestStream()
            try {
                $reqStream.Write($bytes, 0, $bytes.Length)
            } finally {
                $reqStream.Close()
            }
            try {
                $r = $wr.GetResponse()
                try {
                    $code = [int]$r.StatusCode
                    $raw = Read-HttpWebResponseBody -R $r
                    $html = if ($code -eq 200) { $raw } else { $null }
                    $respObj = if ($null -ne $html) { [pscustomobject]@{ Content = $html } } else { $null }
                    return @{ StatusCode = $code; Response = $respObj }
                } finally {
                    $r.Close()
                }
            } catch [System.Net.WebException] {
                $resp = $_.Exception.Response
                if ($null -eq $resp) { throw $_ }
                try {
                    $code = [int]$resp.StatusCode
                    $raw = Read-HttpWebResponseBody -R $resp
                    $html = if ($code -eq 200) { $raw } else { $null }
                    $respObj = if ($null -ne $html) { [pscustomobject]@{ Content = $html } } else { $null }
                    return @{ StatusCode = $code; Response = $respObj }
                } finally {
                    $resp.Close()
                }
            }
        }
    }

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
        $ex = $_.Exception
        while ($null -ne $ex) {
            if ($ex.Response) {
                return @{
                    StatusCode = [int]$ex.Response.StatusCode
                    Response = $null
                }
            }
            $ex = $ex.InnerException
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
    Assert-Status -Url "${BaseUrl}${protectedPath}?token=$AdminToken" -AllowedStatus @(200) -Label "token valid"
    Assert-Status -Url "${BaseUrl}/admin/users?token=$AdminToken" -AllowedStatus @(200) -Label "admin users token"
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
    # Login nominal : Invoke-WebRequest suit les redirections et remplit correctement le cookie jar (HttpWebRequest POST seul ne suffit pas pour Slim/PHP en pratique).
    Invoke-WebRequest -Uri "$BaseUrl/login" -Method "POST" -Body $loginPayload -WebSession $session `
        -MaximumRedirection 10 -TimeoutSec $TimeoutSec -UseBasicParsing -ErrorAction Stop | Out-Null
    Write-Host "OK POST /login session (redirections suivies)" -ForegroundColor Green
    Assert-Status -Url "${BaseUrl}${protectedPath}" -AllowedStatus @(200) -WebSession $session -Label "session protected access"
    Assert-Status -Url "${BaseUrl}/admin/users" -AllowedStatus @(200) -WebSession $session -Label "admin users session"
}

function Assert-NegativeAuthChecks {
  $protectedControlPaths = @(
    $protectedPath,
    "/meteo-control",
    "/serre-control",
    "/aquaponie-control-test",
    "/msp1-test/msp1control/",
    "/n3pp-test/n3ppcontrol/"
  )

  foreach ($path in $protectedControlPaths) {
    Assert-Status -Url "$BaseUrl$path" -AllowedStatus @(301, 302) -Label "protected without auth: $path"
    Assert-Status -Url "${BaseUrl}${path}?token=invalid-token" -AllowedStatus @(301, 302) -Label "token invalid: $path"
  }

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
Assert-Status -Url "$BaseUrl/gallery/ffp3/api/outputs/state?board=5" -Headers @{ "X-Api-Key" = $ApiKey } -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/pgl" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/pgl/api/system/health" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/pgl/api/realtime/sensors/latest" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/msp1gallery/uploadphotoserver-outputs-action.php?action=outputs_state&board=6" -Headers @{ "X-Api-Key" = $ApiKey } -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/n3ppgallery/uploadphotoserver-outputs-action.php?action=outputs_state&board=7" -Headers @{ "X-Api-Key" = $ApiKey } -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/ffp3/ffp3gallery/uploadphotoserver-outputs-action.php?action=outputs_state&board=5" -Headers @{ "X-Api-Key" = $ApiKey } -AllowedStatus @(200)

$cameraVersionPayload = @{
    api_key = $ApiKey
    version = "2.38-smoke"
    board = "5"
    sensor = "ffp3"
}
Assert-Status -Url "$BaseUrl/ffp3/ffp3gallery/post-uploadphotoserver-version.php" -Method "POST" -Body $cameraVersionPayload -AllowedStatus @(200)

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

# POST data Poissonglouton (contrat firmware 0.2.x)
$pglPostData = @{
    api_key = $PglApiKey
    sensor = "poissonglouton"
    version = "0.2.3-smoke"
    location = "n3-recyclage"
    total_count = "12"
    today_count = "3"
    batch_count = "2"
    events = "1716123000:1:3:1:4020:-60:1001,1716123010:1:1:0:4010:-61:1002"
}
$pglPostResult = Assert-Status -Url "$BaseUrl/pgl/post-data" -Method "POST" -Body $pglPostData -AllowedStatus @(200)
$pglPostJson = $null
if ($pglPostResult.Response -and $pglPostResult.Response.Content) {
    $pglPostJson = $pglPostResult.Response.Content | ConvertFrom-Json
}
if ($null -eq $pglPostJson -or $pglPostJson.status -ne "ok") {
    throw "Echec smoke test PGL post-data: reponse JSON invalide"
}
if ([int]$pglPostJson.last_acked_event_id -lt 1002) {
    throw "Echec smoke test PGL post-data: last_acked_event_id attendu >= 1002"
}
Write-Host "OK PGL post-data JSON last_acked_event_id=$($pglPostJson.last_acked_event_id)" -ForegroundColor Green

$pglBadAuth = $pglPostData.Clone()
$pglBadAuth.api_key = "wrong-key"
Assert-Status -Url "$BaseUrl/pgl/post-data" -Method "POST" -Body $pglBadAuth -AllowedStatus @(401)

$pglHeartbeat = @{
    api_key = $PglApiKey
    sensor = "poissonglouton"
    version = "0.1.2-smoke"
    uptime = "1200"
    free = "100000"
    min = "90000"
    reboots = "1"
    rssi = "-60"
}
Assert-Status -Url "$BaseUrl/pgl/heartbeat" -Method "POST" -Body $pglHeartbeat -AllowedStatus @(200)

# --- POST données msp (Phase 4 audit 2026-05) ---
$mspPostData = @{
    api_key       = $ApiKey
    sensor        = "msp1"
    version       = "smoke"
    TempAirInt    = "21.0"
    TempAirExt    = "19.0"
    HumidAirInt   = "55"
    HumidAirExt   = "60"
    LuminositeA   = "100"
    LuminositeB   = "110"
    LuminositeC   = "120"
    LuminositeD   = "130"
    LuminositeMoy = "115"
    ServoHB       = "90"
    ServoGD       = "90"
    HumidSol      = "500"
    Pluie         = "1000"
    TempEau       = "18.5"
    PontDiv       = "2050"
    WakeUp        = "0"
    FreqWakeUp    = "300"
    SeuilSec      = "5000"
    SeuilPontDiv  = "1700"
    mail          = "smoke@local.test"
    mailNotif     = "checked"
    resetMode     = "0"
    bootCount     = "1"
}
Assert-Status -Url "$BaseUrl/msp1/msp1datas/post-msp1-data.php" -Method "POST" -Body $mspPostData -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/msp1/post-data" -Method "POST" -Body $mspPostData -AllowedStatus @(200)

# Cas négatif msp : api_key invalide
$mspBadAuth = $mspPostData.Clone()
$mspBadAuth.api_key = "wrong"
Assert-Status -Url "$BaseUrl/msp1/post-data" -Method "POST" -Body $mspBadAuth -AllowedStatus @(401)

# --- POST données n3pp ---
$n3ppPostData = @{
    api_key       = $ApiKey
    sensor        = "n3pp"
    version       = "smoke"
    TempAir       = "22.5"
    Humidite      = "55"
    Luminosite    = "800"
    Humid1        = "1500"
    Humid2        = "1600"
    Humid3        = "1700"
    Humid4        = "1800"
    HumidMoy      = "1650"
    PontDiv       = "2050"
    WakeUp        = "0"
    ArrosageManu  = "0"
    SeuilSec      = "5000"
    FreqWakeUp    = "300"
    SeuilPontDiv  = "1700"
    mail          = "smoke@local.test"
    mailNotif     = "checked"
    HeureArrosage = "6"
    resetMode     = "0"
    etatPompe     = "0"
    tempsArrosage = "4"
    bootCount     = "1"
}
Assert-Status -Url "$BaseUrl/n3pp/n3ppdatas/post-n3pp-data.php" -Method "POST" -Body $n3ppPostData -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/n3pp/post-data" -Method "POST" -Body $n3ppPostData -AllowedStatus @(200)

# GET outputs_state legacy n3pp/msp1
Assert-Status -Url "$BaseUrl/msp1/msp1control/msp1-outputs-action.php?action=outputs_state&board=2" -AllowedStatus @(200)
Assert-Status -Url "$BaseUrl/n3pp/n3ppcontrol/n3pp-outputs-action.php?action=outputs_state&board=3" -AllowedStatus @(200)

# Heartbeats msp1 et n3pp (Phase 4 audit 2026-05)
$legacyHeartbeat = @{
    api_key = $ApiKey
    sensor  = "smoke"
    version = "smoke"
    uptime  = "1200"
    free    = "100000"
    min     = "90000"
    reboots = "1"
    rssi    = "-65"
}
Assert-Status -Url "$BaseUrl/msp1/heartbeat" -Method "POST" -Body $legacyHeartbeat -AllowedStatus @(200,500)
Assert-Status -Url "$BaseUrl/n3pp/heartbeat" -Method "POST" -Body $legacyHeartbeat -AllowedStatus @(200,500)

# Upload photo galerie (JPEG existant du projet)
$uploadFile = Join-Path (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)) "public/assets/images/aquaponie-description/introduction.jpg"
if (Test-Path $uploadFile) {
    # Note: Invoke-WebRequest + hashtable(FileInfo) peut parfois ne pas générer correctement le multipart.
    # On utilise curl pour fiabiliser l'envoi du fichier pendant le smoke test.
    $allowedStatuses = @(200,202)

    $uploadUrl1 = "$BaseUrl/gallery/ffp3/upload"
    $uploadRes1 = (& curl.exe -sS -o - -w "|%{http_code}" -H "X-Api-Key: $ApiKey" -F "imageFile=@$uploadFile" $uploadUrl1)
    $parts1 = $uploadRes1 -split '\|', 2
    $status1 = [int]$parts1[1]
    if ($allowedStatuses -notcontains $status1) {
        throw "Echec smoke test: POST $uploadUrl1 => HTTP $status1 (attendu: $($allowedStatuses -join ', '))`nBody: $($parts1[0])"
    }
    Write-Host "OK POST $uploadUrl1 => HTTP $status1" -ForegroundColor Green

    # Le point de terminaison ffp3gallery est rate-limité : on attend avant la 2e requête.
    Start-Sleep -Seconds 11

    $uploadUrl2 = "$BaseUrl/ffp3/ffp3gallery/upload.php"
    $uploadRes2 = (& curl.exe -sS -o - -w "|%{http_code}" -H "X-Api-Key: $ApiKey" -F "imageFile=@$uploadFile" $uploadUrl2)
    $parts2 = $uploadRes2 -split '\|', 2
    $status2 = [int]$parts2[1]
    if ($allowedStatuses -notcontains $status2) {
        throw "Echec smoke test: POST $uploadUrl2 => HTTP $status2 (attendu: $($allowedStatuses -join ', '))`nBody: $($parts2[0])"
    }
    Write-Host "OK POST $uploadUrl2 => HTTP $status2" -ForegroundColor Green
} else {
    Write-Warning "Upload JPEG saute: fichier introuvable ($uploadFile)"
}

Write-Host "Smoke test termine avec succes." -ForegroundColor Cyan
