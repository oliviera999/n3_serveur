# Script de comparaison entre ffp3 local et serveur distant
# Compare les fichiers critiques pour identifier les différences

param(
    [Parameter()]
    [string]$ServerUrl = "https://iot.olution.info/ffp3",
    [Parameter()]
    [switch]$Verbose
)

Write-Host "🔍 Comparaison ffp3 local vs serveur distant" -ForegroundColor Cyan
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan

# Liste des fichiers critiques à vérifier
$filesToCheck = @(
    @{
        Local = "ffp3/ota/metadata.json"
        Remote = "$ServerUrl/ota/metadata.json"
        Critical = $true
    },
    @{
        Local = "ffp3/ffp3datas/post-ffp3-data.php"
        Remote = "$ServerUrl/ffp3datas/post-ffp3-data.php"
        Critical = $true
    },
    @{
        Local = "ffp3/ffp3datas/public/post-data.php"
        Remote = "$ServerUrl/ffp3datas/public/post-data.php"
        Critical = $true
    },
    @{
        Local = "ffp3/ffp3datas/ffp3-data.php"
        Remote = "$ServerUrl/ffp3datas/ffp3-data.php"
        Critical = $true
    },
    @{
        Local = "ffp3/ffp3datas/legacy_bridge.php"
        Remote = "$ServerUrl/ffp3datas/legacy_bridge.php"
        Critical = $false
    },
    @{
        Local = "ffp3/ffp3datas/public/index.php"
        Remote = "$ServerUrl/ffp3datas/public/index.php"
        Critical = $false
    },
    @{
        Local = "ffp3/ffp3datas/.htaccess"
        Remote = "$ServerUrl/ffp3datas/.htaccess"
        Critical = $true
    },
    @{
        Local = "ffp3/ffp3datas/public/.htaccess"
        Remote = "$ServerUrl/ffp3datas/public/.htaccess"
        Critical = $true
    }
)

# Fonction pour télécharger un fichier distant
function Get-RemoteFileContent {
    param(
        [string]$Url
    )
    
    try {
        $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
        return @{
            Success = $true
            Content = $response.Content
            StatusCode = $response.StatusCode
        }
    }
    catch {
        return @{
            Success = $false
            Error = $_.Exception.Message
            StatusCode = if ($_.Exception.Response) { $_.Exception.Response.StatusCode.value__ } else { 0 }
        }
    }
}

# Fonction pour comparer deux contenus
function Compare-FileContent {
    param(
        [string]$Content1,
        [string]$Content2
    )
    
    # Normaliser les sauts de ligne
    $normalized1 = $Content1 -replace "`r`n", "`n"
    $normalized2 = $Content2 -replace "`r`n", "`n"
    
    if ($normalized1 -eq $normalized2) {
        return @{
            Identical = $true
            Differences = 0
        }
    }
    else {
        # Compter les différences approximatives
        $lines1 = $normalized1 -split "`n"
        $lines2 = $normalized2 -split "`n"
        $diffCount = [Math]::Abs($lines1.Count - $lines2.Count)
        
        return @{
            Identical = $false
            Differences = $diffCount
            LocalLines = $lines1.Count
            RemoteLines = $lines2.Count
        }
    }
}

# Résultats de la comparaison
$results = @()
$criticalIssues = 0
$totalChecked = 0
$totalErrors = 0

foreach ($file in $filesToCheck) {
    $totalChecked++
    
    Write-Host "`n[$totalChecked/$($filesToCheck.Count)] Vérification: " -NoNewline
    Write-Host $file.Local -ForegroundColor Yellow
    
    # Vérifier si le fichier local existe
    if (-not (Test-Path $file.Local)) {
        Write-Host "  ❌ Fichier local introuvable" -ForegroundColor Red
        $results += @{
            File = $file.Local
            Status = "LocalMissing"
            Critical = $file.Critical
        }
        if ($file.Critical) { $criticalIssues++ }
        continue
    }
    
    # Lire le fichier local
    $localContent = Get-Content -Path $file.Local -Raw -ErrorAction SilentlyContinue
    
    # Télécharger le fichier distant
    Write-Host "  📥 Téléchargement depuis le serveur..." -NoNewline
    $remoteResult = Get-RemoteFileContent -Url $file.Remote
    
    if (-not $remoteResult.Success) {
        Write-Host " ❌" -ForegroundColor Red
        Write-Host "     Erreur: $($remoteResult.Error)" -ForegroundColor Red
        Write-Host "     Code HTTP: $($remoteResult.StatusCode)" -ForegroundColor Red
        
        $results += @{
            File = $file.Local
            Status = "RemoteError"
            Error = $remoteResult.Error
            HttpCode = $remoteResult.StatusCode
            Critical = $file.Critical
        }
        $totalErrors++
        if ($file.Critical) { $criticalIssues++ }
        continue
    }
    
    Write-Host " ✅" -ForegroundColor Green
    
    # Comparer les contenus
    Write-Host "  🔄 Comparaison des contenus..." -NoNewline
    $comparison = Compare-FileContent -Content1 $localContent -Content2 $remoteResult.Content
    
    if ($comparison.Identical) {
        Write-Host " ✅ Identiques" -ForegroundColor Green
        $results += @{
            File = $file.Local
            Status = "Identical"
            Critical = $file.Critical
        }
    }
    else {
        Write-Host " ⚠️ DIFFÉRENTS" -ForegroundColor Yellow
        Write-Host "     Local: $($comparison.LocalLines) lignes" -ForegroundColor Cyan
        Write-Host "     Distant: $($comparison.RemoteLines) lignes" -ForegroundColor Cyan
        Write-Host "     Différence: ~$($comparison.Differences) lignes" -ForegroundColor Yellow
        
        $results += @{
            File = $file.Local
            Status = "Different"
            LocalLines = $comparison.LocalLines
            RemoteLines = $comparison.RemoteLines
            Diff = $comparison.Differences
            Critical = $file.Critical
        }
        
        if ($file.Critical) { $criticalIssues++ }
        
        # Sauvegarder les différences pour inspection
        if ($Verbose) {
            $diffFile = "diff_$(Split-Path $file.Local -Leaf).txt"
            @"
=== FICHIER LOCAL ===
$localContent

=== FICHIER DISTANT ===
$($remoteResult.Content)
"@ | Out-File -FilePath $diffFile -Encoding UTF8
            Write-Host "     💾 Différences sauvegardées dans: $diffFile" -ForegroundColor Cyan
        }
    }
}

