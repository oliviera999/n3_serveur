<?php
/**
 * Diagnostic temporaire des galeries photo.
 * À supprimer après investigation.
 */
header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__, 2);
echo "=== Diagnostic galeries photo ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Racine projet: $root\n";
echo "PHP user: " . get_current_user() . " / " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'N/A') . "\n\n";

// Vérifier .env
$envFile = $root . '/.env';
echo "--- Variables .env galerie ---\n";
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    foreach (['GALLERY_MSP1_DIR', 'GALLERY_N3PP_DIR', 'GALLERY_FFP3_DIR', 'UPLOADS_BASE_DIR'] as $key) {
        if (preg_match('/^' . preg_quote($key) . '=(.*)$/m', $envContent, $m)) {
            echo "$key = " . trim($m[1]) . "\n";
        } else {
            echo "$key = (non défini)\n";
        }
    }
} else {
    echo ".env non trouvé\n";
}

echo "\n--- Dossiers uploads/ ---\n";
$uploadDirs = ['uploads/msp1', 'uploads/n3pp', 'uploads/ffp3'];
foreach ($uploadDirs as $dir) {
    $full = $root . '/' . $dir;
    if (is_dir($full)) {
        $files = array_diff(scandir($full), ['.', '..', '.gitkeep']);
        $jpgs = array_filter($files, fn($f) => preg_match('/\.(jpg|jpeg)$/i', $f));
        echo "$dir : " . count($files) . " fichiers (" . count($jpgs) . " JPG)\n";
        if (count($jpgs) > 0) {
            $sorted = array_values($jpgs);
            rsort($sorted);
            echo "  Derniers: " . implode(', ', array_slice($sorted, 0, 3)) . "\n";
        }
    } else {
        echo "$dir : ABSENT\n";
    }
}

echo "\n--- Anciens dossiers galerie (legacy) ---\n";
$legacyDirs = [
    'msp1gallery', 'n3ppgallery', 'ffp3gallery',
    'ffp3/ffp3gallery', 'msp1/msp1gallery', 'n3pp/n3ppgallery',
    'public/msp1gallery', 'public/n3ppgallery', 'public/ffp3gallery',
];
foreach ($legacyDirs as $dir) {
    $full = $root . '/' . $dir;
    if (is_dir($full)) {
        $files = array_diff(scandir($full), ['.', '..', '.gitkeep']);
        $jpgs = array_filter($files, fn($f) => preg_match('/\.(jpg|jpeg)$/i', $f));
        echo "$dir : " . count($files) . " fichiers (" . count($jpgs) . " JPG) [EXISTE]\n";
        if (count($jpgs) > 0) {
            $sorted = array_values($jpgs);
            rsort($sorted);
            echo "  Derniers: " . implode(', ', array_slice($sorted, 0, 3)) . "\n";
            $oldest = array_values($jpgs);
            sort($oldest);
            echo "  Premiers: " . implode(', ', array_slice($oldest, 0, 3)) . "\n";
        }
    } else {
        echo "$dir : absent\n";
    }
}

echo "\n--- Recherche globale de dossiers contenant des JPG ---\n";
$searchDirs = glob($root . '/*', GLOB_ONLYDIR);
foreach ($searchDirs as $d) {
    $name = basename($d);
    if (in_array($name, ['vendor', '.git', 'var', 'node_modules', 'archives'])) continue;
    $jpgs = glob($d . '/*.{jpg,jpeg}', GLOB_BRACE);
    if (count($jpgs) > 0) {
        echo "$name/ : " . count($jpgs) . " JPG\n";
        $names = array_map('basename', $jpgs);
        rsort($names);
        echo "  Derniers: " . implode(', ', array_slice($names, 0, 3)) . "\n";
    }
    // Sous-dossiers aussi (1 niveau)
    $subDirs = glob($d . '/*', GLOB_ONLYDIR);
    foreach ($subDirs as $sd) {
        $sname = basename($d) . '/' . basename($sd);
        $jpgs = glob($sd . '/*.{jpg,jpeg}', GLOB_BRACE);
        if (count($jpgs) > 0) {
            echo "$sname/ : " . count($jpgs) . " JPG\n";
            $names = array_map('basename', $jpgs);
            rsort($names);
            echo "  Derniers: " . implode(', ', array_slice($names, 0, 3)) . "\n";
        }
    }
}

echo "\n--- Permissions uploads/ ---\n";
$uploadsDir = $root . '/uploads';
if (is_dir($uploadsDir)) {
    echo "uploads/ : " . decoct(fileperms($uploadsDir) & 0777) . " writable=" . (is_writable($uploadsDir) ? 'oui' : 'NON') . "\n";
    foreach (['msp1', 'n3pp', 'ffp3'] as $sub) {
        $d = "$uploadsDir/$sub";
        if (is_dir($d)) {
            echo "uploads/$sub/ : " . decoct(fileperms($d) & 0777) . " writable=" . (is_writable($d) ? 'oui' : 'NON') . "\n";
        }
    }
} else {
    echo "uploads/ : ABSENT\n";
}

echo "\n=== Fin diagnostic ===\n";
