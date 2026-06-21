# Applique les migrations SQL récentes + bootstrap admin.
# Usage (depuis serveur/) :
#   powershell -ExecutionPolicy Bypass -File .\tools\apply-recent-migrations.ps1
#   powershell -ExecutionPolicy Bypass -File .\tools\apply-recent-migrations.ps1 -DryRun

param(
    [switch]$DryRun,
    [switch]$SkipBootstrap
)

$ErrorActionPreference = "Stop"
$serveurRoot = Split-Path -Parent $PSScriptRoot
Set-Location $serveurRoot

$argsList = @()
if ($DryRun) { $argsList += "--dry-run" }
if ($SkipBootstrap) { $argsList += "--skip-bootstrap" }

& php tools/apply-recent-migrations.php @argsList
exit $LASTEXITCODE
