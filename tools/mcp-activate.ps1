<#
.SYNOPSIS
  Aide a l'activation du connecteur MCP n3 IoT (toutes les IA).

.DESCRIPTION
  Genere des secrets, un bloc .env, verifie la decouverte OAuth, propose
  les configs Cursor / Gemini. Guide : docs/MCP_ACTIVATION.md

.PARAMETER Action
  menu | check | secrets | env | cursor | gemini | guide | all

.PARAMETER BaseUrl
  URL publique du serveur (defaut https://iot.olution.info)

.PARAMETER CursorConfigPath
  Chemin mcp.json a ecrire (Action cursor). Si omis : affiche le JSON seulement.

.EXAMPLE
  c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1
  c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action check
  c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action all
  c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action cursor -CursorConfigPath "$env:USERPROFILE\.cursor\mcp.json"
#>
[CmdletBinding()]
param(
    [ValidateSet('menu', 'check', 'secrets', 'env', 'cursor', 'gemini', 'guide', 'all')]
    [string]$Action = 'menu',

    [string]$BaseUrl = 'https://iot.olution.info',

    [string]$CursorConfigPath = ''
)

$ErrorActionPreference = 'Stop'
$ScriptPath = $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent (Split-Path -Parent $ScriptPath)
$BaseUrl = $BaseUrl.TrimEnd('/')
$Doc = Join-Path $Root 'docs\MCP_ACTIVATION.md'
$CheckerPhp = Join-Path $Root 'tools\mcp-check-discovery.php'
$SmokePhp = Join-Path $Root 'tools\mcp-smoke.php'

function New-HexSecret {
    param([int]$Bytes = 32)
    $buf = New-Object byte[] $Bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buf)
    -join ($buf | ForEach-Object { $_.ToString('x2') })
}

function Show-Guide {
    Write-Host ""
    Write-Host "=== Guide d'activation MCP ===" -ForegroundColor Cyan
    Write-Host "Doc complete : $Doc"
    Write-Host ""
    Write-Host "A. Serveur (une fois) : backup -> migrations -> .env MCP_ENABLED=true -> check -> smoke"
    Write-Host "B. Client :"
    Write-Host "   Claude.ai     : Connecteurs -> URL $BaseUrl/mcp"
    Write-Host "   Claude Code   : claude mcp add --transport http iot $BaseUrl/mcp"
    Write-Host "   Cursor        : mcp.json { url: `"$BaseUrl/mcp`" }  (-Action cursor)"
    Write-Host "   ChatGPT       : serveur MCP custom OAuth -> $BaseUrl/mcp"
    Write-Host "   Gemini Ent.   : client confidentiel .env (-Action gemini) + console Google"
    Write-Host ""
    Write-Host "Commandes utiles :"
    Write-Host "  $ScriptPath -Action check"
    Write-Host "  php $CheckerPhp --base=$BaseUrl"
    Write-Host "  `$env:MCP_SMOKE_PASSWORD='...'; php $SmokePhp --base=$BaseUrl --user=<compte>"
    Write-Host ""
}

function Invoke-Secrets {
    $cred = New-HexSecret
    $health = New-HexSecret
    Write-Host ""
    Write-Host "=== Secrets generes (a coller dans .env) ===" -ForegroundColor Cyan
    Write-Host "MCP_CREDENTIAL_SECRET=$cred"
    Write-Host "MCP_HEALTH_TOKEN=$health"
    Write-Host ""
    Write-Host "Conservez-les hors du depot Git. Changer MCP_CREDENTIAL_SECRET revoque toutes les sessions MCP."
    return @{ Credential = $cred; Health = $health }
}

