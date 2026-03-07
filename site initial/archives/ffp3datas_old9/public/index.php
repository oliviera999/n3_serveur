<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Controller\DashboardController;

// Routage minimal : si l'URL contient un chemin spécifique on pourrait l'analyser ici.
// Pour l'instant, on affiche directement le tableau de bord.

$controller = new DashboardController();
$controller->show(); 