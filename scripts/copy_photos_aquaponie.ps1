# Copie les photos depuis le dossier "photos aquaponie" du projet vers public/assets/images/aquaponie-description/
#
# Racine du projet : C:\ffp5cs\ffp3 (dossier contenant public/, templates/, src/, etc.)
# Source par defaut : <racine projet>\photos aquaponie  (ex. C:\ffp5cs\ffp3\photos aquaponie)
# Destination : <racine projet>\public\assets\images\aquaponie-description\
#
# Usage (depuis la racine du projet) :
#   .\scripts\copy_photos_aquaponie.ps1
# Avec une autre source :
#   .\scripts\copy_photos_aquaponie.ps1 "D:\mes photos\photos aquaponie"

param(
    [string]$SourceDir = (Join-Path (Split-Path $PSScriptRoot -Parent) "photos aquaponie")
)

$ProjectRoot = Split-Path $PSScriptRoot -Parent
$DestDir = Join-Path $ProjectRoot "public\assets\images\aquaponie-description"
$DestDir = [System.IO.Path]::GetFullPath($DestDir)

if (-not (Test-Path $SourceDir)) {
    Write-Host "Dossier source introuvable : $SourceDir" -ForegroundColor Yellow
    Write-Host "Indiquez le chemin en parametre : .\scripts\copy_photos_aquaponie.ps1 'C:\chemin\photos aquaponie'" -ForegroundColor Gray
    exit 1
}

$pairs = @(
    @{ Src = "introduction.jpg"; Dest = "introduction.jpg" }
    @{ Src = "vue générale.jpg"; Dest = "vue-generale.jpg" }
    @{ Src = "électronique.jpg"; Dest = "electronique.jpg" }
    @{ Src = "poissons.jpg"; Dest = "poissons.jpg" }
)

foreach ($p in $pairs) {
    $srcPath = Join-Path $SourceDir $p.Src
    $destPath = Join-Path $DestDir $p.Dest
    if (Test-Path $srcPath) {
        Copy-Item $srcPath $destPath -Force
        Write-Host "Copie : $($p.Src) -> $($p.Dest)" -ForegroundColor Green
    } else {
        Write-Host "Absent : $($p.Src)" -ForegroundColor Red
    }
}

Write-Host "Termine. Destination : $DestDir" -ForegroundColor Cyan
