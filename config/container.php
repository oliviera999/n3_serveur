<?php

declare(strict_types=1);

use DI\ContainerBuilder;

// Charger les variables d'environnement
App\Config\Env::load();

$containerBuilder = new ContainerBuilder();

// Autowiring : actif par défaut dans PHP-DI 7, rendu explicite pour la lisibilité.
// La plupart des classes (contrôleurs, services, repositories…) sont câblées
// automatiquement ; config/dependencies.php ne contient que les exceptions
// (PDO, lecture de scalaires / environnement / fichiers de config).
$containerBuilder->useAutowiring(true);

// Activer la compilation du container en production pour de meilleures performances.
// ⚠️ `s3` est de la PRODUCTION (TableConfig::isTest() === false) : la comparaison
// littérale à 'prod' privait S3 de la compilation. On s'appuie donc sur
// TableConfig::isTest() (source de vérité prod/test) plutôt qu'une chaîne en dur.
// Repli sûr : env inconnu/non défini → 'prod' (voir TableConfig), donc compilation active.
if (!App\Config\TableConfig::isTest()) {
    $containerBuilder->enableCompilation(__DIR__ . '/../var/cache/di');
    $containerBuilder->writeProxiesToFile(true, __DIR__ . '/../var/cache/di/proxies');
}

// Charger les définitions
$containerBuilder->addDefinitions(__DIR__ . '/dependencies.php');

// Build et retourner le container
return $containerBuilder->build();
