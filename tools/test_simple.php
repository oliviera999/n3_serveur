<?php

declare(strict_types=1);

/**
 * Script de test simple pour vérifier /post-data-test
 *
 * Lit API_KEY depuis l'environnement (.env via App\Config\Env::load() si disponible,
 * sinon variable d'environnement classique). Aucun secret ne doit jamais figurer
 * en clair dans ce script (cf. .cursor/rules/securite-et-secrets.mdc).
 *
 * Usage:
 *   php test_simple.php                      # url par defaut + API_KEY env
 *   API_KEY=xxxx php test_simple.php         # override cle
 *   POST_URL=http://... php test_simple.php  # override URL
 */

echo "========================================\n";
echo "TEST SIMPLE POST-DATA-TEST\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// Chargement .env si disponible (autoload Composer presents)
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (class_exists(\App\Config\Env::class)) {
        \App\Config\Env::load();
    }
}

// Configuration (URL + cle API lues depuis l'environnement, jamais en dur)
$url = getenv('POST_URL') ?: ($_ENV['POST_URL'] ?? 'http://localhost/post-data-test');
$apiKey = getenv('API_KEY') ?: ($_ENV['API_KEY'] ?? '');
if ($apiKey === '') {
    fwrite(STDERR, "❌ API_KEY introuvable (variable d'env ou .env requis).\n");
    fwrite(STDERR, "   Exemple : \$env:API_KEY = '...'; php tools/test_simple.php\n");
    exit(2);
}

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
