<?php
/**
 * Script de test simple pour vérifier /post-data-test
 * 
 * Usage: php test_simple.php
 */

echo "========================================\n";
echo "TEST SIMPLE POST-DATA-TEST\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// Configuration
$url = "http://localhost/ffp3/post-data-test";
$apiKey = "fdGTMoptd5CD2ert3";

// Données de test minimales
$data = [
    'api_key' => $apiKey,
    'sensor' => 'esp32-wroom',
    'version' => '11.35',
    'TempAir' => '28.0',
    'Humidite' => '61.0',
    'TempEau' => '28.0',
    'EauPotager' => '209',
    'EauAquarium' => '210',
    'EauReserve' => '209',
    'diffMaree' => '-2',
    'Luminosite' => '228',
    'etatPompeAqua' => '1',
    'etatPompeTank' => '0',
    'etatHeat' => '0',
    'etatUV' => '0',
    'resetMode' => '0'
];

echo "URL: $url\n";
echo "Données: " . http_build_query($data) . "\n\n";

// Initialiser cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'User-Agent: ESP32-Test/1.0'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_VERBOSE, true);

// Exécuter la requête
echo "Envoi de la requête...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

// Afficher les résultats
echo "\n========================================\n";
echo "RÉSULTATS\n";
echo "========================================\n";
echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";

if ($error) {
    echo "cURL Error: $error\n";
}

if ($httpCode === 200) {
    echo "✅ SUCCÈS - Requête réussie\n";
} else {
    echo "❌ ÉCHEC - Code HTTP: $httpCode\n";
}

echo "\n========================================\n";
echo "TEST TERMINÉ\n";
echo "========================================\n";
