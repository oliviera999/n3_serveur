<?php

/**
 * Routes MSP1 (météo) et N3PP (serre).
 * Utilise config/modules.php pour la configuration centralisée.
 * Inclus depuis public/index.php — variables $app, $msp1DataController, $msp1DataMethod,
 * $n3ppDataController, $n3ppDataMethod, $basePath doivent être en scope.
 */


$modulesConfig = require __DIR__ . '/modules.php';

// Injecter les controllers/méthodes selon le fallback local
$modulesConfig['msp1']['data_controller'] = $msp1DataController;
$modulesConfig['msp1']['data_method'] = $msp1DataMethod;
$modulesConfig['msp1_test']['data_controller'] = $msp1DataController;
$modulesConfig['msp1_test']['data_method'] = $msp1DataMethod;
$modulesConfig['n3pp']['data_controller'] = $n3ppDataController;
$modulesConfig['n3pp']['data_method'] = $n3ppDataMethod;
$modulesConfig['n3pp_test']['data_controller'] = $n3ppDataController;
$modulesConfig['n3pp_test']['data_method'] = $n3ppDataMethod;

// Enregistrer les routes pour chaque module
foreach (['msp1', 'msp1_test', 'n3pp', 'n3pp_test'] as $key) {
    $config = $modulesConfig[$key];
    registerIotModuleRoutes($app, $config['pathPrefix'], $config['env'], $config);
}
