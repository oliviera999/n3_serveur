<?php

/**
 * Fonctions helper pour l'enregistrement des routes.
 * (force invalidation cache - 2026-03-10)
 * Inclus depuis public/index.php avant les fichiers routes_*.php
 */

use App\Controller\Ffp3\CacheController;
use App\Controller\Ffp3\DashboardController;
use App\Controller\Ffp3\ExportController;
use App\Controller\Ffp3\HeartbeatController;
use App\Controller\Ffp3\OutputController;
use App\Controller\Ffp3\PostDataController;
use App\Controller\Ffp3\RealtimeApiController;
use App\Controller\Ffp3\TideStatsController;
use App\Controller\SupervisionController;
use App\Middleware\EnvironmentMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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
 *
 * @param \Closure $applyAuth
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
        $group->post($routes['toggle'], [OutputController::class, 'toggleOutput']);
        if (isset($routes['trigger_feed'])) {
            $group->post($routes['trigger_feed'], [OutputController::class, 'triggerManualFeed']);
        }
        $group->post($routes['parameters'], [OutputController::class, 'updateParameters']);
        $group->post(str_replace('/parameters', '/notification-policy', $routes['parameters']), [OutputController::class, 'saveNotificationPolicy']);
        $group->post(str_replace('/parameters', '/test-mail', $routes['parameters']), [OutputController::class, 'sendTestMail']);
        $group->post($routes['trigger_ota'], [OutputController::class, 'triggerOtaCheck']);
        $group->get($routes['board_status'], [OutputController::class, 'getBoardStatus']);
        if (isset($routes['admin_clear'])) {
            // POST (écriture d'état : vide les caches) protégé par CSRF, plus un GET
            // en effet de bord. Voir CsrfMiddleware (motif clear-cache) + boutons front.
            $group->post($routes['admin_clear'], [CacheController::class, 'clearCache']);
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
        $handler = fn ($req, $res) => $res->withHeader('Location', $location)->withStatus(301);
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
        $filePath = __DIR__ . '/../public/assets/' . ($subDir ? $subDir . '/' : '') . $filename;
        if (!file_exists($filePath)) {
            return $response->withStatus(404);
        }

        // Cache navigateur : ces fichiers changent au déploiement (version d'app) ;
        // on autorise un cache long avec revalidation conditionnelle (ETag / Last-Modified).
        $mtime = (int) filemtime($filePath);
        $size = (int) filesize($filePath);
        $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        $response = $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Cache-Control', 'public, max-age=2592000')
            ->withHeader('ETag', $etag)
            ->withHeader('Last-Modified', $lastModified);

        // Revalidation : si le client a déjà une copie à jour, renvoyer 304 sans corps.
        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
        $ifModifiedSince = trim($request->getHeaderLine('If-Modified-Since'));
        if (($ifNoneMatch !== '' && $ifNoneMatch === $etag)
            || ($ifModifiedSince !== '' && @strtotime($ifModifiedSince) >= $mtime)) {
            return $response->withStatus(304);
        }

        $response->getBody()->write((string) file_get_contents($filePath));
        return $response;
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
    $heartbeatController = $config['heartbeat_controller'] ?? null;
    $hasParameters = $config['has_parameters'] ?? false;
    $skipDataRoute = $config['skip_data_route'] ?? false;
    $skipControlRoutes = $config['skip_control_routes'] ?? false;
    $skipPostDataShortPath = $config['skip_post_data_short_path'] ?? false;

    $callback = function ($group) use ($pathPrefix, $module, $dataController, $dataMethod, $outputController, $realtimeController, $postDataController, $heartbeatController, $hasParameters, $skipDataRoute, $skipControlRoutes, $skipPostDataShortPath) {
        $group->post("/{$pathPrefix}/{$module}datas/post-{$module}-data.php", [$postDataController, 'handle']);
        // Alias moderne (Phase 4 audit 2026-05) : URL compacte, contrat identique (HMAC ou api_key).
        $group->post("/{$pathPrefix}/post-data", [$postDataController, 'handle']);
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
        // POST uniquement : toggle est une ecriture d'etat. En GET, CsrfMiddleware
        // considere la methode comme sure et laisse passer -> CSRF possible sur une
        // session admin (ex. <img src=".../api/outputs/toggle?gpio=16&state=1">).
        // L'UI (control-actions.js) emet deja un POST + jeton CSRF. Aligne sur FFP3.
        $group->post("/{$pathPrefix}/api/outputs/toggle", [$outputController, 'toggleOutput']);
        if ($hasParameters) {
            $group->post("/{$pathPrefix}/api/outputs/parameters", [$outputController, 'updateParameters']);
            $group->post("/{$pathPrefix}/api/outputs/notification-policy", [$outputController, 'saveNotificationPolicy']);
            $group->post("/{$pathPrefix}/api/outputs/test-mail", [$outputController, 'sendTestMail']);
        }
        // Heartbeat moderne (auth HMAC ou api_key) — table dediee msp1Heartbeat / n3ppHeartbeat.
        if ($heartbeatController !== null) {
            $group->post("/{$pathPrefix}/heartbeat", [$heartbeatController, 'handle']);
        }
    };

    $app->group('', $callback)->add(new EnvironmentMiddleware($env));
}

/**
 * Enregistre les routes de contrôle aquaponie (toggle, parameters, ota, board status) sous /ffp3.
 *
 * @param \Closure $applyAuth
 */
function registerFfp3ControlRoutes($app, string $outputsPrefix, string $toggleMethod, string $env, $applyAuth): void
{
    $app->group('/ffp3', function ($group) use ($outputsPrefix, $toggleMethod) {
        $group->post($outputsPrefix . '/toggle', [OutputController::class, $toggleMethod]);
        $group->post($outputsPrefix . '/trigger-feed', [OutputController::class, 'triggerManualFeed']);
        $group->post($outputsPrefix . '/parameters', [OutputController::class, 'updateParameters']);
        $group->post($outputsPrefix . '/notification-policy', [OutputController::class, 'saveNotificationPolicy']);
        $group->post($outputsPrefix . '/test-mail', [OutputController::class, 'sendTestMail']);
        $group->post($outputsPrefix . '/trigger-ota-check', [OutputController::class, 'triggerOtaCheck']);
        $group->get($outputsPrefix . '/board/{board}/status', [OutputController::class, 'getBoardStatus']);
    })->add(new EnvironmentMiddleware($env))
      ->add($applyAuth);
}
