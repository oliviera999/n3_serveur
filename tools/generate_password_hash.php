<?php
/**
 * Script helper pour générer un hash de mot de passe pour l'authentification.
 * 
 * Usage: php generate_password_hash.php [mot_de_passe]
 * Si aucun mot de passe n'est fourni, le script demande une saisie sécurisée.
 */

if (php_sapi_name() !== 'cli') {
    die("Ce script doit être exécuté en ligne de commande.\n");
}

$password = $argv[1] ?? null;

if ($password === null) {
    // Demander le mot de passe de manière sécurisée (masqué)
    echo "Entrez le mot de passe à hasher: ";
    
    // Sur Windows, utiliser une méthode alternative
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $password = trim(fgets(STDIN));
    } else {
        // Sur Unix/Linux, masquer la saisie
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    }
}

if (empty($password)) {
    echo "Erreur: Le mot de passe ne peut pas être vide.\n";
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "\n";
echo "========================================\n";
echo "HASH DE MOT DE PASSE GÉNÉRÉ\n";
echo "========================================\n";
echo "\n";
echo "Ajoutez cette ligne dans votre fichier .env :\n";
echo "\n";
echo "ADMIN_PASSWORD_HASH=$hash\n";
echo "\n";
echo "========================================\n";
echo "\n";
