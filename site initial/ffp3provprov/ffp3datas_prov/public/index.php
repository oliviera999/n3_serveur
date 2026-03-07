<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Env;
use App\Controller\DashboardController;
use App\Controller\ExportController;
use App\Controller\PostDataController;
use App\Controller\AquaponieController;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;

// Charge les variables d'environnement (.env)
Env::load();

// -----------------------------------------------------------------------------
// Création de l'application Slim (pas de container pour l'instant)
// -----------------------------------------------------------------------------
$app = AppFactory::create();

// Forcer le chemin base pour être identique à l'ancien (dossier parent de /public)
$basePath = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'])));
if ($basePath !== '/' && $basePath !== '') {
    $app->setBasePath($basePath);
}

// -----------------------------------------------------------------------------
// Définition des routes principales
// -----------------------------------------------------------------------------
$app->get('/', function (Request $request, Response $response) {
    (new DashboardController())->show();
    return $response;
});
$app->get('/dashboard', function (Request $request, Response $response) {
    (new DashboardController())->show();
    return $response;
});
$app->get('/aquaponie', function (Request $request, Response $response) {
    (new AquaponieController())->show();
    return $response;
});
// Permettre également le POST (formulaire filtre + export CSV)
$app->map(['POST'], '/aquaponie', function (Request $request, Response $response) {
    (new AquaponieController())->show();
    return $response;
});
$app->get('/ffp3-data', function (Request $request, Response $response) {
    // Alias legacy
    (new AquaponieController())->show();
    return $response;
});
// Accept POST on alias as well
$app->map(['POST'], '/ffp3-data', function (Request $request, Response $response) {
    (new AquaponieController())->show();
    return $response;
});
$app->post('/post-data', function (Request $request, Response $response) {
    (new PostDataController())->handle();
    return $response;
});
$app->post('/post-ffp3-data.php', function (Request $request, Response $response) {
    // Alias legacy (ESP32)
    (new PostDataController())->handle();
    return $response;
});
$app->get('/export-data', function (Request $request, Response $response) {
    (new ExportController())->downloadCsv();
    return $response;
});
$app->get('/export-data.php', function (Request $request, Response $response) {
    // Alias legacy
    (new ExportController())->downloadCsv();
    return $response;
});

// Page statistiques marée (GET + POST pour formulaire)
$app->map(['GET', 'POST'], '/tide-stats', function (Request $request, Response $response) {
    (new \App\Controller\TideStatsController())->show();
    return $response;
});

// -----------------------------------------------------------------------------
// Fallback 404 pour toute route non définie (Slim le gère mais on peut personnaliser)
// -----------------------------------------------------------------------------
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->run(); 