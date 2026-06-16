<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Env;
use App\Controller\AuthController;
use App\Controller\Ffp3\AquaponieController;
use App\Controller\Ffp3\AquaponieDescriptionController;
use App\Controller\HomeController;
use App\Controller\LocalDataPagesController;
use App\Controller\Msp\MspDataController;
use App\Controller\Msp\MspDescriptionController;
use App\Controller\Msp\MspOutputController;
use App\Controller\N3pp\N3ppDataController;
use App\Controller\N3pp\N3ppDescriptionController;
use App\Controller\N3pp\N3ppOutputController;
use App\Controller\Pgl\PglHeartbeatController;
use App\Controller\Pgl\PglPostDataController;
use App\Controller\Pgl\PglRealtimeApiController;
use App\Controller\Pgl\PglStatsController;
use App\Middleware\AuthGuardMiddleware;
use App\Middleware\EnvironmentMiddleware;
use App\Middleware\RawPostBodyMiddleware;
use App\Middleware\RequestEnvironmentMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;

// Charge les variables d'environnement (.env)
Env::load();

// ====================================================================
// Initialisation du container DI
// ====================================================================
$container = require __DIR__ . '/../config/container.php';
AppFactory::setContainer($container);

// ====================================================================
// Création de l'application Slim
// ====================================================================
$app = AppFactory::create();
$useLocalDataFallback = PHP_SAPI === 'cli-server';

// Forcer le chemin base pour être identique à l'ancien (dossier parent de /public)
// Détection du basePath selon le point d'entrée utilisé
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);

if (PHP_SAPI === 'cli-server') {
    // Le serveur intégré PHP renseigne SCRIPT_NAME avec la route demandée.
    // En local, l'application est servie à la racine sans préfixe.
    $basePath = '';
} elseif (strpos($scriptName, '/public/index.php') !== false) {
    // Accès via public/index.php : remonter de 2 niveaux depuis /public/
    // Ex: /ffp3/public/index.php -> /ffp3
    $basePath = dirname(dirname($scriptName));
} else {
    // Accès via index.php racine : utiliser le répertoire de SCRIPT_NAME
    // Ex: /ffp3/index.php -> /ffp3
    $basePath = dirname($scriptName);
}

// Normaliser le basePath (enlever les points et slashes multiples)
$basePath = rtrim($basePath, '/');
// Ne pas définir de basePath si c'est la racine du serveur
if ($basePath !== '' && $basePath !== '/') {
    $app->setBasePath($basePath);
}
// Base path pour les templates (assets, liens). Utilisé par TemplateRenderer.
$GLOBALS['base_path'] = $basePath;

// Helpers de routes (registerRedirects, etc.) — requis avant les redirections 301
require __DIR__ . '/../config/routes_helpers.php';

$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Chargement config routes avec fallback si fichier absent (évite 500 sur prod si config/ non déployé)
$routesConfigPath = __DIR__ . '/../config/routes_config.php';
$loadRoutesConfig = static function () use ($routesConfigPath): array {
    if (is_file($routesConfigPath)) {
        return require $routesConfigPath;
    }
    error_log('[iot.olution.info] routes_config.php absent: ' . $routesConfigPath);
    return [
        'exact_public_paths' => ['/', '/login', '/logout', '/ping', '/favicon.ico'],
        'public_paths' => ['/api/', '/post-data', '/heartbeat', '/assets/', '/aquaponie', '/meteo', '/serre', '/gallery/', '/ota/', '/ffp3/ota/'],
        'protected_paths' => ['/dashboard', '/supervision', '/export-data', '/admin/'],
        'asset_js' => [],
        'asset_css' => [],
        'asset_icons' => [],
        'redirects_301' => [],
    ];
};

// ====================================================================
// Middleware de gestion d'erreurs personnalisé
// ====================================================================
$app->add($container->get(\App\Middleware\ErrorHandlerMiddleware::class));

// ====================================================================
// Middleware headers de sécurité (X-Content-Type-Options, X-Frame-Options, etc.)
// ====================================================================
$app->add(new SecurityHeadersMiddleware());

// ====================================================================
// Protection CSRF des écritures navigateur du panneau de contrôle
// (toggle/parameters/trigger-ota). Gaté par liste positive de chemins :
// n'impacte JAMAIS les endpoints ESP32 (post-data, heartbeat, /state).
// ====================================================================
$app->add($container->get(\App\Middleware\CsrfMiddleware::class));

