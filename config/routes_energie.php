<?php

/**
 * Routes ENERGIE — banc de mesure INA226 (panneau solaire / batterie 12 V / conso).
 * Inclus depuis public/index.php — la variable $app doit être en scope.
 *
 * Famille « mesure seule » : page publique + ingestion firmware + API temps réel,
 * sans table outputs ni heartbeat. Chaque environnement a son préfixe d'URL :
 *   - prod          → /energie       (tables energieData)
 *   - energie_test  → /energie-test  (tables energieDataTest, banc de test)
 *
 * Auth : /…/post-data = HMAC (X-Sig-* ou timestamp+signature) avec repli api_key
 * (EnergiePostDataController) ; page et API realtime publiques (lecture seule).
 * Contrat : docs/API_ENERGIE.md.
 */

use App\Controller\Energie\EnergieDataController;
use App\Controller\Energie\EnergiePostDataController;
use App\Controller\Energie\EnergieRealtimeApiController;
use App\Middleware\EnvironmentMiddleware;

// Garde d'inclusion : $app est fourni par le scope appelant (public/index.php, tests).
if (!isset($app) || !$app instanceof \Slim\App) {
    throw new \LogicException('config/routes_energie.php doit être inclus avec une instance Slim\\App dans $app.');
}

foreach (['prod' => 'energie', 'energie_test' => 'energie-test'] as $energieEnv => $energiePrefix) {
    $app->group('', function ($group) use ($energiePrefix) {
        // Page publique (POST = formulaire de période / export CSV, jeton CSRF vérifié par DateRangeExtractor).
        $group->map(['GET', 'POST'], "/{$energiePrefix}", [EnergieDataController::class, 'show']);
        // Ingestion firmware (form-urlencoded).
        $group->post("/{$energiePrefix}/post-data", [EnergiePostDataController::class, 'handle']);
        // API temps réel (polling UI).
        $group->get("/{$energiePrefix}/api/realtime/sensors/latest", [EnergieRealtimeApiController::class, 'getLatestSensors']);
        $group->get("/{$energiePrefix}/api/realtime/sensors/since/{timestamp}", [EnergieRealtimeApiController::class, 'getSensorsSince']);
        $group->get("/{$energiePrefix}/api/realtime/outputs/state", [EnergieRealtimeApiController::class, 'getOutputsState']);
        $group->get("/{$energiePrefix}/api/realtime/system/health", [EnergieRealtimeApiController::class, 'getSystemHealth']);
        $group->get("/{$energiePrefix}/api/realtime/alerts/active", [EnergieRealtimeApiController::class, 'getActiveAlerts']);
    })->add(new EnvironmentMiddleware($energieEnv));
}