function Show-EnvSnippet {
    param($Secrets)
    if (-not $Secrets) {
        $Secrets = @{
            Credential = "<64 hex - $ScriptPath -Action secrets>"
            Health     = '<64 hex distinct>'
        }
    }
    Write-Host ""
    Write-Host "=== Bloc .env minimal (Partie A5) ===" -ForegroundColor Cyan
    @"
MCP_ENABLED=true
MCP_PUBLIC_BASE_URL=$BaseUrl
MCP_ALLOWED_ENVS=prod,s3
MCP_CONTROL_ENABLED=false
MCP_CREDENTIAL_SECRET=$($Secrets.Credential)
DB_MCP_USER=oliviera_mcpro
DB_MCP_PASS=...
# Optionnel sante :
# MCP_HEALTH_TOKEN=$($Secrets.Health)
# MCP_HEALTH_REPORT_ENABLED=true
# MCP_HEALTH_REPORT_HOUR=7
# MCP_HEALTH_REPORT_EMAIL=alert
"@ | Write-Host
}

function Get-HttpProbe {
    param(
        [Parameter(Mandatory = $true)][string]$Method,
        [Parameter(Mandatory = $true)][string]$Uri,
        [string]$Body = '',
        [string]$ContentType = ''
    )
    $tmpBody = [System.IO.Path]::GetTempFileName()
    $tmpHdr = [System.IO.Path]::GetTempFileName()
    try {
        $curl = Get-Command curl.exe -ErrorAction SilentlyContinue
        if (-not $curl) {
            return @{ Status = 0; Body = ''; Headers = ''; Error = 'curl.exe introuvable' }
        }
        $args = @(
            '-sS', '-L', '--max-redirs', '0', '--connect-timeout', '10', '--max-time', '25',
            '-X', $Method, '-D', $tmpHdr, '-o', $tmpBody, '-w', '%{http_code}'
        )
        if ($ContentType -ne '') {
            $args += @('-H', "Content-Type: $ContentType", '-H', 'Accept: application/json')
        }
        if ($Body -ne '') {
            $args += @('--data-binary', $Body)
        }
        $args += $Uri
        $codeText = & curl.exe @args 2>&1
        $exit = $LASTEXITCODE
        $bodyText = ''
        $hdrText = ''
        if (Test-Path $tmpBody) { $bodyText = Get-Content -Raw -Path $tmpBody -ErrorAction SilentlyContinue }
        if (Test-Path $tmpHdr) { $hdrText = Get-Content -Raw -Path $tmpHdr -ErrorAction SilentlyContinue }
        if ($exit -ne 0 -and ($codeText -as [int]) -eq 0) {
            return @{ Status = 0; Body = "$bodyText"; Headers = "$hdrText"; Error = "$codeText" }
        }
        $code = 0
        if ("$codeText" -match '(\d{3})\s*$') { $code = [int]$Matches[1] }
        return @{ Status = $code; Body = "$bodyText"; Headers = "$hdrText"; Error = '' }
    } finally {
        Remove-Item -Force -ErrorAction SilentlyContinue $tmpBody, $tmpHdr
    }
}

function Write-CheckResult {
    param([bool]$Pass, [string]$Label, [string]$Detail)
    if ($Pass) {
        Write-Host "[OK]  $Label - $Detail" -ForegroundColor Green
    } else {
        Write-Host "[FAIL] $Label - $Detail" -ForegroundColor Red
    }
}

