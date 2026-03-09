<?php
/**
 * Script pour générer les icônes PWA
 * Crée des icônes simples avec le logo FFP3
 * 
 * Usage: php generate-icons.php
 */

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$bgColor = [0, 139, 116]; // #008B74
$textColor = [255, 255, 255];

foreach ($sizes as $size) {
    // Créer une image
    $im = imagecreatetruecolor($size, $size);
    
    // Couleur de fond
    $bg = imagecolorallocate($im, $bgColor[0], $bgColor[1], $bgColor[2]);
    $fg = imagecolorallocate($im, $textColor[0], $textColor[1], $textColor[2]);
    
    // Remplir le fond
    imagefill($im, 0, 0, $bg);
    
    // Ajouter le texte "FFP3"
    $fontSize = $size / 3;
    $text = "FFP3";
    
    // Centrer le texte
    $bbox = imagettfbbox($fontSize, 0, __DIR__ . '/../../../vendor/fonts/Arial.ttf', $text);
    
    // Si Arial n'existe pas, utiliser une police système simple
    if (!$bbox) {
        // Utiliser une police intégrée
        $fontFile = 5; // Police système
        $textWidth = imagefontwidth($fontFile) * strlen($text);
        $textHeight = imagefontheight($fontFile);
        $x = ($size - $textWidth) / 2;
        $y = ($size - $textHeight) / 2;
        imagestring($im, $fontFile, $x, $y, $text, $fg);
    } else {
        $x = ($size - ($bbox[2] - $bbox[0])) / 2;
        $y = ($size - ($bbox[1] - $bbox[7])) / 2 + $fontSize;
        imagettftext($im, $fontSize, 0, $x, $y, $fg, __DIR__ . '/../../../vendor/fonts/Arial.ttf', $text);
    }
    
    // Ajouter un cercle décoratif
    $circleSize = $size * 0.15;
    imagefilledellipse($im, $size / 2, $size * 0.75, $circleSize, $circleSize, $fg);
    
    // Sauvegarder
    $filename = __DIR__ . "/icon-{$size}.png";
    imagepng($im, $filename, 9);
    imagedestroy($im);
    
    echo "✓ Generated icon-{$size}.png\n";
}

echo "\n✅ All icons generated successfully!\n";
echo "Note: For better icons, consider using a professional design tool.\n";