// Réinitialise l'environnement BDD au défaut .env en début de requête (évite fuites inter-requêtes)
$app->add(new RequestEnvironmentMiddleware());

// ====================================================================
// Redirections 301 : anciens liens iot.olution.info/ffp3/* vers les nouvelles pages /*
// (Fonctionne même si .htaccess n'est pas appliqué, ex. nginx)
// ====================================================================
$app->add(function (Request $request, $handler) {
    $path = $request->getUri()->getPath();
    $method = $request->getMethod();
    // Redirection GET uniquement (pages), pas les POST API (ex. ffp3gallery/upload.php)
    if ($method !== 'GET') {
        return $handler->handle($request);
    }
    // /ffp3 ou /ffp3/ -> /
    if ($path === '/ffp3' || $path === '/ffp3/') {
        $location = '/';
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', $location)->withStatus(301);
    }
    // /ffp3/xxx -> /xxx (sauf /ffp3/ffp3gallery/, /ffp3/api/outputs*, /ffp3/ota/ pour OTA firmware ESP32)
    $isFfp3ApiOutputs = (strpos($path, '/ffp3/api/outputs') === 0);
    $isFfp3Ota = (strpos($path, '/ffp3/ota/') === 0);
    if (strpos($path, '/ffp3/') === 0 && strpos($path, '/ffp3/ffp3gallery/') !== 0 && !$isFfp3ApiOutputs && !$isFfp3Ota) {
        $target = '/' . substr($path, 6); // enlever '/ffp3/'
        $query = $request->getUri()->getQuery();
        $location = $target . ($query !== '' ? '?' . $query : '');
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', $location)->withStatus(301);
    }
    return $handler->handle($request);
});

// ====================================================================
// Routes d'authentification (publiques - pas d'auth requise)
// ====================================================================
$app->get('/login', [AuthController::class, 'showLogin']);
// Anti-brute-force par IP en complément du limiteur par session du contrôleur.
$app->post('/login', [AuthController::class, 'handleLogin'])
    ->add($container->get(\App\Middleware\RateLimitMiddleware::class));
$app->get('/logout', [AuthController::class, 'handleLogout']);

// Déterminer la méthode d'authentification à utiliser
Env::load();
$authMethod = $_ENV['AUTH_METHOD'] ?? 'session'; // 'session', 'token', ou 'both'
$authGuard = $container->get(AuthGuardMiddleware::class);

// Fonction helper pour appliquer l'authentification selon la méthode configurée
$applyAuth = function ($request, $handler) use ($authGuard, $authMethod) {
    return $authGuard->applyConfiguredAuth($request, $handler, $authMethod);
};

// ====================================================================
// Middleware global pour protéger les routes protégées avant le routage
// ====================================================================
// Ce middleware intercepte les requêtes vers les chemins protégés même si la route n'est pas trouvée
$app->add(function (Request $request, $handler) use ($authGuard, $authMethod, $loadRoutesConfig) {
    $routesConfig = $loadRoutesConfig();
    return $authGuard->processProtectedPaths($request, $handler, $authMethod, $routesConfig);
});

// ====================================================================
// Routes PUBLIQUES (pas d'authentification requise)
// ====================================================================

// Page d'accueil - PUBLIQUE
$app->get('/', [HomeController::class, 'show']);
$app->get('/index.html', function (Request $request, Response $response) use ($app) {
    $base = $app->getBasePath() ?: '';
    return $response->withHeader('Location', $base . '/')->withStatus(301);
});

// Redirections 301 : anciennes URL vers nouveau schéma de nommage
$basePath = $app->getBasePath() ?: '';
$routesConfigFull = $loadRoutesConfig();
registerRedirects($app, $routesConfigFull['redirects_301'], $basePath);

