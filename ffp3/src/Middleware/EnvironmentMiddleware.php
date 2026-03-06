<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\TableConfig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Middleware pour définir l'environnement (PROD/TEST)
 * 
 * Permet de factoriser le code des routes qui nécessitent un environnement spécifique
 */
class EnvironmentMiddleware implements MiddlewareInterface
{
    private string $environment;

    /**
     * @param string $environment 'prod', 'test', 'test3' ou 's3'
     */
    public function __construct(string $environment)
    {
        if (!in_array($environment, ['prod', 'test', 'test3', 's3'], true)) {
            throw new \InvalidArgumentException("Environment must be 'prod', 'test', 'test3' or 's3', got: {$environment}");
        }
        
        $this->environment = $environment;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // S'assurer que l'environnement est chargé avant de le définir
        \App\Config\Env::load();
        
        // Définir l'environnement pour cette requête
        TableConfig::setEnvironment($this->environment);
        
        // Continuer le traitement de la requête
        return $handler->handle($request);
    }
}

