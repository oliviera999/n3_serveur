# Script PowerShell pour déploiement sur serveur distant
# À exécuter depuis votre machine locale

Write-Host "🚀 Déploiement du projet FFP3 sur le serveur de production..." -ForegroundColor Green

# Configuration SSH
$server = "iot.olution.info"
$user = "oliviera"
$remotePath = "/home4/oliviera/iot.olution.info/ffp3/"

# Commandes à exécuter sur le serveur distant
$commands = @(
    "cd $remotePath",
    "git fetch --all",
    "git reset --hard origin/main",
    "composer install --no-dev --optimize-autoloader",
    "chmod -R 755 public/",
    "chmod -R 644 config/",
    "chmod -R 644 src/",
    "rm -rf var/cache/*"
)

Write-Host "📡 Connexion au serveur $server..." -ForegroundColor Yellow

# Exécuter les commandes via SSH
$commandString = $commands -join " && "
ssh $user@$server $commandString

if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Déploiement terminé avec succès !" -ForegroundColor Green
    Write-Host "🌐 Votre site est maintenant accessible sur https://iot.olution.info/ffp3/" -ForegroundColor Cyan
} else {
    Write-Host "❌ Erreur lors du déploiement" -ForegroundColor Red
}