function Invoke-Check {
    Write-Host ""
    Write-Host "=== Verification discovery - $BaseUrl ===" -ForegroundColor Cyan
    Write-Host "Outil: curl.exe (fiable sous Windows ; PHP curl echoue souvent en HTTP 0 / CA)"
    Write-Host ""

    $fail = 0
    $hint404 = "HTTP 404 = MCP inactif (mettre MCP_ENABLED=true sur le serveur) ou rewrite /.well-known"
    $hint0 = "HTTP 0 = reseau/TLS bloque depuis ce PC"

    $prm = Get-HttpProbe -Method GET -Uri "$BaseUrl/.well-known/oauth-protected-resource/mcp"
    $prmOk = $false
    $prmDetail = "HTTP $($prm.Status)"
    if ($prm.Status -eq 0) {
        $prmDetail = "HTTP 0 | $($prm.Error) | $hint0"
    } elseif ($prm.Status -eq 404) {
        $prmDetail = "HTTP 404 | $hint404"
    } elseif ($prm.Status -eq 200) {
        try {
            $j = $prm.Body | ConvertFrom-Json
            if ($j.resource -eq "$BaseUrl/mcp") {
                $prmOk = $true
                $prmDetail = "HTTP 200, resource=$($j.resource)"
            } else {
                $prmDetail = "HTTP 200, resource inattendu=$($j.resource)"
            }
        } catch {
            $prmDetail = "HTTP 200 mais JSON invalide"
        }
    }
    Write-CheckResult -Pass $prmOk -Label 'oauth-protected-resource/mcp' -Detail $prmDetail
    if (-not $prmOk) { $fail++ }

    $as = Get-HttpProbe -Method GET -Uri "$BaseUrl/.well-known/oauth-authorization-server"
    $asOk = $false
    $asDetail = "HTTP $($as.Status)"
    if ($as.Status -eq 0) {
        $asDetail = "HTTP 0 | $($as.Error) | $hint0"
    } elseif ($as.Status -eq 404) {
        $asDetail = "HTTP 404 | $hint404"
    } elseif ($as.Status -eq 200) {
        try {
            $j = $as.Body | ConvertFrom-Json
            if ($j.authorization_endpoint -and $j.token_endpoint) {
                $asOk = $true
                $methods = @($j.token_endpoint_auth_methods_supported) -join ','
                $asDetail = "HTTP 200, auth=$methods"
            } else {
                $asDetail = 'HTTP 200, metadata incomplete'
            }
        } catch {
            $asDetail = "HTTP 200 mais JSON invalide"
        }
    }
    Write-CheckResult -Pass $asOk -Label 'oauth-authorization-server' -Detail $asDetail
    if (-not $asOk) { $fail++ }

    $ping = Get-HttpProbe -Method POST -Uri "$BaseUrl/mcp" `
        -Body '{"jsonrpc":"2.0","id":1,"method":"ping"}' -ContentType 'application/json'
    $pingOk = $false
    $pingDetail = "HTTP $($ping.Status)"
    if ($ping.Status -eq 0) {
        $pingDetail = "HTTP 0 | $($ping.Error) | $hint0"
    } elseif ($ping.Status -eq 404) {
        $pingDetail = "HTTP 404 | $hint404"
    } elseif ($ping.Status -eq 401) {
        $hasWww = ($ping.Headers -match '(?i)WWW-Authenticate\s*:')
        if ($hasWww) {
            $pingOk = $true
            $pingDetail = 'HTTP 401 + WWW-Authenticate'
        } else {
            $pingDetail = 'HTTP 401 sans WWW-Authenticate'
        }
    }
    Write-CheckResult -Pass $pingOk -Label 'POST /mcp sans jeton -> 401' -Detail $pingDetail
    if (-not $pingOk) { $fail++ }

    Write-Host ""
    if ($fail -eq 0) {
        Write-Host "SMOKE DISCOVERY OK - brancher ensuite Claude / Cursor / ChatGPT / Gemini" -ForegroundColor Green
        Write-Host "Doc: $Doc"
        return 0
    }

    Write-Host "ECHEC ($fail point(s)). Prochaine etape habituelle :" -ForegroundColor Yellow
    Write-Host "  1) Sur le serveur o2switch : MCP_ENABLED=true + MCP_PUBLIC_BASE_URL=$BaseUrl"
    Write-Host "  2) Migrations SQL (docs/MCP_ACTIVATION.md A3)"
    Write-Host "  3) Relancer: $ScriptPath -Action check"
    Write-Host "  4) Detail: $Doc"
    return 1
}

function Show-CursorConfig {
    $json = @"
{
  "mcpServers": {
    "n3-iot": {
      "url": "$BaseUrl/mcp"
    }
  }
}
"@
    Write-Host ""
    Write-Host "=== Config Cursor (mcp.json) ===" -ForegroundColor Cyan
    Write-Host $json
    if ($CursorConfigPath -ne '') {
        $dir = Split-Path -Parent $CursorConfigPath
        if ($dir -and -not (Test-Path $dir)) {
            New-Item -ItemType Directory -Path $dir -Force | Out-Null
        }
        if (Test-Path $CursorConfigPath) {
            Write-Host "Fichier existant : $CursorConfigPath" -ForegroundColor Yellow
            Write-Host "Fusionnez manuellement la cle n3-iot (ecriture refusee pour ne pas ecraser)."
        } else {
            Set-Content -Path $CursorConfigPath -Value $json -Encoding UTF8
            Write-Host "Ecrit : $CursorConfigPath" -ForegroundColor Green
        }
    } else {
        Write-Host "Pour ecrire un fichier : -CursorConfigPath `"`$env:USERPROFILE\.cursor\mcp.json`""
        Write-Host "(Sur Cursor IDE le chemin exact peut varier - Settings > MCP.)"
    }
}

