<?php

/**
 * Routes FFP3 (aquaponie) : realtime, firmware, protected, control.
 * Inclus depuis public/index.php — variables $app, $applyAuth, $basePath, $useLocalDataFallback doivent être en scope.
 */

use App\Controller\Ffp3\OutputController;
use App\Controller\Ffp3\CacheController;
use App\Controller\Ffp3\DashboardController;
use App\Controller\Ffp3\ExportController;
use App\Controller\Ffp3\TideStatsController;
use App\Controller\SupervisionController;
use App\Middleware\EnvironmentMiddleware;

// API Temps Réel FFP3 - PUBLIQUES
registerRealtimeRoutes($app, '/api/realtime', 'prod', ['/api/health']);
registerRealtimeRoutes($app, '/api/realtime-test', 'test', ['/api/health-test', '/api/realtime/system/health-test']);
registerRealtimeRoutes($app, '/api/realtime3-test', 'test3');
registerRealtimeRoutes($app, '/api/realtime3', 's3');
registerRealtimeRoutes($app, '/api/realtime-s3-test', 's3test');

// Endpoints Firmware ESP32 - PUBLICS (factorisés)
registerFirmwareRoutes($app, '', 'prod', '/post-data', '/api/outputs/state', '/heartbeat',
    ['/post-ffp3-data.php', '/ffp3datas/post-ffp3-data2.php'], ['/heartbeat.php']);
$app->group('', function ($group) {
    $group->get('/ffp3control/ffp3-outputs-action2.php', [OutputController::class, 'getOutputsState']);
})->add(new EnvironmentMiddleware('prod'));

registerFirmwareRoutes($app, '', 'test', '/post-data-test', '/api/outputs-test/state', '/heartbeat-test',
    [], ['/heartbeat-test.php']);
registerFirmwareRoutes($app, '', 'test3', '/post-data3-test', '/api/outputs3-test/state', '/heartbeat3-test');
registerFirmwareRoutes($app, '', 's3', '/post-data3', '/api/outputs3/state', '/heartbeat3');
registerFirmwareRoutes($app, '', 's3test', '/post-data-s3-test', '/api/outputs-s3-test/state', '/heartbeat-s3-test');

// Alias /ffp3/* : mêmes endpoints pour firmwares utilisant base URL /ffp3/
registerFirmwareRoutes($app, '/ffp3', 'prod', '/post-data', '/api/outputs/state', '/heartbeat');
registerFirmwareRoutes($app, '/ffp3', 'test', '/post-data-test', '/api/outputs-test/state', '/heartbeat-test');
registerFirmwareRoutes($app, '/ffp3', 'test3', '/post-data3-test', '/api/outputs3-test/state', '/heartbeat3-test');
registerFirmwareRoutes($app, '/ffp3', 's3', '/post-data3', '/api/outputs3/state', '/heartbeat3');
registerFirmwareRoutes($app, '/ffp3', 's3test', '/post-data-s3-test', '/api/outputs-s3-test/state', '/heartbeat-s3-test');

// Contrôle aquaponie sous /ffp3 (toggle, parameters) — protégé par auth
registerFfp3ControlRoutes($app, '/api/outputs', 'toggleOutput', 'prod', $applyAuth);
registerFfp3ControlRoutes($app, '/api/outputs-test', 'toggleOutput', 'test', $applyAuth);
registerFfp3ControlRoutes($app, '/api/outputs3-test', 'toggleOutput', 'test3', $applyAuth);
registerFfp3ControlRoutes($app, '/api/outputs3', 'toggleOutput', 's3', $applyAuth);
registerFfp3ControlRoutes($app, '/api/outputs-s3-test', 'toggleOutput', 's3test', $applyAuth);

// Routes FFP3 protégées par environnement (dashboard, tide-stats, export, contrôle)
$ffp3RoutesConfig = [
    'prod' => [
        'supervision' => '/supervision',
        'dashboard' => '/dashboard',
        'export' => '/export-data',
        'export_legacy' => '/export-data.php',
        'tide_stats' => '/tide-stats',
        'control' => '/aquaponie-control',
        'toggle' => '/api/outputs/toggle',
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
        'parameters' => '/api/outputs3/parameters',
        'trigger_ota' => '/api/outputs3/trigger-ota-check',
        'board_status' => '/api/outputs3/board/{board}/status',
        'admin_clear' => '/admin/clear-cache3',
        'admin_clear_page' => '/admin/clear-cache-page3',
    ],
    's3test' => [
        'dashboard' => '/dashboard-s3-test',
        'export' => '/export-data-s3-test',
        'tide_stats' => '/tide-stats-s3-test',
        'control' => '/aquamobile-control-s3-test',
        'toggle' => '/api/outputs-s3-test/toggle',
        'parameters' => '/api/outputs-s3-test/parameters',
        'trigger_ota' => '/api/outputs-s3-test/trigger-ota-check',
        'board_status' => '/api/outputs-s3-test/board/{board}/status',
        'admin_clear' => '/admin/clear-cache-s3-test',
        'admin_clear_page' => '/admin/clear-cache-page-s3-test',
    ],
];

foreach ($ffp3RoutesConfig as $env => $routes) {
    registerFfp3ProtectedRoutes($app, $routes, $env, $applyAuth);
}

// Route additionnelle prod : toggle-test (alias)
$app->group('', function ($group) {
    $group->get('/api/outputs/toggle-test', [OutputController::class, 'toggleOutput']);
})->add(new EnvironmentMiddleware('prod'))->add($applyAuth);