# Résumé
Write-Host "`n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
Write-Host "📊 RÉSUMÉ DE LA COMPARAISON" -ForegroundColor Cyan
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan

$identical = ($results | Where-Object { $_.Status -eq "Identical" }).Count
$different = ($results | Where-Object { $_.Status -eq "Different" }).Count
$localMissing = ($results | Where-Object { $_.Status -eq "LocalMissing" }).Count
$remoteError = ($results | Where-Object { $_.Status -eq "RemoteError" }).Count

Write-Host "`n✅ Identiques:        " -NoNewline; Write-Host "$identical" -ForegroundColor Green
Write-Host "⚠️  Différents:        " -NoNewline; Write-Host "$different" -ForegroundColor Yellow
Write-Host "❌ Fichiers manquants:" -NoNewline; Write-Host "$localMissing" -ForegroundColor Red
Write-Host "❌ Erreurs distantes: " -NoNewline; Write-Host "$remoteError" -ForegroundColor Red
Write-Host "`n🚨 Problèmes critiques: " -NoNewline
if ($criticalIssues -gt 0) {
    Write-Host "$criticalIssues" -ForegroundColor Red
}
else {
    Write-Host "0" -ForegroundColor Green
}

# Afficher les fichiers différents en détail
if ($different -gt 0) {
    Write-Host "`n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Yellow
    Write-Host "⚠️  FICHIERS DIFFÉRENTS (à synchroniser)" -ForegroundColor Yellow
    Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Yellow
    
    $results | Where-Object { $_.Status -eq "Different" } | ForEach-Object {
        $criticalMark = if ($_.Critical) { "🚨" } else { "ℹ️" }
        Write-Host "`n$criticalMark $($_.File)" -ForegroundColor $(if ($_.Critical) { "Red" } else { "Yellow" })
        Write-Host "   Local: $($_.LocalLines) lignes | Distant: $($_.RemoteLines) lignes"
    }
}

# Afficher les erreurs
if ($remoteError -gt 0) {
    Write-Host "`n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Red
    Write-Host "❌ ERREURS D'ACCÈS AU SERVEUR" -ForegroundColor Red
    Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Red
    
    $results | Where-Object { $_.Status -eq "RemoteError" } | ForEach-Object {
        Write-Host "`n❌ $($_.File)" -ForegroundColor Red
        Write-Host "   Erreur: $($_.Error)"
        Write-Host "   Code HTTP: $($_.HttpCode)"
    }
}

# Recommandations
Write-Host "`n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
Write-Host "💡 RECOMMANDATIONS" -ForegroundColor Cyan
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan

if ($criticalIssues -gt 0) {
    Write-Host "`n🚨 Action requise:" -ForegroundColor Red
    Write-Host "   Des fichiers critiques sont différents ou inaccessibles."
    Write-Host "   Il est recommandé de synchroniser avec le serveur distant."
    Write-Host "`n   Pour voir les différences détaillées:" -ForegroundColor Yellow
    Write-Host "   .\compare_ffp3_remote.ps1 -Verbose" -ForegroundColor Cyan
    Write-Host "`n   Pour synchroniser (si vous avez accès FTP/SSH):" -ForegroundColor Yellow
    Write-Host "   .\sync_ffp3distant.ps1 push" -ForegroundColor Cyan
}
elseif ($different -gt 0) {
    Write-Host "`n⚠️  Attention:" -ForegroundColor Yellow
    Write-Host "   Des fichiers non-critiques sont différents."
    Write-Host "   Vérifiez si une synchronisation est nécessaire."
}
else {
    Write-Host "`n✅ Tout est en ordre!" -ForegroundColor Green
    Write-Host "   Les fichiers locaux et distants sont identiques."
}

Write-Host "`n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan

# Sauvegarder le rapport
$reportFile = "ffp3_comparison_report_$(Get-Date -Format 'yyyy-MM-dd_HH-mm-ss').txt"
$results | ConvertTo-Json -Depth 3 | Out-File -FilePath $reportFile -Encoding UTF8
Write-Host "📄 Rapport sauvegardé dans: $reportFile" -ForegroundColor Cyan

# Code de sortie
if ($criticalIssues -gt 0) {
    exit 1
}
else {
    exit 0
}