// Pages aquaponie - PUBLIQUES (avec middleware d'environnement mais sans authentification)
// Inversion 2026-03 : /aquaponie = vue paysage (main), /aquaponie-alt = vue classique (alt)
// PRODUCTION
$app->group('', function ($group) use ($useLocalDataFallback) {
    $group->map(['GET', 'POST'], '/aquaponie', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponie' : 'showAlt']);
    $group->map(['GET', 'POST'], '/aquaponie-alt', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponieClassic' : 'show']);
})->add(new EnvironmentMiddleware('prod'));

// TEST
$app->group('', function ($group) use ($useLocalDataFallback) {
    $group->map(['GET', 'POST'], '/aquaponie-test', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponie' : 'showAlt']);
    $group->map(['GET', 'POST'], '/aquaponie-alt-test', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponieClassic' : 'show']);
})->add(new EnvironmentMiddleware('test'));

// TEST3
$app->group('', function ($group) use ($useLocalDataFallback) {
    $group->map(['GET', 'POST'], '/aquamobile-test', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponie' : 'showAlt']);
    $group->map(['GET', 'POST'], '/aquamobile-alt-test', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponieClassic' : 'show']);
})->add(new EnvironmentMiddleware('test3'));

// S3 PROD
$app->group('', function ($group) use ($useLocalDataFallback) {
    $group->map(['GET', 'POST'], '/aquamobile', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponie' : 'showAlt']);
    $group->map(['GET', 'POST'], '/aquamobile-alt', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponieClassic' : 'show']);
})->add(new EnvironmentMiddleware('s3'));

// S3 TEST (tables ffp3DataS3Test, ffp3OutputsS3Test)
$app->group('', function ($group) use ($useLocalDataFallback) {
    $group->map(['GET', 'POST'], '/aquamobile-s3-test', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponie' : 'showAlt']);
    $group->map(['GET', 'POST'], '/aquamobile-alt-s3-test', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponieClassic' : 'show']);
})->add(new EnvironmentMiddleware('s3test'));

// Page Caractéristiques du module FFP3 - PUBLIQUE (pas de variante env)
$app->get('/aquaponie-description', [AquaponieDescriptionController::class, 'show']);

// Pages Caractéristiques des modules MSP1 et N3PP - PUBLIQUES
$app->get('/meteo-description', [MspDescriptionController::class, 'show']);
$app->get('/serre-description', [N3ppDescriptionController::class, 'show']);

// Handler OTA : sert les fichiers depuis serveur/ota/ (streaming, sans charger tout en mémoire).
// Auth opt-in via OTA_REQUIRE_AUTH=true (.env) : exige X-Api-Key / X-OTA-Key / ?api_key= sinon 401.
// Par défaut (compat firmwares déployés) : lecture publique des binaires ; l'intégrité est
// validée côté firmware par MD5 (cf. firmwires/ffp5cs/include/ota_config.h).
// Supporte HTTP Range (206) pour la reprise de download ; téléchargement complet (200) inchangé.
// Logique extraite et testée dans App\Controller\Ffp3\OtaFileController (OtaFileControllerTest).
$otaHandler = new \App\Controller\Ffp3\OtaFileController(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ota');

// Fichiers OTA (n3pp, msp, cam, ffp3) — servis depuis serveur/ota/
$app->get('/ota/{path:.+}', $otaHandler);
// /ffp3/ota/* : alias legacy (firmware ffp5cs >= 13.12 utilise /ota/)
$app->get('/ffp3/ota/{path:.+}', $otaHandler);

// Ping / diagnostic latence - PUBLIC (GET et POST, réponse minimale, pas de BDD)
$app->map(['GET', 'POST'], '/ping', function (Request $request, Response $response): Response {
    $body = 'OK';
    $response->getBody()->write($body);
    return $response
        ->withHeader('Content-Type', 'text/plain; charset=utf-8')
        ->withHeader('Content-Length', (string) strlen($body))
        ->withHeader('Connection', 'close')
        ->withStatus(200);
});

// Glossaire — définitions des termes techniques (public collège)
$app->get('/glossaire', [\App\Controller\GlossaireController::class, 'show']);

// Poissonglouton (compteur recyclage plastique)
$app->get('/pgl', [PglStatsController::class, 'show']);
$app->get('/pgl/api/realtime/sensors/latest', [PglRealtimeApiController::class, 'getLatestSensors']);
$app->get('/pgl/api/realtime/sensors/since/{timestamp}', [PglRealtimeApiController::class, 'getSensorsSince']);
$app->get('/pgl/api/realtime/system/health', [PglRealtimeApiController::class, 'getSystemHealth']);
$app->get('/pgl/api/system/health', [PglRealtimeApiController::class, 'getSystemHealth']);
$app->post('/pgl/post-data', [PglPostDataController::class, 'handle']);
$app->post('/pgl/heartbeat', [PglHeartbeatController::class, 'handle']);

// ====================================================================
// Routes FFP3 (aquaponie) — config/routes_ffp3.php
// ====================================================================
require __DIR__ . '/../config/routes_ffp3.php';

// ====================================================================
// Fichiers statiques GLOBAUX (config centralisée)
// ====================================================================
registerAssetRoute($app, '/assets/js/{filename}', $routesConfigFull['asset_js'], 'application/javascript', 'js');
registerAssetRoute($app, '/assets/css/{filename}', $routesConfigFull['asset_css'], 'text/css', 'css');
registerAssetRoute($app, '/assets/icons/{filename}', $routesConfigFull['asset_icons'], 'image/png', 'icons');
registerAssetRoute($app, '/assets/webfonts/{filename}', $routesConfigFull['asset_webfonts'], 'font/woff2', 'webfonts');

$app->get('/assets/logo.png', function (Request $request, Response $response) {
    $filePath = __DIR__ . '/assets/logo.png';
    if (file_exists($filePath)) {
        $response->getBody()->write(file_get_contents($filePath));
        return $response->withHeader('Content-Type', 'image/png');
    }
    return $response->withStatus(404);
});

$app->get('/assets/bg-aquaponie.png', function (Request $request, Response $response) {
    $filePath = __DIR__ . '/assets/bg-aquaponie.png';
    if (file_exists($filePath)) {
        $response->getBody()->write(file_get_contents($filePath));
        return $response->withHeader('Content-Type', 'image/png');
    }
    return $response->withStatus(404);
});

$app->get('/assets/images/aquaponie-description/{filename}', function (Request $request, Response $response, array $args) {
    $allowed = ['introduction.jpg', 'vue-generale.jpg', 'electronique.jpg', 'poissons.jpg'];
    $filename = $args['filename'];
    if (!in_array($filename, $allowed, true)) {
        return $response->withStatus(404);
    }
    $filePath = __DIR__ . '/assets/images/aquaponie-description/' . $filename;
    if (!is_file($filePath)) {
        return $response->withStatus(404);
    }
    $response->getBody()->write(file_get_contents($filePath));
    return $response->withHeader('Content-Type', 'image/jpeg');
});

$app->get('/service-worker.js', function (Request $request, Response $response) {
    $swPath = __DIR__ . '/service-worker.js';
    if (file_exists($swPath)) {
        $response->getBody()->write(file_get_contents($swPath));
        return $response->withHeader('Content-Type', 'application/javascript');
    }
    return $response->withStatus(404);
});

$app->get('/manifest.json', function (Request $request, Response $response) {
    $manifestPath = __DIR__ . '/manifest.json';
    if (file_exists($manifestPath)) {
        $response->getBody()->write(file_get_contents($manifestPath));
        return $response->withHeader('Content-Type', 'application/json');
    }
    return $response->withStatus(404);
});

// robots.txt — évite 404 pour les crawlers et réduit le bruit en logs.
// Patterns généralisés (ne révèle pas les routes API précises).
$app->get('/robots.txt', function (Request $request, Response $response) use ($app) {
    $basePath = $app->getBasePath() ?: '';
    $body = "User-agent: *\n"
        . "Allow: /\n"
        . 'Disallow: ' . $basePath . "/admin\n"
        . 'Disallow: ' . $basePath . "/api\n"
        . 'Disallow: ' . $basePath . "/dashboard\n"
        . 'Disallow: ' . $basePath . "/supervision\n"
        . 'Disallow: ' . $basePath . "/export-data\n"
        . 'Disallow: ' . $basePath . "/pgl/\n"
        . 'Disallow: ' . $basePath . "/aquaponie-control\n"
        . 'Disallow: ' . $basePath . "/meteo-control\n"
        . 'Disallow: ' . $basePath . "/serre-control\n"
        . 'Disallow: ' . $basePath . "/aquamobile-control\n"
        . 'Disallow: ' . $basePath . "/tide-stats\n";
    $response->getBody()->write($body);
    return $response
        ->withHeader('Content-Type', 'text/plain; charset=utf-8')
        ->withStatus(200);
});

// favicon.ico — évite 404 (icône réelle à ajouter dans public/assets/icons si besoin)
$app->get('/favicon.ico', function (Request $request, Response $response) {
    $iconPath = __DIR__ . '/assets/icons/favicon.ico';
    if (file_exists($iconPath)) {
        $response->getBody()->write(file_get_contents($iconPath));
        return $response->withHeader('Content-Type', 'image/x-icon')->withStatus(200);
    }
    return $response->withStatus(204);
});

// Redirections 301 explicites /ffp3* (complément au middleware, évite 404 si .htaccess non appliqué)
$app->get('/ffp3', function (Request $request, Response $response) {
    return $response->withHeader('Location', '/')->withStatus(301);
});
$app->get('/ffp3/', function (Request $request, Response $response) {
    return $response->withHeader('Location', '/')->withStatus(301);
});
$app->get('/ffp3/control', function (Request $request, Response $response) {
    return $response->withHeader('Location', '/aquaponie-control')->withStatus(301);
});
$app->get('/ffp3/control-test', function (Request $request, Response $response) {
    return $response->withHeader('Location', '/aquaponie-control-test')->withStatus(301);
});
$app->get('/ffp3/supervision', function (Request $request, Response $response) {
    return $response->withHeader('Location', '/supervision')->withStatus(301);
});

// ====================================================================
// Routes MSP1 — station meteo (Le potager)
// ====================================================================

// Nouvelles routes unifiées
$app->map(['GET', 'POST'], '/meteo', [($useLocalDataFallback ? LocalDataPagesController::class : MspDataController::class), $useLocalDataFallback ? 'showMsp1' : 'show']);
$app->get('/meteo-control', [MspOutputController::class, 'showControlPage']);
$app->map(['GET', 'POST'], '/serre', [($useLocalDataFallback ? LocalDataPagesController::class : N3ppDataController::class), $useLocalDataFallback ? 'showN3pp' : 'show']);
$app->get('/serre-control', [N3ppOutputController::class, 'showControlPage']);

// Redirections 301 MSP1 / N3PP
$app->get('/msp1_data', fn ($rq, $rs) => $rs->withHeader('Location', $basePath . '/meteo')->withStatus(301));
$app->map(['GET', 'POST'], '/msp1/msp1datas/msp1-data.php', function (Request $request, Response $response) use ($basePath) {
    $query = $request->getUri()->getQuery();
    return $response->withHeader('Location', $basePath . '/meteo' . ($query ? '?' . $query : ''))->withStatus(301);
});
$app->get('/msp1/msp1control/', fn ($rq, $rs) => $rs->withHeader('Location', $basePath . '/meteo-control')->withStatus(301));
$app->get('/msp1/msp1control/index.php', fn ($rq, $rs) => $rs->withHeader('Location', $basePath . '/meteo-control')->withStatus(301));
$app->map(['GET', 'POST'], '/n3pp/n3ppdatas/n3pp-data.php', function (Request $request, Response $response) use ($basePath) {
    $query = $request->getUri()->getQuery();
    return $response->withHeader('Location', $basePath . '/serre' . ($query ? '?' . $query : ''))->withStatus(301);
});
$app->get('/n3pp/n3ppcontrol/', fn ($rq, $rs) => $rs->withHeader('Location', $basePath . '/serre-control')->withStatus(301));
$app->get('/n3pp/n3ppcontrol/index.php', fn ($rq, $rs) => $rs->withHeader('Location', $basePath . '/serre-control')->withStatus(301));

// Routes MSP1 et N3PP (prod + test) — config/routes_msp1_n3pp.php + config/modules.php
$msp1DataController = $useLocalDataFallback ? LocalDataPagesController::class : MspDataController::class;
$msp1DataMethod = $useLocalDataFallback ? 'showMsp1' : 'show';
$n3ppDataController = $useLocalDataFallback ? LocalDataPagesController::class : N3ppDataController::class;
$n3ppDataMethod = $useLocalDataFallback ? 'showN3pp' : 'show';
require __DIR__ . '/../config/routes_msp1_n3pp.php';

// ====================================================================
// Routes Galeries photo — config/routes_gallery.php
// ====================================================================
require __DIR__ . '/../config/routes_gallery.php';

// ====================================================================
// Middleware Slim (routing et erreurs)
// ====================================================================
$app->addRoutingMiddleware();
// displayErrorDetails = true uniquement hors prod (dev/test/local). En prod le
// ErrorHandlerMiddleware custom traite les exceptions sans exposer la stack PHP.
$isProd = (($_ENV['ENV'] ?? 'prod') === 'prod') && PHP_SAPI !== 'cli-server';
$errorMiddleware = $app->addErrorMiddleware(!$isProd, true, true);

// Gestionnaire 404 personnalisé : log l'URL pour faciliter le diagnostic (error.log serveur)
$errorMiddleware->setErrorHandler(
    HttpNotFoundException::class,
    function (Request $request, Throwable $exception, bool $displayErrorDetails): Response {
        $uri = (string) $request->getUri();
        error_log(sprintf('[%s] [n3-iot 404] %s %s', date('Y-m-d H:i:s'), $request->getMethod(), $uri));
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write('Not found.');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
);

// Dernier add = exécution en premier : capture php://input avant consommation mod_php (HMAC X-Sig)
$app->add(new RawPostBodyMiddleware());

$app->run();