function Show-Gemini {
    $id = 'gemini-n3-iot'
    $secret = New-HexSecret -Bytes 32
    Write-Host ""
    Write-Host "=== Gemini Enterprise - client confidentiel ===" -ForegroundColor Cyan
    Write-Host "A ajouter dans le .env du serveur :"
    Write-Host "MCP_OAUTH_CONFIDENTIAL_CLIENT_ID=$id"
    Write-Host "MCP_OAUTH_CONFIDENTIAL_CLIENT_SECRET=$secret"
    Write-Host ""
    Write-Host "Console Gemini Enterprise (Custom MCP Server) :"
    Write-Host "  MCP URL            : $BaseUrl/mcp"
    Write-Host "  Authorization URL  : $BaseUrl/oauth/authorize"
    Write-Host "  Token URL          : $BaseUrl/oauth/token"
    Write-Host "  Client ID / Secret : valeurs ci-dessus"
    Write-Host "  Scopes             : iot.read offline_access"
    Write-Host "  PKCE               : active"
    Write-Host "  Redirect           : https://vertexaisearch.cloud.google.com/oauth-redirect"
    Write-Host ""
    Write-Host "Gemini grand public : non supporte."
}

function Show-Menu {
    Write-Host ""
    Write-Host "mcp-activate - connecteur n3 IoT" -ForegroundColor Cyan
    Write-Host "  1) guide     - rappel des etapes"
    Write-Host "  2) secrets   - generer MCP_CREDENTIAL_SECRET / MCP_HEALTH_TOKEN"
    Write-Host "  3) env       - afficher le bloc .env"
    Write-Host "  4) check     - well-known + 401 /mcp"
    Write-Host "  5) cursor    - JSON mcp.json"
    Write-Host "  6) gemini    - ID/secret Gemini Enterprise"
    Write-Host "  7) all       - secrets + env + check + guide"
    Write-Host "  q) quitter"
    $choice = Read-Host "Choix"
    switch ($choice) {
        '1' { return 'guide' }
        '2' { return 'secrets' }
        '3' { return 'env' }
        '4' { return 'check' }
        '5' { return 'cursor' }
        '6' { return 'gemini' }
        '7' { return 'all' }
        'q' { return 'quit' }
        default { return $choice }
    }
}

# --- main ---
if ($Action -eq 'menu') {
    $Action = Show-Menu
}

$exitCode = 0
$secrets = $null

switch ($Action) {
    'quit' { exit 0 }
    'guide' { Show-Guide }
    'secrets' { $null = Invoke-Secrets }
    'env' { Show-EnvSnippet }
    'check' { $exitCode = Invoke-Check }
    'cursor' { Show-CursorConfig }
    'gemini' { Show-Gemini }
    'all' {
        $secrets = Invoke-Secrets
        Show-EnvSnippet -Secrets $secrets
        $exitCode = Invoke-Check
        Show-Guide
    }
    default {
        Write-Host "Action inconnue : $Action" -ForegroundColor Red
        Show-Guide
        exit 2
    }
}

exit $exitCode
