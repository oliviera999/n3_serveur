<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Env;
use App\Controller\AquaponieController;
use App\Controller\AuthController;
use App\Controller\CacheController;
use App\Controller\DashboardController;
use App\Controller\ExportController;
use App\Controller\HeartbeatController;
use App\Controller\HomeController;
use App\Controller\OutputController;
use App\Controller\PostDataController;
use App\Controller\RealtimeApiController;
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

// Forcer le chemin base pour être identique à l'ancien (dossier parent de /public)
// Détection du basePath selon le point d'entrée utilisé
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);

if (strpos($scriptName, '/public/index.php') !== false) {
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

// ====================================================================
// Middleware de gestion d'erreurs personnalisé
// ====================================================================
$app->add($container->get(\App\Middleware\ErrorHandlerMiddleware::class));

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
        '/post-data',              // Endpoints firmware ESP32
        '/post-data-test',
        '/post-data3',
        '/post-data3-test',
        '/heartbeat',              // Heartbeat firmware ESP32
        '/heartbeat-test',
        '/heartbeat3',
        '/heartbeat3-test',
        '/ping'                    // Diagnostic latence (GET/POST, réponse minimale)
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
    if (!$isPublic && preg_match('#^/api/outputs(-test|3-test|3)?/state$#', $path)) {
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
        '/api/outputs3-test'
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
$app->get('/index.html', function (Request $request, Response $response) {
    return $response->withHeader('Location', '/ffp3/')->withStatus(301);
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
$app->group('', function ($group) {
    $group->map(['GET', 'POST'], '/aquaponie', [AquaponieController::class, 'showAlt']);
    $group->map(['GET', 'POST'], '/aquaponie-alt', [AquaponieController::class, 'show']);
    $group->get('/ffp3-data', function (Request $request, Response $response) {
        return $response->withHeader('Location', '/ffp3/aquaponie')->withStatus(301);
    }); // Redirection legacy vers aquaponie
})->add(new EnvironmentMiddleware('prod'));

// TEST
$app->group('', function ($group) {
    $group->map(['GET', 'POST'], '/aquaponie-test', [AquaponieController::class, 'showAlt']);
    $group->map(['GET', 'POST'], '/aquaponie-alt-test', [AquaponieController::class, 'show']);
})->add(new EnvironmentMiddleware('test'));

// TEST3
$app->group('', function ($group) {
    $group->map(['GET', 'POST'], '/aquamobile-test', [AquaponieController::class, 'showAlt']);
    $group->map(['GET', 'POST'], '/aquamobile-alt-test', [AquaponieController::class, 'show']);
})->add(new EnvironmentMiddleware('test3'));

// S3 PROD
$app->group('', function ($group) {
    $group->map(['GET', 'POST'], '/aquamobile', [AquaponieController::class, 'showAlt']);
    $group->map(['GET', 'POST'], '/aquamobile-alt', [AquaponieController::class, 'show']);
})->add(new EnvironmentMiddleware('s3'));

// Page Caractéristiques du module FFP3 - PUBLIQUE (pas de variante env)
$app->get('/aquaponie-description', [AquaponieController::class, 'showDescription']);

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
    $group->get('/api/outputs/state', [OutputController::class, 'getOutputsState']);
    $group->post('/heartbeat', [HeartbeatController::class, 'handle']);
    $group->post('/heartbeat.php', function (Request $request, Response $response) {
        return $response->withHeader('Location', '/ffp3/heartbeat')->withStatus(301);
    }); // Redirection legacy
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

    // ====================================================================
    // Fichiers statiques PROD (fallback si serveur web ne les sert pas)
    // ====================================================================
    $group->get('/manifest.json', function (Request $request, Response $response) {
        $manifestPath = __DIR__ . '/manifest.json';
        if (file_exists($manifestPath)) {
            $response->getBody()->write(file_get_contents($manifestPath));
            return $response->withHeader('Content-Type', 'application/json');
        }
        return $response->withStatus(404);
    });
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
        'control-values-updater.js',
        'control-sync.js', 
        'chart-updater.js',
        'stats-updater.js',
        'realtime-updater.js',
        'toast-notifications.js',
        'pwa-init.js',
        'mobile-gestures.js'
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
        'control-styles.css',
        'mobile-optimized.css',
        'realtime-styles.css',
        'login-styles.css'
    ];
    
    if (!in_array($filename, $allowedFiles)) {
        return $response->withStatus(404);
    }
    
    $filePath = __DIR__ . '/assets/css/' . $filename;
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
        error_log(sprintf('[FFP3 404] %s %s', $request->getMethod(), $uri));
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write('Not found.');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
);

$app->run();
