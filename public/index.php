<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Env;
use App\Controller\AuthController;
use App\Controller\Ffp3\AquaponieController;
use App\Controller\Ffp3\AquaponieDescriptionController;
use App\Controller\Ffp3\CacheController;
use App\Controller\Ffp3\DashboardController;
use App\Controller\Ffp3\ExportController;
use App\Controller\Ffp3\HeartbeatController;
use App\Controller\Ffp3\OutputController;
use App\Controller\Ffp3\PostDataController;
use App\Controller\Ffp3\RealtimeApiController;
use App\Controller\Ffp3\TideStatsController;
use App\Controller\Gallery\GalleryUploadController;
use App\Controller\Gallery\GalleryViewController;
use App\Controller\HomeController;
use App\Controller\LocalDataPagesController;
use App\Controller\Msp\MspDataController;
use App\Controller\Msp\MspOutputController;
use App\Controller\Msp\MspPostDataController;
use App\Controller\Msp\MspRealtimeApiController;
use App\Controller\N3pp\N3ppDataController;
use App\Controller\N3pp\N3ppOutputController;
use App\Controller\N3pp\N3ppPostDataController;
use App\Controller\N3pp\N3ppRealtimeApiController;
use App\Controller\SupervisionController;
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

// Chargement config routes avec fallback si fichier absent (évite 500 sur prod si config/ non déployé)
$routesConfigPath = __DIR__ . '/../config/routes_config.php';
$loadRoutesConfig = static function () use ($routesConfigPath): array {
    if (is_file($routesConfigPath)) {
        return require $routesConfigPath;
    }
    error_log('[iot.olution.info] routes_config.php absent: ' . $routesConfigPath);
    return [
        'exact_public_paths' => ['/', '/login', '/logout', '/ping', '/favicon.ico'],
        'public_paths' => ['/api/', '/post-data', '/heartbeat', '/assets/', '/aquaponie', '/meteo', '/serre', '/gallery/', '/ota/'],
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
$app->add(function (Request $request, $handler) use ($container, $authMethod, $loadRoutesConfig) {
    if ($authMethod === 'none' || empty($authMethod)) {
        return $handler->handle($request);
    }
    
    $uri = $request->getUri();
    $path = $uri->getPath();

    $routesConfig = $loadRoutesConfig();
    $publicPaths = $routesConfig['public_paths'];
    $exactPublicPaths = $routesConfig['exact_public_paths'];
    
    // Vérifier si le chemin est public (GET /api/outputs*/state uniquement)
    $isPublic = in_array($path, $exactPublicPaths, true);
    if (!$isPublic) {
        foreach ($publicPaths as $publicPath) {
            if (strpos($path, $publicPath) === 0) {
                $isPublic = true;
                break;
            }
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
    
    $protectedPaths = $routesConfig['protected_paths'];
    
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
$routesConfigFull = $loadRoutesConfig();
registerRedirects($app, $routesConfigFull['redirects_301'], $basePath);

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
// Helpers pour factoriser les routes FFP3 multi-environnements
// ====================================================================

/**
 * Enregistre les 5 routes realtime sous un préfixe donné.
 */
function registerRealtimeRoutes($app, string $prefix, string $env, array $aliases = []): void
{
    $app->group('', function ($group) use ($prefix, $aliases) {
        $group->get($prefix . '/sensors/latest', [RealtimeApiController::class, 'getLatestSensors']);
        $group->get($prefix . '/sensors/since/{timestamp}', [RealtimeApiController::class, 'getSensorsSince']);
        $group->get($prefix . '/outputs/state', [RealtimeApiController::class, 'getOutputsState']);
        $group->get($prefix . '/system/health', [RealtimeApiController::class, 'getSystemHealth']);
        $group->get($prefix . '/alerts/active', [RealtimeApiController::class, 'getActiveAlerts']);
        foreach ($aliases as $alias) {
            $group->get($alias, [RealtimeApiController::class, 'getSystemHealth']);
        }
    })->add(new EnvironmentMiddleware($env));
}

/**
 * Enregistre les routes firmware (post-data, outputs/state, heartbeat) sous un préfixe optionnel.
 */
function registerFirmwareRoutes($app, string $groupPrefix, string $env, string $postDataPath, string $outputsStatePath, string $heartbeatPath, array $extraPostData = [], array $extraHeartbeat = []): void
{
    $app->group($groupPrefix, function ($group) use ($postDataPath, $outputsStatePath, $heartbeatPath, $extraPostData, $extraHeartbeat) {
        $group->post($postDataPath, [PostDataController::class, 'handle']);
        $group->get($outputsStatePath, [OutputController::class, 'getOutputsState']);
        $group->post($heartbeatPath, [HeartbeatController::class, 'handle']);
        foreach ($extraPostData as $path) {
            $group->post($path, [PostDataController::class, 'handle']);
        }
        foreach ($extraHeartbeat as $path) {
            $group->post($path, [HeartbeatController::class, 'handle']);
        }
    })->add(new EnvironmentMiddleware($env));
}

/**
 * Enregistre les routes protégées FFP3 (dashboard, tide-stats, export, contrôle) pour un environnement.
 */
function registerFfp3ProtectedRoutes($app, array $routes, string $env, $applyAuth): void
{
    $app->group('', function ($group) use ($routes) {
        if (isset($routes['supervision'])) {
            $group->get($routes['supervision'], [SupervisionController::class, 'show']);
        }
        $group->get($routes['dashboard'], [DashboardController::class, 'show']);
        $group->get($routes['export'], [ExportController::class, 'downloadCsv']);
        if (isset($routes['export_legacy'])) {
            $group->get($routes['export_legacy'], [ExportController::class, 'downloadCsv']);
        }
        $group->map(['GET', 'POST'], $routes['tide_stats'], [TideStatsController::class, 'show']);
        $group->get($routes['control'], [OutputController::class, 'showInterface']);
        $group->get($routes['toggle'], [OutputController::class, $routes['toggle_method']]);
        $group->post($routes['parameters'], [OutputController::class, 'updateParameters']);
        $group->post($routes['trigger_ota'], [OutputController::class, 'triggerOtaCheck']);
        $group->get($routes['board_status'], [OutputController::class, 'getBoardStatus']);
        if (isset($routes['admin_clear'])) {
            $group->get($routes['admin_clear'], [CacheController::class, 'clearCache']);
        }
        if (isset($routes['admin_clear_page'])) {
            $group->get($routes['admin_clear_page'], [CacheController::class, 'clearCachePage']);
        }
        if (isset($routes['admin_deploy'])) {
            $group->get($routes['admin_deploy'], [CacheController::class, 'showDeployScript']);
        }
    })->add(new EnvironmentMiddleware($env))->add($applyAuth);
}

/**
 * Enregistre les redirections 301 depuis la config.
 */
function registerRedirects($app, array $redirects, string $basePath): void
{
    foreach ($redirects as [$from, $to, $methods]) {
        $location = $basePath . $to;
        $handler = fn($req, $res) => $res->withHeader('Location', $location)->withStatus(301);
        if ($methods === ['GET', 'POST']) {
            $app->map(['GET', 'POST'], $from, $handler);
        } else {
            $app->get($from, $handler);
        }
    }
}

/**
 * Enregistre une route de fichier statique (whitelist).
 */
function registerAssetRoute($app, string $path, array $allowedFiles, string $contentType, string $subDir = ''): void
{
    $app->get($path, function (Request $request, Response $response, array $args) use ($allowedFiles, $contentType, $subDir) {
        $filename = $args['filename'];
        if (!in_array($filename, $allowedFiles)) {
            return $response->withStatus(404);
        }
        $filePath = __DIR__ . '/assets/' . ($subDir ? $subDir . '/' : '') . $filename;
        if (file_exists($filePath)) {
            $response->getBody()->write(file_get_contents($filePath));
            return $response->withHeader('Content-Type', $contentType);
        }
        return $response->withStatus(404);
    });
}

/**
 * Enregistre les routes MSP1 ou N3PP (prod ou test). $pathPrefix = msp1|msp1-test|n3pp|n3pp-test.
 */
function registerIotModuleRoutes($app, string $pathPrefix, string $env, array $config): void
{
    $module = $config['module'];
    $dataController = $config['data_controller'];
    $dataMethod = $config['data_method'];
    $outputController = $config['output_controller'];
    $realtimeController = $config['realtime_controller'];
    $postDataController = $config['post_data_controller'];
    $hasParameters = $config['has_parameters'] ?? false;
    $skipDataRoute = $config['skip_data_route'] ?? false;
    $skipControlRoutes = $config['skip_control_routes'] ?? false;
    $skipPostDataShortPath = $config['skip_post_data_short_path'] ?? false; // évite doublon /msp1datas/ (enregistré par prod)

    $callback = function ($group) use ($pathPrefix, $module, $dataController, $dataMethod, $outputController, $realtimeController, $postDataController, $hasParameters, $skipDataRoute, $skipControlRoutes, $skipPostDataShortPath) {
        $group->post("/{$pathPrefix}/{$module}datas/post-{$module}-data.php", [$postDataController, 'handle']);
        if (!$skipPostDataShortPath) {
            $group->post("/{$module}datas/post-{$module}-data.php", [$postDataController, 'handle']);
        }
        if (!$skipDataRoute) {
            $group->map(['GET', 'POST'], "/{$pathPrefix}/{$module}datas/{$module}-data.php", [$dataController, $dataMethod]);
        }
        $group->get("/{$pathPrefix}/{$module}control/{$module}-outputs-action.php", [$outputController, 'getState']);
        $group->post("/{$pathPrefix}/{$module}control/{$module}-outputs-action.php", [$outputController, 'setOutput']);
        if (!$skipControlRoutes) {
            $group->get("/{$pathPrefix}/{$module}control/", [$outputController, 'showControlPage']);
            $group->get("/{$pathPrefix}/{$module}control/index.php", [$outputController, 'showControlPage']);
        }
        $group->get("/{$pathPrefix}/api/realtime/sensors/latest", [$realtimeController, 'getLatestSensors']);
        $group->get("/{$pathPrefix}/api/realtime/sensors/since/{timestamp}", [$realtimeController, 'getSensorsSince']);
        $group->get("/{$pathPrefix}/api/realtime/outputs/state", [$realtimeController, 'getOutputsState']);
        $group->get("/{$pathPrefix}/api/realtime/system/health", [$realtimeController, 'getSystemHealth']);
        $group->get("/{$pathPrefix}/api/realtime/alerts/active", [$realtimeController, 'getActiveAlerts']);
        $group->get("/{$pathPrefix}/api/outputs/state", [$realtimeController, 'getOutputsState']);
        $group->map(['GET', 'POST'], "/{$pathPrefix}/api/outputs/toggle", [$outputController, 'toggleOutput']);
        if ($hasParameters) {
            $group->post("/{$pathPrefix}/api/outputs/parameters", [$outputController, 'updateParameters']);
        }
    };

    $app->group('', $callback)->add(new EnvironmentMiddleware($env));
}

/**
 * Enregistre les routes de contrôle aquaponie (toggle, parameters, ota, board status) sous /ffp3.
 */
function registerFfp3ControlRoutes($app, string $outputsPrefix, string $toggleMethod, string $env, $applyAuth): void
{
    $app->group('/ffp3', function ($group) use ($outputsPrefix, $toggleMethod) {
        $group->get($outputsPrefix . '/toggle', [OutputController::class, $toggleMethod]);
        $group->post($outputsPrefix . '/parameters', [OutputController::class, 'updateParameters']);
        $group->post($outputsPrefix . '/trigger-ota-check', [OutputController::class, 'triggerOtaCheck']);
        $group->get($outputsPrefix . '/board/{board}/status', [OutputController::class, 'getBoardStatus']);
    })->add(new EnvironmentMiddleware($env))
      ->add($applyAuth);
}

// ====================================================================
// Routes API PUBLIQUES (utilisées par pages aquaponie et firmware ESP32)
// ====================================================================

// API Temps Réel FFP3 - PUBLIQUES
registerRealtimeRoutes($app, '/api/realtime', 'prod', ['/api/health']);
registerRealtimeRoutes($app, '/api/realtime-test', 'test', ['/api/health-test', '/api/realtime/system/health-test']);
registerRealtimeRoutes($app, '/api/realtime3-test', 'test3');
registerRealtimeRoutes($app, '/api/realtime3', 's3');

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

// Endpoints Firmware ESP32 - PUBLICS (factorisés)
registerFirmwareRoutes($app, '', 'prod', '/post-data', '/api/outputs/state', '/heartbeat',
    ['/post-ffp3-data.php', '/ffp3datas/post-ffp3-data2.php'], ['/heartbeat.php']);
// Alias legacy LVGL_Widgets pour outputs state
$app->group('', function ($group) {
    $group->get('/ffp3control/ffp3-outputs-action2.php', [OutputController::class, 'getOutputsState']);
})->add(new EnvironmentMiddleware('prod'));

registerFirmwareRoutes($app, '', 'test', '/post-data-test', '/api/outputs-test/state', '/heartbeat-test',
    [], ['/heartbeat-test.php']);
registerFirmwareRoutes($app, '', 'test3', '/post-data3-test', '/api/outputs3-test/state', '/heartbeat3-test');
registerFirmwareRoutes($app, '', 's3', '/post-data3', '/api/outputs3/state', '/heartbeat3');

// ====================================================================
// Alias /ffp3/* : mêmes endpoints pour firmwares utilisant base URL /ffp3/
// ====================================================================
registerFirmwareRoutes($app, '/ffp3', 'prod', '/post-data', '/api/outputs/state', '/heartbeat');
registerFirmwareRoutes($app, '/ffp3', 'test', '/post-data-test', '/api/outputs-test/state', '/heartbeat-test');
registerFirmwareRoutes($app, '/ffp3', 'test3', '/post-data3-test', '/api/outputs3-test/state', '/heartbeat3-test');
registerFirmwareRoutes($app, '/ffp3', 's3', '/post-data3', '/api/outputs3/state', '/heartbeat3');

// ====================================================================
// Contrôle aquaponie sous /ffp3 (toggle, parameters) — protégé par auth
// ====================================================================
registerFfp3ControlRoutes($app, '/api/outputs', 'toggleOutput', 'prod', $applyAuth);
registerFfp3ControlRoutes($app, '/api/outputs-test', 'toggleOutputTest', 'test', $applyAuth);
registerFfp3ControlRoutes($app, '/api/outputs3-test', 'toggleOutputTest3', 'test3', $applyAuth);
registerFfp3ControlRoutes($app, '/api/outputs3', 'toggleOutputS3', 's3', $applyAuth);

// ====================================================================
// Routes FFP3 protégées par environnement (dashboard, tide-stats, export, contrôle)
// ====================================================================
$ffp3RoutesConfig = [
    'prod' => [
        'supervision' => '/supervision',
        'dashboard' => '/dashboard',
        'export' => '/export-data',
        'export_legacy' => '/export-data.php',
        'tide_stats' => '/tide-stats',
        'control' => '/aquaponie-control',
        'toggle' => '/api/outputs/toggle',
        'toggle_method' => 'toggleOutput',
        'parameters' => '/api/outputs/parameters',
        'trigger_ota' => '/api/outputs/trigger-ota-check',
        'board_status' => '/api/outputs/board/{board}/status',
        'admin_clear' => '/admin/clear-cache',
        'admin_clear_page' => '/admin/clear-cache-page',
        'admin_deploy' => '/admin/deploy-script',
    ],
    'test' => [
        'dashboard' => '/dashboard-test',
        'export' => '/export-data-test',
        'tide_stats' => '/tide-stats-test',
        'control' => '/aquaponie-control-test',
        'toggle' => '/api/outputs-test/toggle',
        'toggle_method' => 'toggleOutputTest',
        'parameters' => '/api/outputs-test/parameters',
        'trigger_ota' => '/api/outputs-test/trigger-ota-check',
        'board_status' => '/api/outputs-test/board/{board}/status',
        'admin_clear' => '/admin/clear-cache-test',
        'admin_clear_page' => '/admin/clear-cache-page-test',
    ],
    'test3' => [
        'dashboard' => '/dashboard3-test',
        'export' => '/export-data3-test',
        'tide_stats' => '/tide-stats3-test',
        'control' => '/aquamobile-control-test',
        'toggle' => '/api/outputs3-test/toggle',
        'toggle_method' => 'toggleOutputTest3',
        'parameters' => '/api/outputs3-test/parameters',
        'trigger_ota' => '/api/outputs3-test/trigger-ota-check',
        'board_status' => '/api/outputs3-test/board/{board}/status',
        'admin_clear' => '/admin/clear-cache3-test',
        'admin_clear_page' => '/admin/clear-cache-page3-test',
    ],
    's3' => [
        'dashboard' => '/dashboard3',
        'export' => '/export-data3',
        'tide_stats' => '/tide-stats3',
        'control' => '/aquamobile-control',
        'toggle' => '/api/outputs3/toggle',
        'toggle_method' => 'toggleOutputS3',
        'parameters' => '/api/outputs3/parameters',
        'trigger_ota' => '/api/outputs3/trigger-ota-check',
        'board_status' => '/api/outputs3/board/{board}/status',
        'admin_clear' => '/admin/clear-cache3',
        'admin_clear_page' => '/admin/clear-cache-page3',
    ],
];

foreach ($ffp3RoutesConfig as $env => $routes) {
    registerFfp3ProtectedRoutes($app, $routes, $env, $applyAuth);
}

// Route additionnelle prod : toggle-test (alias)
$app->group('', function ($group) {
    $group->get('/api/outputs/toggle-test', [OutputController::class, 'toggleOutputTest']);
})->add(new EnvironmentMiddleware('prod'))->add($applyAuth);

// ====================================================================
// Fichiers statiques GLOBAUX (config centralisée)
// ====================================================================
registerAssetRoute($app, '/assets/js/{filename}', $routesConfigFull['asset_js'], 'application/javascript', 'js');
registerAssetRoute($app, '/assets/css/{filename}', $routesConfigFull['asset_css'], 'text/css', 'css');
registerAssetRoute($app, '/assets/icons/{filename}', $routesConfigFull['asset_icons'], 'image/png', 'icons');

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
// Routes MSP1 — station meteo (Le potager)
// ====================================================================

// Nouvelles routes unifiées
$app->map(['GET', 'POST'], '/meteo', [($useLocalDataFallback ? LocalDataPagesController::class : MspDataController::class), $useLocalDataFallback ? 'showMsp1' : 'show']);
$app->get('/meteo-control', [MspOutputController::class, 'showControlPage']);
$app->map(['GET', 'POST'], '/serre', [($useLocalDataFallback ? LocalDataPagesController::class : N3ppDataController::class), $useLocalDataFallback ? 'showN3pp' : 'show']);
$app->get('/serre-control', [N3ppOutputController::class, 'showControlPage']);

// Redirections 301 MSP1 / N3PP
$app->get('/msp1_data', fn($rq, $rs) => $rs->withHeader('Location', $basePath . '/meteo')->withStatus(301));
$app->map(['GET', 'POST'], '/msp1/msp1datas/msp1-data.php', function (Request $request, Response $response) use ($basePath) {
    $query = $request->getUri()->getQuery();
    return $response->withHeader('Location', $basePath . '/meteo' . ($query ? '?' . $query : ''))->withStatus(301);
});
$app->get('/msp1/msp1control/', fn($rq, $rs) => $rs->withHeader('Location', $basePath . '/meteo-control')->withStatus(301));
$app->get('/msp1/msp1control/index.php', fn($rq, $rs) => $rs->withHeader('Location', $basePath . '/meteo-control')->withStatus(301));
$app->map(['GET', 'POST'], '/n3pp/n3ppdatas/n3pp-data.php', function (Request $request, Response $response) use ($basePath) {
    $query = $request->getUri()->getQuery();
    return $response->withHeader('Location', $basePath . '/serre' . ($query ? '?' . $query : ''))->withStatus(301);
});
$app->get('/n3pp/n3ppcontrol/', fn($rq, $rs) => $rs->withHeader('Location', $basePath . '/serre-control')->withStatus(301));
$app->get('/n3pp/n3ppcontrol/index.php', fn($rq, $rs) => $rs->withHeader('Location', $basePath . '/serre-control')->withStatus(301));

// Routes MSP1 et N3PP (prod + test) factorisées
$msp1DataController = $useLocalDataFallback ? LocalDataPagesController::class : MspDataController::class;
$msp1DataMethod = $useLocalDataFallback ? 'showMsp1' : 'show';
registerIotModuleRoutes($app, 'msp1', 'prod', [
    'module' => 'msp1',
    'data_controller' => $msp1DataController,
    'data_method' => $msp1DataMethod,
    'output_controller' => MspOutputController::class,
    'realtime_controller' => MspRealtimeApiController::class,
    'post_data_controller' => MspPostDataController::class,
    'has_parameters' => false,
    'skip_data_route' => true,
    'skip_control_routes' => true, // redirections 301 manuelles vers /meteo et /meteo-control
]);
registerIotModuleRoutes($app, 'msp1-test', 'msp_test', [
    'module' => 'msp1',
    'data_controller' => $msp1DataController,
    'data_method' => $msp1DataMethod,
    'output_controller' => MspOutputController::class,
    'realtime_controller' => MspRealtimeApiController::class,
    'post_data_controller' => MspPostDataController::class,
    'has_parameters' => false,
    'skip_post_data_short_path' => true,
]);

$n3ppDataController = $useLocalDataFallback ? LocalDataPagesController::class : N3ppDataController::class;
$n3ppDataMethod = $useLocalDataFallback ? 'showN3pp' : 'show';
registerIotModuleRoutes($app, 'n3pp', 'prod', [
    'module' => 'n3pp',
    'data_controller' => $n3ppDataController,
    'data_method' => $n3ppDataMethod,
    'output_controller' => N3ppOutputController::class,
    'realtime_controller' => N3ppRealtimeApiController::class,
    'post_data_controller' => N3ppPostDataController::class,
    'has_parameters' => true,
    'skip_data_route' => true,
    'skip_control_routes' => true, // redirections 301 manuelles vers /serre et /serre-control
]);
registerIotModuleRoutes($app, 'n3pp-test', 'n3pp_test', [
    'module' => 'n3pp',
    'data_controller' => $n3ppDataController,
    'data_method' => $n3ppDataMethod,
    'output_controller' => N3ppOutputController::class,
    'realtime_controller' => N3ppRealtimeApiController::class,
    'post_data_controller' => N3ppPostDataController::class,
    'has_parameters' => true,
    'skip_post_data_short_path' => true,
]);

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
