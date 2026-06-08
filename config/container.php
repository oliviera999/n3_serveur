<?php

declare(strict_types=1);

use DI\ContainerBuilder;

// Charger les variables d'environnement
App\Config\Env::load();

$containerBuilder = new ContainerBuilder();

// Activer la compilation du container en production pour meilleures performances
if (($_ENV['ENV'] ?? 'prod') === 'prod') {
    $containerBuilder->enableCompilation(__DIR__ . '/../var/cache/di');
    $containerBuilder->writeProxiesToFile(true, __DIR__ . '/../var/cache/di/proxies');
}

// Charger les définitions
$containerBuilder->addDefinitions(__DIR__ . '/dependencies.php');

// Build et retourner le container
return $containerBuilder->build();
