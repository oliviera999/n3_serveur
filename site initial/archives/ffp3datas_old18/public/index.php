<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Env;
use App\Controller\DashboardController;
use App\Controller\ExportController;
use App\Controller\PostDataController;
use App\Controller\AquaponieController;

// Charge les variables d'environnement (.env)
Env::load();

// Récupère l'URI demandée (sans query) et la normalise en retirant le prefixe de base
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Déduction automatique du chemin racine de l'application (dossier parent de /public)
$basePath = dirname(dirname($_SERVER['SCRIPT_NAME'])); // ex: /ffp3/ffp3datas
if ($basePath !== '/' && str_starts_with($uri, $basePath)) {
    $uri = substr($uri, strlen($basePath));
}
$uri = '/' . ltrim($uri, '/');

switch ($uri) {
    case '/':
    case '/dashboard':
        (new DashboardController())->show();
        break;

    // On laisse les anciennes routes fonctionner le temps de la migration
    case '/export-data':
        (new ExportController())->downloadCsv();
        break;
    case '/post-data':
        (new PostDataController())->handle();
        break;
    case '/ffp3-data':
    case '/aquaponie':
        (new AquaponieController())->show();
        break;

    default:
        http_response_code(404);
        echo 'Page non trouvée';
        break;
}

exit; 