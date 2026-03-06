# Test structure table ffp3Data2
# Vérifie si toutes les colonnes nécessaires existent

Write-Host "=== Test Structure ffp3Data2 ===" -ForegroundColor Cyan
Write-Host ""

# Créer script PHP pour vérifier colonnes
$phpScript = @"
<?php
header('Content-Type: text/plain');

`$conn = new mysqli('localhost', 'oliviera_iot', 'Iot#Olution1', 'oliviera_iot');
if (`$conn->connect_error) {
    die("Connection failed: " . `$conn->connect_error);
}

echo "=== Structure ffp3Data2 ===\n\n";

`$result = `$conn->query("DESCRIBE ffp3Data2");
`$columns = [];
while (`$row = `$result->fetch_assoc()) {
    `$columns[] = `$row['Field'];
    echo `$row['Field'] . " (" . `$row['Type'] . ")\n";
}

echo "\n=== Colonnes requises par post-data-test ===\n\n";

`$required = [
    'api_key', 'sensor', 'version', 'TempAir', 'Humidite', 'TempEau',
    'EauPotager', 'EauAquarium', 'EauReserve', 'diffMaree', 'Luminosite',
    'etatPompeAqua', 'etatPompeTank', 'etatHeat', 'etatUV',
    'bouffeMatin', 'bouffeMidi', 'bouffeSoir', 'bouffePetits', 'bouffeGros',
    'tempsGros', 'tempsPetits', 'aqThreshold', 'tankThreshold', 'chauffageThreshold'
];

`$missing = [];
foreach (`$required as `$col) {
    if (in_array(`$col, `$columns)) {
        echo "✓ `$col\n";
    } else {
        echo "✗ `$col MANQUANT\n";
        `$missing[] = `$col;
    }
}

if (count(`$missing) > 0) {
    echo "\n=== COLONNES MANQUANTES ===\n";
    foreach (`$missing as `$col) {
        echo "ALTER TABLE ffp3Data2 ADD COLUMN `$col VARCHAR(50);\n";
    }
}

`$conn->close();
?>
"@

# Sauvegarder temporairement
$phpScript | Out-File -FilePath "ffp3/public/check-structure.php" -Encoding UTF8 -NoNewline

Write-Host "Script PHP créé: ffp3/public/check-structure.php" -ForegroundColor Green
Write-Host ""
Write-Host "Pour tester sur serveur, exécute:" -ForegroundColor Yellow
Write-Host "  curl.exe http://iot.olution.info/ffp3/public/check-structure.php" -ForegroundColor Cyan
Write-Host ""
Write-Host "Ou uploade le fichier sur le serveur avec:" -ForegroundColor Yellow
Write-Host "  scp ffp3/public/check-structure.php user@iot.olution.info:/path/to/ffp3/public/" -ForegroundColor Cyan

