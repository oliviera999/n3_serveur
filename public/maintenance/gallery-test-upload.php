<?php
/**
 * Test d'upload galerie — envoie un JPEG de test à /msp1gallery/upload.php via HTTP interne.
 * À supprimer après vérification.
 */
header('Content-Type: text/plain; charset=utf-8');

echo "=== Test upload galerie ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

$root = dirname(__DIR__, 2);

// Créer un JPEG 1x1 pixel minimal valide
$im = imagecreatetruecolor(2, 2);
$red = imagecolorallocate($im, 255, 0, 0);
imagefill($im, 0, 0, $red);
ob_start();
imagejpeg($im, null, 90);
$jpegData = ob_get_clean();
imagedestroy($im);
echo "JPEG de test: " . strlen($jpegData) . " octets\n";

// Écrire le JPEG dans un fichier temporaire
$tmpFile = tempnam(sys_get_temp_dir(), 'gallery_test_') . '.jpg';
file_put_contents($tmpFile, $jpegData);
echo "Fichier temp: $tmpFile\n\n";

// Simuler un upload via move_uploaded_file n'est pas possible, mais on peut
// tester l'écriture directe dans le dossier uploads/msp1
$targetDir = $root . '/uploads/msp1';
$testFilename = 'TEST_' . date('Y-m-d_H-i-s') . '_00000000.jpg';
$targetPath = $targetDir . '/' . $testFilename;

echo "--- Test 1: écriture directe dans $targetDir ---\n";
if (!is_dir($targetDir)) {
    echo "ERREUR: dossier $targetDir n'existe pas\n";
} elseif (!is_writable($targetDir)) {
    echo "ERREUR: dossier $targetDir non writable\n";
} else {
    $written = file_put_contents($targetPath, $jpegData);
    if ($written === false) {
        echo "ERREUR: file_put_contents a échoué\n";
    } else {
        echo "OK: $testFilename écrit ($written octets)\n";
        // Vérifier que le fichier est lisible
        if (is_file($targetPath) && is_readable($targetPath)) {
            echo "OK: fichier lisible\n";
        } else {
            echo "ERREUR: fichier non lisible\n";
        }
    }
}

// Test 2: appel HTTP interne à la route d'upload
echo "\n--- Test 2: upload HTTP vers /msp1gallery/upload.php ---\n";
$serverHost = $_SERVER['HTTP_HOST'] ?? 'iot.olution.info';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$uploadUrl = $protocol . '://' . $serverHost . '/msp1gallery/upload.php';
echo "URL: $uploadUrl\n";

$boundary = '----WebKitFormBoundary' . bin2hex(random_bytes(8));
$body = "--$boundary\r\n";
$body .= "Content-Disposition: form-data; name=\"imageFile\"; filename=\"esp32-cam.jpg\"\r\n";
$body .= "Content-Type: image/jpeg\r\n\r\n";
$body .= $jpegData . "\r\n";
$body .= "--$boundary--\r\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $uploadUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'X-Api-Key: test',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Status: $httpCode\n";
echo "Response: $result\n";
if ($curlError) {
    echo "cURL Error: $curlError\n";
}

// Lister le contenu de uploads/msp1 après le test
echo "\n--- Contenu de uploads/msp1 après test ---\n";
$files = array_diff(scandir($targetDir), ['.', '..', '.gitkeep']);
foreach ($files as $f) {
    echo "  $f (" . filesize($targetDir . '/' . $f) . " octets)\n";
}
echo "Total: " . count($files) . " fichiers\n";

@unlink($tmpFile);
echo "\n=== Fin test upload ===\n";
