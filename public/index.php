<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Env;
use App\Controller\AquaponieController;
use App\Controller\AquaponieDescriptionController;
use App\Controller\AuthController;
use App\Controller\CacheController;
use App\Controller\DashboardController;
use App\Controller\ExportController;
use App\Controller\Gallery\GalleryUploadController;
use App\Controller\Gallery\GalleryViewController;
use App\Controller\HeartbeatController;
use App\Controller\HomeController;
use App\Controller\Msp\MspDataController;
use App\Controller\Msp\MspOutputController;
use App\Controller\Msp\MspPostDataController;
use App\Controller\Msp\MspRealtimeApiController;
use App\Controller\N3pp\N3ppDataController;
use App\Controller\N3pp\N3ppOutputController;
use App\Controller\N3pp\N3ppPostDataController;
use App\Controller\N3pp\N3ppRealtimeApiController;
use App\Controller\OutputController;
use App\Controller\PostDataController;
use App\Controller\RealtimeApiController;
use App\Controller\LocalDataPagesController;
use App\Controller\SupervisionController;
use App\Controller\TideStatsController;
use App\Middleware\AuthMiddleware;
use App\Middleware\EnvironmentMiddleware;
use App\Middleware\TokenAuthMiddleware;
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

$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// ====================================================================
// Middleware de gestion d'erreurs personnalisé
// ====================================================================
$app->add($container->get(\App\Middleware\ErrorHandlerMiddleware::class));

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
    // /ffp3/xxx -> /xxx (sauf /ffp3/ffp3gallery/ et /ffp3/api/outputs* pour contrôle aquaponie)
    $isFfp3ApiOutputs = (strpos($path, '/ffp3/api/outputs') === 0);
    if (strpos($path, '/ffp3/') === 0 && strpos($path, '/ffp3/ffp3gallery/') !== 0 && !$isFfp3ApiOutputs) {
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
$app->post('/login', [AuthController::class, 'handleLogin']);
$app->get('/logout', [AuthController::class, 'handleLogout']);

// Déterminer la méthode d'authentification à utiliser
Env::load();
$authMethod = $_ENV['AUTH_METHOD'] ?? 'session'; // 'session', 'token', ou 'both'

// Fonction helper pour appliquer l'authentification selon la méthode configurée
$applyAuth = function ($request, $handler) use ($container, $authMethod) {
    if ($authMethod === 'none' || empty($authMethod)) {
        // Pas d'authentification si désactivée
        return $handler->handle($request);
    }
    
    $authMiddleware = $container->get(AuthMiddleware::class);
    $tokenAuthMiddleware = $container->get(TokenAuthMiddleware::class);
    $authService = $container->get(\App\Security\AuthService::class);
    
    if ($authMethod === 'session') {
        return $authMiddleware->process($request, $handler);
    } elseif ($authMethod === 'token') {
        return $tokenAuthMiddleware->process($request, $handler);
    } elseif ($authMethod === 'both') {
        // Les deux méthodes : vérifier session d'abord, puis token si session échoue
        if ($authService->isAuthenticated()) {
            return $handler->handle($request);
        }
        // Si pas de session, essayer le token
        $queryParams = $request->getQueryParams();
        if ($authService->isAuthenticatedByToken($queryParams)) {
            return $handler->handle($request);
        }
        // Aucune authentification valide, rediriger vers login
        return $authMiddleware->process($request, $handler);
    }
    // Par défaut, utiliser l'authentification par session
    return $authMiddleware->process($request, $handler);
};

// ====================================================================
// Middleware global pour protéger les routes protégées avant le routage
// ====================================================================
// Ce middleware intercepte les requêtes vers les chemins protégés même si la route n'est pas trouvée
$app->add(function (Request $request, $handler) use ($container, $authMethod) {
    if ($authMethod === 'none' || empty($authMethod)) {
        return $handler->handle($request);
    }
    
    $uri = $request->getUri();
    $path = $uri->getPath();
    
    // Liste des chemins publics (doivent commencer par ces chemins) - exclus de la protection
    $publicPaths = [
        '/api/realtime',           // API temps réel pour pages aquaponie publiques
        '/api/realtime-test',
        '/api/realtime3',
        '/api/realtime3-test',
        '/post-data',              // Endpoints firmware ESP32 (FFP)
        '/post-data-test',
        '/post-data3',
        '/post-data3-test',
        '/heartbeat',              // Heartbeat firmware ESP32
        '/heartbeat-test',
        '/heartbeat3',
        '/heartbeat3-test',
        '/ping',                   // Diagnostic latence (GET/POST, réponse minimale)
        '/msp1/',                  // Endpoints firmware msp2_5 (station meteo)
        '/msp1-test/',             // Endpoints firmware msp2_5 TEST
        '/msp1_data',              // Alias court -> redirection vers page donnees MSP1
        '/msp1datas/',             // Alias sans préfixe /msp1/ (compatibilité firmwares)
        '/n3pp/',                  // Endpoints firmware n3pp4_2 (serre)
        '/n3pp-test/',             // Endpoints firmware n3pp4_2 TEST
        '/n3ppdatas/',             // Alias sans préfixe /n3pp/ (compatibilité firmwares)
        '/msp1gallery/',           // Upload photo ESP32-CAM
        '/n3ppgallery/',           // Upload photo ESP32-CAM
        '/ffp3/ffp3gallery/',      // Upload photo ESP32-CAM vers FFP3
        '/ffp3/post-data',         // API firmware FFP avec base URL /ffp3/ (tous profils)
        '/ffp3/post-data-test',
        '/ffp3/post-data3',
        '/ffp3/post-data3-test',
        '/ffp3/heartbeat',
        '/ffp3/heartbeat-test',
        '/ffp3/heartbeat3',
        '/ffp3/heartbeat3-test',
        '/gallery/',               // Pages galeries photo (consultation)
        '/ota/',                   // Fichiers OTA (metadata.json, firmware.bin) n3pp, msp, cam
        '/ffp3datas/',             // Alias legacy (LVGL_Widgets)
        '/ffp3control/',           // Alias legacy (LVGL_Widgets)
    ];
    
    // Vérifier si le chemin est public (GET /api/outputs*/state uniquement)
    $isPublic = false;
    foreach ($publicPaths as $publicPath) {
        if (strpos($path, $publicPath) === 0) {
            $isPublic = true;
            break;
        }
    }
    
    // Les endpoints GET /api/outputs*/state sont publics (utilisés par firmware ESP32)
    if (!$isPublic && preg_match('#^/(ffp3/)?api/outputs(-test|3-test|3)?/state$#', $path)) {
        $isPublic = true;
    }
    
    // Si le chemin est public, ne pas vérifier l'authentification
    if ($isPublic) {
        return $handler->handle($request);
    }
    
    // Liste des chemins protégés (doivent commencer par ces chemins)
    $protectedPaths = [
        '/aquaponie-control',
        '/aquaponie-control-test',
        '/aquamobile-control',
        '/aquamobile-control-test',
        '/dashboard',
        '/dashboard-test',
        '/dashboard3',
        '/dashboard3-test',
        '/supervision',
        '/tide-stats',
        '/tide-stats-test',
        '/tide-stats3',
        '/tide-stats3-test',
        '/export-data',
        '/export-data-test',
        '/export-data3',
        '/export-data3-test',
        '/admin',
        '/api/outputs',            // Protégé sauf /state (géré ci-dessus)
        '/api/outputs-test',
        '/api/outputs3',
        '/api/outputs3-test',
        '/ffp3/api/outputs',       // Contrôle aquaponie (toggle, parameters) depuis page /ffp3/*
        '/ffp3/api/outputs-test',
        '/ffp3/api/outputs3-test',
        '/ffp3/api/outputs3',
    ];
    
    // Vérifier si le chemin demandé est protégé
    $isProtected = false;
    foreach ($protectedPaths as $protectedPath) {
        if (strpos($path, $protectedPath) === 0) {
            $isProtected = true;
            break;
        }
    }
    
    // Si le chemin est protégé, vérifier l'authentification
    if ($isProtected) {
        $authService = $container->get(\App\Security\AuthService::class);
        $isAuthenticated = false;
        
        if ($authMethod === 'session' || $authMethod === 'both') {
            $isAuthenticated = $authService->isAuthenticated();
        }
        
        if (!$isAuthenticated && ($authMethod === 'token' || $authMethod === 'both')) {
            $queryParams = $request->getQueryParams();
            $isAuthenticated = $authService->isAuthenticatedByToken($queryParams);
        }
        
        // Si non authentifié, rediriger vers login
        if (!$isAuthenticated) {
            $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            if (strpos($scriptName, '/public/index.php') !== false) {
                $basePath = dirname(dirname($scriptName));
            } else {
                $basePath = dirname($scriptName);
            }
            $basePath = rtrim($basePath, '/');
            
            $loginPath = ($basePath !== '' ? $basePath : '') . '/login';
            $redirectUrl = $loginPath . '?redirect=' . urlencode($path);
            
            $response = new \Slim\Psr7\Response();
            return $response
                ->withStatus(302)
                ->withHeader('Location', $redirectUrl);
        }
    }
    
    return $handler->handle($request);
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
$app->get('/control', function (Request $request, Response $response) use ($basePath) {
    return $response->withHeader('Location', $basePath . '/aquaponie-control')->withStatus(301);
});
$app->get('/control-test', function (Request $request, Response $response) use ($basePath) {
    return $response->withHeader('Location', $basePath . '/aquaponie-control-test')->withStatus(301);
});
$app->get('/control3-test', function (Request $request, Response $response) use ($basePath) {
    return $response->withHeader('Location', $basePath . '/aquamobile-control-test')->withStatus(301);
});
$app->get('/control3', function (Request $request, Response $response) use ($basePath) {
    return $response->withHeader('Location', $basePath . '/aquamobile-control')->withStatus(301);
});
$app->map(['GET', 'POST'], '/aquaponie3-test', function (Request $request, Response $response) use ($basePath) {
    return $response->withHeader('Location', $basePath . '/aquamobile-test')->withStatus(301);
});
$app->map(['GET', 'POST'], '/aquaponie3', function (Request $request, Response $response) use ($basePath) {
    return $response->withHeader('Location', $basePath . '/aquamobile')->withStatus(301);
});
$app->map(['GET', 'POST'], '/aquaponie-alt3-test', function (Request $request, Response $response) use ($basePath) {
    return $response->withHeader('Location', $basePath . '/aquamobile-alt-test')->withStatus(301);
});
$app->map(['GET', 'POST'], '/aquaponie-alt3', function (Request $request, Response $response) use ($basePath) {
    return $response->withHeader('Location', $basePath . '/aquamobile-alt')->withStatus(301);
});

// Pages aquaponie - PUBLIQUES (avec middleware d'environnement mais sans authentification)
// Inversion 2026-03 : /aquaponie = vue paysage (main), /aquaponie-alt = vue classique (alt)
// PRODUCTION
$app->group('', function ($group) use ($basePath, $useLocalDataFallback) {
    $group->map(['GET', 'POST'], '/aquaponie', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponie' : 'showAlt']);
    $group->map(['GET', 'POST'], '/aquaponie-alt', [($useLocalDataFallback ? LocalDataPagesController::class : AquaponieController::class), $useLocalDataFallback ? 'showAquaponieClassic' : 'show']);
    $group->get('/ffp3-data', function (Request $request, Response $response) use ($basePath) {
        return $response->withHeader('Location', $basePath . '/aquaponie')->withStatus(301);
    }); // Redirection legacy vers aquaponie
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

// Page Caractéristiques du module FFP3 - PUBLIQUE (pas de variante env)
$app->get('/aquaponie-description', [AquaponieDescriptionController::class, 'show']);

// Fichiers OTA (n3pp, msp, cam) — servis depuis serveur/ota/
$app->get('/ota/{path:.+}', function (Request $request, Response $response, array $args) {
    $path = $args['path'] ?? '';
    if (strpos($path, '..') !== false || $path === '') {
        return $response->withStatus(400);
    }
    $otaDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ota' . DIRECTORY_SEPARATOR;
    $file = $otaDir . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($file)) {
        return $response->withStatus(404);
    }
    $body = (string) file_get_contents($file);
    $response->getBody()->write($body);
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    if ($ext === 'json') {
        return $response->withHeader('Content-Type', 'application/json');
    }
    if ($ext === 'bin') {
        return $response->withHeader('Content-Type', 'application/octet-stream');
    }
    return $response;
});

// ====================================================================
// Routes API PUBLIQUES (utilisées par pages aquaponie et firmware ESP32)
// ====================================================================

// API Temps Réel - PUBLIQUES (utilisées par pages aquaponie)
// PRODUCTION
$app->group('', function ($group) {
    $group->get('/api/realtime/sensors/latest', [RealtimeApiController::class, 'getLatestSensors']);
    $group->get('/api/realtime/sensors/since/{timestamp}', [RealtimeApiController::class, 'getSensorsSince']);
    $group->get('/api/realtime/outputs/state', [RealtimeApiController::class, 'getOutputsState']);
    $group->get('/api/realtime/system/health', [RealtimeApiController::class, 'getSystemHealth']);
    $group->get('/api/realtime/alerts/active', [RealtimeApiController::class, 'getActiveAlerts']);
    $group->get('/api/health', [RealtimeApiController::class, 'getSystemHealth']); // Alias
})->add(new EnvironmentMiddleware('prod'));

// TEST
$app->group('', function ($group) {
    $group->get('/api/realtime-test/sensors/latest', [RealtimeApiController::class, 'getLatestSensors']);
    $group->get('/api/realtime-test/sensors/since/{timestamp}', [RealtimeApiController::class, 'getSensorsSince']);
    $group->get('/api/realtime-test/outputs/state', [RealtimeApiController::class, 'getOutputsState']);
    $group->get('/api/realtime-test/system/health', [RealtimeApiController::class, 'getSystemHealth']);
    $group->get('/api/realtime-test/alerts/active', [RealtimeApiController::class, 'getActiveAlerts']);
    $group->get('/api/health-test', [RealtimeApiController::class, 'getSystemHealth']); // Alias
    $group->get('/api/realtime/system/health-test', [RealtimeApiController::class, 'getSystemHealth']); // Alias
})->add(new EnvironmentMiddleware('test'));

// TEST3
$app->group('', function ($group) {
    $group->get('/api/realtime3-test/sensors/latest', [RealtimeApiController::class, 'getLatestSensors']);
    $group->get('/api/realtime3-test/sensors/since/{timestamp}', [RealtimeApiController::class, 'getSensorsSince']);
    $group->get('/api/realtime3-test/outputs/state', [RealtimeApiController::class, 'getOutputsState']);
    $group->get('/api/realtime3-test/system/health', [RealtimeApiController::class, 'getSystemHealth']);
    $group->get('/api/realtime3-test/alerts/active', [RealtimeApiController::class, 'getActiveAlerts']);
})->add(new EnvironmentMiddleware('test3'));

// S3 PROD
$app->group('', function ($group) {
    $group->get('/api/realtime3/sensors/latest', [RealtimeApiController::class, 'getLatestSensors']);
    $group->get('/api/realtime3/sensors/since/{timestamp}', [RealtimeApiController::class, 'getSensorsSince']);
    $group->get('/api/realtime3/outputs/state', [RealtimeApiController::class, 'getOutputsState']);
    $group->get('/api/realtime3/system/health', [RealtimeApiController::class, 'getSystemHealth']);
    $group->get('/api/realtime3/alerts/active', [RealtimeApiController::class, 'getActiveAlerts']);
})->add(new EnvironmentMiddleware('s3'));

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

// Endpoints Firmware ESP32 - PUBLICS
// PRODUCTION
$app->group('', function ($group) {
    $group->post('/post-data', [PostDataController::class, 'handle']);
    $group->post('/post-ffp3-data.php', [PostDataController::class, 'handle']); // Alias legacy
    $group->post('/ffp3datas/post-ffp3-data2.php', [PostDataController::class, 'handle']); // Alias legacy (LVGL_Widgets)
    $group->get('/api/outputs/state', [OutputController::class, 'getOutputsState']);
    $group->get('/ffp3control/ffp3-outputs-action2.php', [OutputController::class, 'getOutputsState']); // Alias legacy (LVGL_Widgets)
    $group->post('/heartbeat', [HeartbeatController::class, 'handle']);
    $group->post('/heartbeat.php', [HeartbeatController::class, 'handle']); // Alias legacy
})->add(new EnvironmentMiddleware('prod'));

// TEST
$app->group('', function ($group) {
    $group->post('/post-data-test', [PostDataController::class, 'handle']);
    $group->get('/api/outputs-test/state', [OutputController::class, 'getOutputsState']);
    $group->post('/heartbeat-test', [HeartbeatController::class, 'handle']);
    $group->post('/heartbeat-test.php', [HeartbeatController::class, 'handle']); // Alias legacy
})->add(new EnvironmentMiddleware('test'));

// TEST3
$app->group('', function ($group) {
    $group->post('/post-data3-test', [PostDataController::class, 'handle']);
    $group->get('/api/outputs3-test/state', [OutputController::class, 'getOutputsState']);
    $group->post('/heartbeat3-test', [HeartbeatController::class, 'handle']);
})->add(new EnvironmentMiddleware('test3'));

// S3 PROD
$app->group('', function ($group) {
    $group->post('/post-data3', [PostDataController::class, 'handle']);
    $group->get('/api/outputs3/state', [OutputController::class, 'getOutputsState']);
    $group->post('/heartbeat3', [HeartbeatController::class, 'handle']);
})->add(new EnvironmentMiddleware('s3'));

// ====================================================================
// Alias /ffp3/* : mêmes endpoints pour firmwares utilisant base URL /ffp3/
// (évite 404 lorsque le firmware envoie vers iot.olution.info/ffp3/post-data3-test)
// ====================================================================
$app->group('/ffp3', function ($group) {
    $group->post('/post-data', [PostDataController::class, 'handle']);
    $group->get('/api/outputs/state', [OutputController::class, 'getOutputsState']);
    $group->post('/heartbeat', [HeartbeatController::class, 'handle']);
})->add(new EnvironmentMiddleware('prod'));

$app->group('/ffp3', function ($group) {
    $group->post('/post-data-test', [PostDataController::class, 'handle']);
    $group->get('/api/outputs-test/state', [OutputController::class, 'getOutputsState']);
    $group->post('/heartbeat-test', [HeartbeatController::class, 'handle']);
})->add(new EnvironmentMiddleware('test'));

$app->group('/ffp3', function ($group) {
    $group->post('/post-data3-test', [PostDataController::class, 'handle']);
    $group->get('/api/outputs3-test/state', [OutputController::class, 'getOutputsState']);
    $group->post('/heartbeat3-test', [HeartbeatController::class, 'handle']);
})->add(new EnvironmentMiddleware('test3'));

$app->group('/ffp3', function ($group) {
    $group->post('/post-data3', [PostDataController::class, 'handle']);
    $group->get('/api/outputs3/state', [OutputController::class, 'getOutputsState']);
    $group->post('/heartbeat3', [HeartbeatController::class, 'handle']);
})->add(new EnvironmentMiddleware('s3'));

// ====================================================================
// Contrôle aquaponie sous /ffp3 (toggle, parameters) — protégé par auth
// (page /ffp3/aquaponie-control* appelle /ffp3/api/outputs*/toggle et /parameters)
// ====================================================================
$app->group('/ffp3', function ($group) {
    $group->get('/api/outputs/toggle', [OutputController::class, 'toggleOutput']);
    $group->post('/api/outputs/parameters', [OutputController::class, 'updateParameters']);
    $group->post('/api/outputs/trigger-ota-check', [OutputController::class, 'triggerOtaCheck']);
    $group->get('/api/outputs/board/{board}/status', [OutputController::class, 'getBoardStatus']);
})->add(new EnvironmentMiddleware('prod'))
  ->add($applyAuth);

$app->group('/ffp3', function ($group) {
    $group->get('/api/outputs-test/toggle', [OutputController::class, 'toggleOutputTest']);
    $group->post('/api/outputs-test/parameters', [OutputController::class, 'updateParameters']);
    $group->post('/api/outputs-test/trigger-ota-check', [OutputController::class, 'triggerOtaCheck']);
    $group->get('/api/outputs-test/board/{board}/status', [OutputController::class, 'getBoardStatus']);
})->add(new EnvironmentMiddleware('test'))
  ->add($applyAuth);

$app->group('/ffp3', function ($group) {
    $group->get('/api/outputs3-test/toggle', [OutputController::class, 'toggleOutputTest3']);
    $group->post('/api/outputs3-test/parameters', [OutputController::class, 'updateParameters']);
    $group->post('/api/outputs3-test/trigger-ota-check', [OutputController::class, 'triggerOtaCheck']);
    $group->get('/api/outputs3-test/board/{board}/status', [OutputController::class, 'getBoardStatus']);
})->add(new EnvironmentMiddleware('test3'))
  ->add($applyAuth);

$app->group('/ffp3', function ($group) {
    $group->get('/api/outputs3/toggle', [OutputController::class, 'toggleOutputS3']);
    $group->post('/api/outputs3/parameters', [OutputController::class, 'updateParameters']);
    $group->post('/api/outputs3/trigger-ota-check', [OutputController::class, 'triggerOtaCheck']);
    $group->get('/api/outputs3/board/{board}/status', [OutputController::class, 'getBoardStatus']);
})->add(new EnvironmentMiddleware('s3'))
  ->add($applyAuth);

// ====================================================================
// Routes PRODUCTION (par défaut) - avec middleware pour forcer 'prod'
// ====================================================================
$app->group('', function ($group) {
    // Page de supervision (liens vers toutes les pages) - PROTÉGÉE
    $group->get('/supervision', [SupervisionController::class, 'show']);

    // Dashboard - PROTÉGÉ
    $group->get('/dashboard', [DashboardController::class, 'show']);

    // Export CSV - PROTÉGÉ
    $group->get('/export-data', [ExportController::class, 'downloadCsv']);
    $group->get('/export-data.php', [ExportController::class, 'downloadCsv']); // Alias legacy

    // Statistiques marées - PROTÉGÉES
    $group->map(['GET', 'POST'], '/tide-stats', [TideStatsController::class, 'show']);

    // Interface de contrôle PROD - PROTÉGÉE
    $group->get('/aquaponie-control', [OutputController::class, 'showInterface']);
    $group->get('/api/outputs/toggle', [OutputController::class, 'toggleOutput']);
    $group->get('/api/outputs/toggle-test', [OutputController::class, 'toggleOutputTest']);
    // Note: /api/outputs/state est public (défini dans le groupe public ci-dessus)
    $group->post('/api/outputs/parameters', [OutputController::class, 'updateParameters']);
    $group->post('/api/outputs/trigger-ota-check', [OutputController::class, 'triggerOtaCheck']);
    $group->get('/api/outputs/board/{board}/status', [OutputController::class, 'getBoardStatus']);

    // ====================================================================
    // Administration - Gestion du cache PROD - PROTÉGÉE
    // ====================================================================
    $group->get('/admin/clear-cache', [CacheController::class, 'clearCache']);
    $group->get('/admin/clear-cache-page', [CacheController::class, 'clearCachePage']);
    $group->get('/admin/deploy-script', [CacheController::class, 'showDeployScript']);
})->add(new EnvironmentMiddleware('prod'))
  ->add($applyAuth);

// ====================================================================
// Groupe de routes TEST (avec middleware EnvironmentMiddleware)
// ====================================================================
$app->group('', function ($group) {
    // Dashboard TEST - PROTÉGÉ
    $group->get('/dashboard-test', [DashboardController::class, 'show']);
    
    // Statistiques marées TEST - PROTÉGÉES
    $group->map(['GET', 'POST'], '/tide-stats-test', [TideStatsController::class, 'show']);
    
    // Export CSV TEST - PROTÉGÉ
    $group->get('/export-data-test', [ExportController::class, 'downloadCsv']);
    
    // Interface de contrôle TEST - PROTÉGÉE
    $group->get('/aquaponie-control-test', [OutputController::class, 'showInterface']);
    $group->get('/api/outputs-test/toggle', [OutputController::class, 'toggleOutputTest']);
    // Note: /api/outputs-test/state est public (défini dans le groupe public ci-dessus)
    $group->post('/api/outputs-test/parameters', [OutputController::class, 'updateParameters']);
    $group->post('/api/outputs-test/trigger-ota-check', [OutputController::class, 'triggerOtaCheck']);
    $group->get('/api/outputs-test/board/{board}/status', [OutputController::class, 'getBoardStatus']);
    
    // ====================================================================
    // Administration - Gestion du cache TEST - PROTÉGÉE
    // ====================================================================
    $group->get('/admin/clear-cache-test', [CacheController::class, 'clearCache']);
    $group->get('/admin/clear-cache-page-test', [CacheController::class, 'clearCachePage']);
    
    // ====================================================================
    // Fichiers statiques TEST (fallback si serveur web ne les sert pas)
    // ====================================================================
    // Note: Les fichiers statiques sont gérés par le groupe global pour éviter les conflits de routes
    
})->add(new EnvironmentMiddleware('test'))
  ->add($applyAuth);

// ====================================================================
// Groupe de routes TEST3 (avec middleware EnvironmentMiddleware)
// ====================================================================
$app->group('', function ($group) {
    // Dashboard TEST3 - PROTÉGÉ
    $group->get('/dashboard3-test', [DashboardController::class, 'show']);

    // Statistiques marées TEST3 - PROTÉGÉES
    $group->map(['GET', 'POST'], '/tide-stats3-test', [TideStatsController::class, 'show']);

    // Export CSV TEST3 - PROTÉGÉ
    $group->get('/export-data3-test', [ExportController::class, 'downloadCsv']);

    // Interface de contrôle TEST3 - PROTÉGÉE
    $group->get('/aquamobile-control-test', [OutputController::class, 'showInterface']);
    $group->get('/api/outputs3-test/toggle', [OutputController::class, 'toggleOutputTest3']);
    // Note: /api/outputs3-test/state est public (défini dans le groupe public ci-dessus)
    $group->post('/api/outputs3-test/parameters', [OutputController::class, 'updateParameters']);
    $group->post('/api/outputs3-test/trigger-ota-check', [OutputController::class, 'triggerOtaCheck']);
    $group->get('/api/outputs3-test/board/{board}/status', [OutputController::class, 'getBoardStatus']);

    // Administration - Gestion du cache TEST3 - PROTÉGÉE
    $group->get('/admin/clear-cache3-test', [CacheController::class, 'clearCache']);
    $group->get('/admin/clear-cache-page3-test', [CacheController::class, 'clearCachePage']);
})->add(new EnvironmentMiddleware('test3'))
  ->add($applyAuth);

// ====================================================================
// Groupe de routes S3 prod (aquamobile, aquamobile-control - tables 4, board 5)
// ====================================================================
$app->group('', function ($group) {
    // Dashboard S3 - PROTÉGÉ
    $group->get('/dashboard3', [DashboardController::class, 'show']);

    // Statistiques marées S3 - PROTÉGÉES
    $group->map(['GET', 'POST'], '/tide-stats3', [TideStatsController::class, 'show']);

    // Export CSV S3 - PROTÉGÉ
    $group->get('/export-data3', [ExportController::class, 'downloadCsv']);

    // Interface de contrôle S3 - PROTÉGÉE
    $group->get('/aquamobile-control', [OutputController::class, 'showInterface']);
    $group->get('/api/outputs3/toggle', [OutputController::class, 'toggleOutputS3']);
    // Note: /api/outputs3/state est public (défini dans le groupe public ci-dessus)
    $group->post('/api/outputs3/parameters', [OutputController::class, 'updateParameters']);
    $group->post('/api/outputs3/trigger-ota-check', [OutputController::class, 'triggerOtaCheck']);
    $group->get('/api/outputs3/board/{board}/status', [OutputController::class, 'getBoardStatus']);

    // Administration - Gestion du cache S3 - PROTÉGÉE
    $group->get('/admin/clear-cache3', [CacheController::class, 'clearCache']);
    $group->get('/admin/clear-cache-page3', [CacheController::class, 'clearCachePage']);
})->add(new EnvironmentMiddleware('s3'))
  ->add($applyAuth);

// ====================================================================
// Fichiers statiques GLOBAUX (disponibles pour PROD et TEST)
// ====================================================================
// Ces routes sont partagées entre les deux environnements pour éviter les conflits
$app->get('/assets/js/{filename}', function (Request $request, Response $response, $args) {
    $filename = $args['filename'];
    $allowedFiles = [
        'jquery.min.js',
        'jquery.scrollex.min.js',
        'jquery.scrolly.min.js',
        'browser.min.js',
        'breakpoints.min.js',
        'util.js',
        'main.js',
        'control-values-updater.js',
        'control-sync.js',
        'chart-updater.js',
        'stats-updater.js',
        'realtime-updater.js',
        'toast-notifications.js',
        'pwa-init.js',
        'mobile-gestures.js',
        'control-actions.js',
        'control-auto-save.js',
    ];
    
    if (!in_array($filename, $allowedFiles)) {
        return $response->withStatus(404);
    }
    
    $filePath = __DIR__ . '/assets/js/' . $filename;
    if (file_exists($filePath)) {
        $response->getBody()->write(file_get_contents($filePath));
        return $response->withHeader('Content-Type', 'application/javascript');
    }
    return $response->withStatus(404);
});

$app->get('/assets/css/{filename}', function (Request $request, Response $response, $args) {
    $filename = $args['filename'];
    $allowedFiles = [
        'main.css',
        'noscript.css',
        'control-styles.css',
        'mobile-optimized.css',
        'realtime-styles.css',
        'login-styles.css'
    ];

    $filePath = __DIR__ . '/assets/css/' . $filename;

    if (!in_array($filename, $allowedFiles)) {
        return $response->withStatus(404);
    }

    if (file_exists($filePath)) {
        $response->getBody()->write(file_get_contents($filePath));
        return $response->withHeader('Content-Type', 'text/css');
    }
    return $response->withStatus(404);
});

$app->get('/assets/icons/{filename}', function (Request $request, Response $response, $args) {
    $filename = $args['filename'];
    $allowedFiles = [
        'icon-72.png', 'icon-96.png', 'icon-128.png', 'icon-144.png',
        'icon-152.png', 'icon-192.png', 'icon-384.png', 'icon-512.png'
    ];
    
    if (!in_array($filename, $allowedFiles)) {
        return $response->withStatus(404);
    }
    
    $filePath = __DIR__ . '/assets/icons/' . $filename;
    if (file_exists($filePath)) {
        $response->getBody()->write(file_get_contents($filePath));
        return $response->withHeader('Content-Type', 'image/png');
    }
    return $response->withStatus(404);
});

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

// robots.txt — évite 404 pour les crawlers et réduit le bruit en logs
$app->get('/robots.txt', function (Request $request, Response $response) use ($app) {
    $basePath = $app->getBasePath() ?: '';
    $body = "User-agent: *\nAllow: /\nDisallow: " . $basePath . "/admin/\nDisallow: " . $basePath . "/api/outputs/toggle\n";
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

// Redirection explicite /ffp3/supervision -> /supervision (complément au middleware)
$app->get('/ffp3/supervision', function (Request $request, Response $response) use ($app) {
    $basePath = $app->getBasePath() ?: '';
    $location = $basePath . '/supervision';
    return $response->withHeader('Location', $location)->withStatus(301);
});

// ====================================================================
// Routes MSP1 — compatibilite firmware msp2_5 (station meteo)
// ====================================================================
$app->get('/msp1_data', function (Request $request, Response $response) use ($app) {
    $uri = $request->getUri();
    $basePath = rtrim($app->getBasePath(), '/');
    $path = $basePath . '/msp1/msp1datas/msp1-data.php';
    $location = $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '')
        . $path . ($uri->getQuery() ? '?' . $uri->getQuery() : '');
    return $response->withHeader('Location', $location)->withStatus(301);
});
$app->post('/msp1/msp1datas/post-msp1-data.php', [MspPostDataController::class, 'handle']);
$app->post('/msp1datas/post-msp1-data.php', [MspPostDataController::class, 'handle']); // Alias sans /msp1/ (compatibilité firmwares)
$app->map(['GET', 'POST'], '/msp1/msp1datas/msp1-data.php', [($useLocalDataFallback ? LocalDataPagesController::class : MspDataController::class), $useLocalDataFallback ? 'showMsp1' : 'show']);
$app->get('/msp1/msp1control/msp1-outputs-action.php', [MspOutputController::class, 'getState']);
$app->post('/msp1/msp1control/msp1-outputs-action.php', [MspOutputController::class, 'setOutput']);
$app->get('/msp1/msp1control/', [MspOutputController::class, 'showControlPage']);
$app->get('/msp1/msp1control/index.php', [MspOutputController::class, 'showControlPage']);

// API temps réel MSP (pages données + contrôle)
$app->get('/msp1/api/realtime/sensors/latest', [MspRealtimeApiController::class, 'getLatestSensors']);
$app->get('/msp1/api/realtime/sensors/since/{timestamp}', [MspRealtimeApiController::class, 'getSensorsSince']);
$app->get('/msp1/api/realtime/system/health', [MspRealtimeApiController::class, 'getSystemHealth']);
$app->get('/msp1/api/outputs/state', [MspRealtimeApiController::class, 'getOutputsState']);

// ====================================================================
// Routes N3PP — compatibilite firmware n3pp4_2 (serre/aquaponie)
// ====================================================================
$app->post('/n3pp/n3ppdatas/post-n3pp-data.php', [N3ppPostDataController::class, 'handle']);
$app->post('/n3ppdatas/post-n3pp-data.php', [N3ppPostDataController::class, 'handle']); // Alias sans /n3pp/ (compatibilité firmwares)
$app->map(['GET', 'POST'], '/n3pp/n3ppdatas/n3pp-data.php', [($useLocalDataFallback ? LocalDataPagesController::class : N3ppDataController::class), $useLocalDataFallback ? 'showN3pp' : 'show']);
$app->get('/n3pp/n3ppcontrol/n3pp-outputs-action.php', [N3ppOutputController::class, 'getState']);
$app->post('/n3pp/n3ppcontrol/n3pp-outputs-action.php', [N3ppOutputController::class, 'setOutput']);
$app->get('/n3pp/n3ppcontrol/', [N3ppOutputController::class, 'showControlPage']);
$app->get('/n3pp/n3ppcontrol/index.php', [N3ppOutputController::class, 'showControlPage']);

// API temps réel N3PP (pages données + contrôle)
$app->get('/n3pp/api/realtime/sensors/latest', [N3ppRealtimeApiController::class, 'getLatestSensors']);
$app->get('/n3pp/api/realtime/sensors/since/{timestamp}', [N3ppRealtimeApiController::class, 'getSensorsSince']);
$app->get('/n3pp/api/realtime/system/health', [N3ppRealtimeApiController::class, 'getSystemHealth']);
$app->get('/n3pp/api/outputs/state', [N3ppRealtimeApiController::class, 'getOutputsState']);
$app->post('/n3pp/api/outputs/parameters', [N3ppOutputController::class, 'updateParameters']);

// ====================================================================
// Routes MSP1 TEST — tables msp1DataTest, msp1OutputsTest
// ====================================================================
$app->group('', function ($group) use ($useLocalDataFallback) {
    $group->post('/msp1-test/msp1datas/post-msp1-data.php', [MspPostDataController::class, 'handle']);
    $group->map(['GET', 'POST'], '/msp1-test/msp1datas/msp1-data.php', [($useLocalDataFallback ? LocalDataPagesController::class : MspDataController::class), $useLocalDataFallback ? 'showMsp1' : 'show']);
    $group->get('/msp1-test/msp1control/msp1-outputs-action.php', [MspOutputController::class, 'getState']);
    $group->post('/msp1-test/msp1control/msp1-outputs-action.php', [MspOutputController::class, 'setOutput']);
    $group->get('/msp1-test/msp1control/', [MspOutputController::class, 'showControlPage']);
    $group->get('/msp1-test/msp1control/index.php', [MspOutputController::class, 'showControlPage']);
})->add(new EnvironmentMiddleware('msp_test'));

// ====================================================================
// Routes N3PP TEST — tables n3ppDataTest, n3ppOutputsTest
// ====================================================================
$app->group('', function ($group) use ($useLocalDataFallback) {
    $group->post('/n3pp-test/n3ppdatas/post-n3pp-data.php', [N3ppPostDataController::class, 'handle']);
    $group->map(['GET', 'POST'], '/n3pp-test/n3ppdatas/n3pp-data.php', [($useLocalDataFallback ? LocalDataPagesController::class : N3ppDataController::class), $useLocalDataFallback ? 'showN3pp' : 'show']);
    $group->get('/n3pp-test/n3ppcontrol/n3pp-outputs-action.php', [N3ppOutputController::class, 'getState']);
    $group->post('/n3pp-test/n3ppcontrol/n3pp-outputs-action.php', [N3ppOutputController::class, 'setOutput']);
    $group->get('/n3pp-test/n3ppcontrol/', [N3ppOutputController::class, 'showControlPage']);
    $group->get('/n3pp-test/n3ppcontrol/index.php', [N3ppOutputController::class, 'showControlPage']);
    // API temps réel N3PP TEST (même contrat que prod, tables n3ppDataTest / n3ppOutputsTest)
    $group->get('/n3pp-test/api/realtime/sensors/latest', [N3ppRealtimeApiController::class, 'getLatestSensors']);
    $group->get('/n3pp-test/api/realtime/sensors/since/{timestamp}', [N3ppRealtimeApiController::class, 'getSensorsSince']);
    $group->get('/n3pp-test/api/realtime/system/health', [N3ppRealtimeApiController::class, 'getSystemHealth']);
    $group->get('/n3pp-test/api/outputs/state', [N3ppRealtimeApiController::class, 'getOutputsState']);
    $group->post('/n3pp-test/api/outputs/parameters', [N3ppOutputController::class, 'updateParameters']);
})->add(new EnvironmentMiddleware('n3pp_test'));

// ====================================================================
// Routes Galeries photo — compatibilite firmwares ESP32-CAM (upload)
// ====================================================================
$app->post('/msp1gallery/upload.php', [GalleryUploadController::class, 'handleMsp1']);
$app->post('/msp1/msp1gallery/upload.php', [GalleryUploadController::class, 'handleMsp1']); // Alias avec préfixe /msp1/ (compatibilité firmwares)
$app->post('/n3ppgallery/upload.php', [GalleryUploadController::class, 'handleN3pp']);
$app->post('/n3pp/n3ppgallery/upload.php', [GalleryUploadController::class, 'handleN3pp']); // Alias avec préfixe /n3pp/ (compatibilité firmwares)
$app->post('/ffp3/ffp3gallery/upload.php', [GalleryUploadController::class, 'handleFfp3']);
// Alias pour deploiement avec basePath /ffp3 : pattern sans prefixe pour que la route complete soit /ffp3/ffp3gallery/upload.php
$app->post('/ffp3gallery/upload.php', [GalleryUploadController::class, 'handleFfp3']);

// Galeries photo — pages de consultation (style site actuel)
// ====================================================================
$app->get('/gallery/{slug}/files/{filename}', [GalleryViewController::class, 'serveImage']);
$app->get('/gallery/msp1', [GalleryViewController::class, 'showMsp1']);
$app->get('/gallery/n3pp', [GalleryViewController::class, 'showN3pp']);
$app->get('/gallery/ffp3', [GalleryViewController::class, 'showFfp3']);

// ====================================================================
// Middleware Slim (routing et erreurs)
// ====================================================================
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

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

$app->run();
