<?php
// Chemin vers le dossier contenant vos photos
$photosDirectory = 'ffp3photos/';

// Chemin vers les dossiers de sortie
$dossierPhotosSombres = 'photostodel/';
$dossierPhotosClaires = 'photostodel/';
$dossierPhotosMoyennes = 'ffp3photos/';

// Ouvrir le répertoire des photos
$dir = opendir($photosDirectory);
echo "Test1 <br>";
// Parcourir les fichiers dans le dossier
while (($file = readdir($dir)) !== false) {
    echo "Test2 <br>";

    if ($file !== '.' && $file !== '..') {
        // Chemin complet du fichier
        $filePath = $photosDirectory . $file;
echo "Test3<br>";

        // Lire la luminosité moyenne de la photo
        $luminosite = calculerLuminositeMoyenne($filePath);
echo "Test4<br>";

        // Choisir le dossier de destination en fonction de la luminosité
        if ($luminosite < 25) {
            $destination = $dossierPhotosSombres;
echo "Test5<br>";

        } elseif ($luminosite > 275) {
            $destination = $dossierPhotosClaires;
echo "Test6<br>";

        } else {
            $destination = $dossierPhotosMoyennes;
echo "Test7<br>";

        }

        // Déplacer la photo dans le dossier approprié
        rename($filePath, $destination . $file);
echo "Test8<br>";

    }
}

closedir($dir);

// Fonction pour calculer la luminosité moyenne d'une photo
function calculerLuminositeMoyenne($filePath) {
    $image = imagecreatefromjpeg($filePath);
    $width = imagesx($image);
    $height = imagesy($image);
    $totalBrightness = 0;
echo "Test9<br>" ;
    echo ("".$image."");


    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $totalBrightness += ($r + $g + $b) / 3;
    echo ("état de la pompe de l'aquarium : ".$image. '<br />');

        }
    }

    $luminosity = $totalBrightness / ($width * $height);
echo "Test11<br>";

    return $luminosity;
echo "Test12<br>";

}
?>