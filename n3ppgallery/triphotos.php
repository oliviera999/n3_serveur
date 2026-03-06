<?php
// Chemin vers le dossier contenant vos photos
$photosDirectory = 'n3ppphotos/';

// Chemin vers les dossiers de sortie
$dossierPhotosSombres = 'photostodel/';
$dossierPhotosClaires = 'photostodel/';
$dossierPhotosMoyennes = 'n3ppphotos/';
$dossierPhotosRedressees = 'n3ppphotos/';


// Ouvrir le répertoire des photos
$dir = opendir($photosDirectory);
echo "Test1";
// Parcourir les fichiers dans le dossier
while (($file = readdir($dir)) !== false) {
    echo "Test2";

    if ($file !== '.' && $file !== '..') {
        // Chemin complet du fichier
        $filePath = $photosDirectory . $file;
echo "Test3";

        // Vérifier si la photo est en mode paysage
        list($largeur, $hauteur) = getimagesize($filePath);
        $modePaysage = $largeur > $hauteur;
echo "Test4";


        // Si la photo est en mode paysage, la redresser
        if ($modePaysage) {
            redresserPhoto($filePath);
            $destination = $dossierPhotosRedressees;
        // Déplacer la photo dans le dossier approprié
        rename($filePath, $destination . $file);
echo "Test8";
        }

/*
echo "Test44";

        // Lire la luminosité moyenne de la photo
        $luminosite = calculerLuminositeMoyenne($filePath);
echo "Test4";

        // Choisir le dossier de destination en fonction de la luminosité
        if ($luminosite < 35) {
            $destination = $dossierPhotosSombres;
echo "Test5";

        } elseif ($luminosite > 260) {
            $destination = $dossierPhotosClaires;
echo "Test6";

        } else {
            $destination = $dossierPhotosMoyennes;
echo "Test7";
        }

*/
    }
}

closedir($dir);

// Fonction pour calculer la luminosité moyenne d'une photo
function calculerLuminositeMoyenne($filePath) {
    $image = imagecreatefromjpeg($filePath);
    $width = imagesx($image);
    $height = imagesy($image);
    $totalBrightness = 0;
echo "Test9";

    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $totalBrightness += ($r + $g + $b) / 3;
echo "Test10";

        }
    }

    $luminosity = $totalBrightness / ($width * $height);
echo "Test11";

    return $luminosity;
echo "Test12";

}

// Fonction pour redresser une photo en mode portrait
function redresserPhoto($filePath) {
    $image = imagecreatefromjpeg($filePath);
    $image = imagerotate($image, -90, 0);
    imagejpeg($image, $filePath);
}

?>