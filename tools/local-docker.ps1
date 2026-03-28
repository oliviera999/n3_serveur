param(
    [ValidateSet("up", "down", "restart", "logs", "ps", "smoke", "test", "shell")]
    [string]$Action = "up"
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$compose = Join-Path $root "docker-compose.local.yml"
$composeEnvFile = Join-Path $root ".env.docker.example"

if (-not (Test-Path $compose)) {
    throw "Fichier introuvable: $compose"
}

function Initialize-LocalEnv {
    $envFile = Join-Path $root ".env"
    $example = Join-Path $root ".env.docker.example"
    if (-not (Test-Path $envFile) -and (Test-Path $example)) {
        Copy-Item $example $envFile
        Write-Host ".env cree depuis .env.docker.example" -ForegroundColor Green
    }
}

switch ($Action) {
    "up" {
        Initialize-LocalEnv
        docker compose --env-file $composeEnvFile -f $compose up -d --build
    }
    "down" {
        docker compose --env-file $composeEnvFile -f $compose down
    }
    "restart" {
        docker compose --env-file $composeEnvFile -f $compose down
        Initialize-LocalEnv
        docker compose --env-file $composeEnvFile -f $compose up -d --build
    }
    "logs" {
        docker compose --env-file $composeEnvFile -f $compose logs --tail 200
    }
    "ps" {
        docker compose --env-file $composeEnvFile -f $compose ps
    }
    "smoke" {
        & (Join-Path $root "tools/local-smoke-test.ps1")
    }
    "test" {
        docker compose --env-file $composeEnvFile -f $compose exec app composer test
    }
    "shell" {
        docker compose --env-file $composeEnvFile -f $compose exec app sh
    }
}
