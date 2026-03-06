# Script de déploiement des endpoints POST data
# Commit + Push les modifications locales vers le serveur

Write-Host "=== Déploiement Endpoints POST Data v11.36 ===" -ForegroundColor Cyan
Write-Host ""

$ffp3Path = "C:\Users\olivi\Mon Drive\travail\##olution\##Projets\##prototypage\platformIO\Projects\ffp5cs\ffp3"

# Vérifier qu'on est dans le bon répertoire
if (-not (Test-Path $ffp3Path)) {
    Write-Host "✗ Erreur: Répertoire ffp3 introuvable" -ForegroundColor Red
    exit 1
}

cd $ffp3Path

# === Étape 1: Vérifier l'état Git ===
Write-Host "1. Vérification état Git..." -ForegroundColor Yellow
git status --short

Write-Host ""
$continue = Read-Host "Continuer le déploiement ? (o/n)"
if ($continue -ne 'o') {
    Write-Host "Déploiement annulé." -ForegroundColor Yellow
    exit 0
}

Write-Host ""

# === Étape 2: Commit post-data.php (production) ===
Write-Host "2. Commit post-data.php (production)..." -ForegroundColor Yellow

if (Test-Path "public/post-data.php") {
    git add public/post-data.php
    git commit -m "v11.36: Fix GPIO 100 (email) - UPDATE complet outputs"
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host "   ✓ Commit réussi" -ForegroundColor Green
    } else {
        Write-Host "   ! Rien à committer ou erreur" -ForegroundColor Yellow
    }
} else {
    Write-Host "   ✗ Fichier public/post-data.php introuvable" -ForegroundColor Red
}

Write-Host ""

# === Étape 3: Versionner post-data-test.php ===
Write-Host "3. Versionner post-data-test.php..." -ForegroundColor Yellow

if (Test-Path "post-data-test-CORRECTED.php") {
    # Renommer CORRECTED → post-data-test.php
    if (Test-Path "post-data-test.php") {
        Write-Host "   ! post-data-test.php existe déjà, backup..." -ForegroundColor Yellow
        Move-Item -Path "post-data-test.php" -Destination "post-data-test.php.backup-$(Get-Date -Format 'yyyyMMdd')" -Force
    }
    
    Move-Item -Path "post-data-test-CORRECTED.php" -Destination "post-data-test.php"
    Write-Host "   ✓ Renommé CORRECTED → post-data-test.php" -ForegroundColor Green
    
    git add post-data-test.php
    git commit -m "v11.36: Fix post-data-test - Colonnes compatibles ffp3Data2"
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host "   ✓ Commit réussi" -ForegroundColor Green
    } else {
        Write-Host "   ! Rien à committer ou erreur" -ForegroundColor Yellow
    }
} else {
    Write-Host "   ✗ Fichier post-data-test-CORRECTED.php introuvable" -ForegroundColor Red
}

Write-Host ""

# === Étape 4: Push vers origin/main ===
Write-Host "4. Push vers origin/main..." -ForegroundColor Yellow

$push = Read-Host "Pusher les commits vers le serveur Git ? (o/n)"
if ($push -eq 'o') {
    git push origin main
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host "   ✓ Push réussi" -ForegroundColor Green
    } else {
        Write-Host "   ✗ Erreur lors du push" -ForegroundColor Red
        exit 1
    }
} else {
    Write-Host "   ! Push annulé - Les commits sont locaux uniquement" -ForegroundColor Yellow
}

Write-Host ""

# === Étape 5: Récapitulatif ===
Write-Host "=== Récapitulatif ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "Fichiers committés:" -ForegroundColor White
git log --oneline -3

Write-Host ""
Write-Host "=== Prochaines étapes ===" -ForegroundColor Cyan
Write-Host "1. Se connecter au serveur distant" -ForegroundColor White
Write-Host "   ssh user@iot.olution.info" -ForegroundColor Gray
Write-Host ""
Write-Host "2. Mettre à jour le code" -ForegroundColor White
Write-Host "   cd /path/to/ffp3" -ForegroundColor Gray
Write-Host "   git pull origin main" -ForegroundColor Gray
Write-Host ""
Write-Host "3. Tester les endpoints" -ForegroundColor White
Write-Host "   curl http://iot.olution.info/ffp3/post-data" -ForegroundColor Gray
Write-Host "   curl http://iot.olution.info/ffp3/post-data-test" -ForegroundColor Gray
Write-Host ""
Write-Host "4. Monitor ESP32 (90 secondes)" -ForegroundColor White
Write-Host "   cd '$($ffp3Path -replace '\\ffp3$','')'" -ForegroundColor Gray
Write-Host "   pio device monitor --baud 115200" -ForegroundColor Gray
Write-Host ""
Write-Host "✓ Déploiement local terminé!" -ForegroundColor Green

