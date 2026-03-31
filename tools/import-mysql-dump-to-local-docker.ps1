# Importe un dump MySQL (ex. phpMyAdmin export de production) dans la base Docker locale
# iot_n3_local, sans creer de tables inexistantes : import brut dans iot_n3_import_staging
# puis copie mappee via docker/mysql/sync-import-staging-to-local.sql.
#
# Prerequis : stack locale demarree (local-docker.ps1 -Action up), conteneur n3_iot_db_local.
#
# Usage (depuis serveur/) :
#   powershell -ExecutionPolicy Bypass -File .\tools\import-mysql-dump-to-local-docker.ps1 -DumpPath "C:\chemin\oliviera_iot.sql"
#
# Options :
#   -SyncOnly  : la base staging contient deja l'import ; rejouer uniquement la synchronisation vers iot_n3_local.
#
# Confidentialite : le dump peut contenir des e-mails ou chemins serveur ; ne pas versionner le fichier .sql.

param(
    [Parameter(Mandatory = $false)]
    [string]$DumpPath = "",
    [switch]$SyncOnly
)

$ErrorActionPreference = "Stop"

$ServerRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$ContainerDb = "n3_iot_db_local"
$StagingDb = "iot_n3_import_staging"
$SyncSql = Join-Path $ServerRoot "docker\mysql\sync-import-staging-to-local.sql"
$RootPassword = "root_local_iot_n3"

function Test-ContainerRunning {
    param([string]$Name)
    $names = docker ps --format "{{.Names}}" 2>$null
    return ($names -contains $Name)
}

if (-not (Test-Path $SyncSql)) {
    throw "Fichier SQL de synchro introuvable: $SyncSql"
}

if (-not (Test-ContainerRunning -Name $ContainerDb)) {
    throw "Conteneur $ContainerDb non actif. Demarrer la stack : tools\local-docker.ps1 -Action up"
}

if (-not $SyncOnly) {
    if ([string]::IsNullOrWhiteSpace($DumpPath)) {
        throw "Preciser -DumpPath vers le fichier .sql exporte, ou utiliser -SyncOnly si la staging est deja chargee."
    }
    $resolved = Resolve-Path -Path $DumpPath -ErrorAction Stop
    Write-Host "Import du dump dans ${StagingDb} (peut prendre plusieurs minutes)..." -ForegroundColor Cyan
    docker cp "$resolved" "${ContainerDb}:/tmp/n3_import_dump.sql"
    try {
        docker exec -e "MYSQL_PWD=$RootPassword" $ContainerDb mysql -uroot -e "DROP DATABASE IF EXISTS iot_n3_import_staging; CREATE DATABASE iot_n3_import_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        docker exec -e "MYSQL_PWD=$RootPassword" $ContainerDb sh -c "mysql --max_allowed_packet=512M -uroot $StagingDb < /tmp/n3_import_dump.sql"
        if ($LASTEXITCODE -ne 0) {
            throw "Echec import MySQL (code $LASTEXITCODE). Verifier le dump et les logs du conteneur."
        }
    }
    finally {
        docker exec $ContainerDb rm -f /tmp/n3_import_dump.sql
    }
    Write-Host "Import staging termine." -ForegroundColor Green
}

Write-Host "Synchronisation staging -> iot_n3_local..." -ForegroundColor Cyan
docker cp "$SyncSql" "${ContainerDb}:/tmp/sync-import-staging-to-local.sql"
try {
    docker exec -e "MYSQL_PWD=$RootPassword" $ContainerDb sh -c "mysql --max_allowed_packet=512M -uroot < /tmp/sync-import-staging-to-local.sql"
    if ($LASTEXITCODE -ne 0) {
        throw "Echec synchronisation (code $LASTEXITCODE)."
    }
}
finally {
    docker exec $ContainerDb rm -f /tmp/sync-import-staging-to-local.sql
}

Write-Host "Termine. Verifier : docker exec -e MYSQL_PWD=... n3_iot_db_local mysql -uroot -e `"SELECT COUNT(*) FROM iot_n3_local.ffp3Data`"" -ForegroundColor Green
Write-Host "Puis : local-docker.ps1 -Action smoke / test" -ForegroundColor Green
