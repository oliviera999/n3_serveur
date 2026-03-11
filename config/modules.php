<?php

/**
 * Configuration centralisée des modules IoT (MSP1, N3PP).
 * Utilisé par config/routes_msp1_n3pp.php via registerIotModuleRoutes().
 *
 * Chaque module définit : pathPrefix, env, et les overrides par rapport au défaut.
 */

use App\Controller\Msp\MspDataController;
use App\Controller\Msp\MspOutputController;
use App\Controller\Msp\MspPostDataController;
use App\Controller\Msp\MspRealtimeApiController;
use App\Controller\N3pp\N3ppDataController;
use App\Controller\N3pp\N3ppOutputController;
use App\Controller\N3pp\N3ppPostDataController;
use App\Controller\N3pp\N3ppRealtimeApiController;

return [
    'msp1' => [
        'pathPrefix' => 'msp1',
        'env' => 'prod',
        'module' => 'msp1',
        'data_controller' => null, // injecté (MspDataController ou LocalDataPagesController)
        'data_method' => null,     // injecté ('show' ou 'showMsp1')
        'output_controller' => MspOutputController::class,
        'realtime_controller' => MspRealtimeApiController::class,
        'post_data_controller' => MspPostDataController::class,
        'has_parameters' => true,
        'skip_data_route' => true,
        'skip_control_routes' => true,
        'skip_post_data_short_path' => false,
    ],
    'msp1_test' => [
        'pathPrefix' => 'msp1-test',
        'env' => 'msp_test',
        'module' => 'msp1',
        'data_controller' => null,
        'data_method' => null,
        'output_controller' => MspOutputController::class,
        'realtime_controller' => MspRealtimeApiController::class,
        'post_data_controller' => MspPostDataController::class,
        'has_parameters' => true,
        'skip_data_route' => false,
        'skip_control_routes' => false,
        'skip_post_data_short_path' => true,
    ],
    'n3pp' => [
        'pathPrefix' => 'n3pp',
        'env' => 'prod',
        'module' => 'n3pp',
        'data_controller' => null,
        'data_method' => null,
        'output_controller' => N3ppOutputController::class,
        'realtime_controller' => N3ppRealtimeApiController::class,
        'post_data_controller' => N3ppPostDataController::class,
        'has_parameters' => true,
        'skip_data_route' => true,
        'skip_control_routes' => true,
        'skip_post_data_short_path' => false,
    ],
    'n3pp_test' => [
        'pathPrefix' => 'n3pp-test',
        'env' => 'n3pp_test',
        'module' => 'n3pp',
        'data_controller' => null,
        'data_method' => null,
        'output_controller' => N3ppOutputController::class,
        'realtime_controller' => N3ppRealtimeApiController::class,
        'post_data_controller' => N3ppPostDataController::class,
        'has_parameters' => true,
        'skip_data_route' => false,
        'skip_control_routes' => false,
        'skip_post_data_short_path' => true,
    ],
];
