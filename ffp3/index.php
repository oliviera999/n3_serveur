<?php
/**
 * Point d'entrée racine - Redirige vers public/index.php
 * 
 * Ce fichier permet d'éviter l'affichage de l'arborescence Apache
 * quand on accède à la racine du projet (/ffp3/)
 */

// Redirection vers le front-controller dans public/
require __DIR__ . '/public/index.php';
