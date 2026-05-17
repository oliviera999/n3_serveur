<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\TableConfig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Réinitialise l'environnement BDD au défaut (.env) en début de chaque requête HTTP.
 * Évite la fuite d'ENV entre requêtes quand EnvironmentMiddleware a fixé un env de route.
 */
final class RequestEnvironmentMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        TableConfig::resetRequestEnvironment();

        return $handler->handle($request);
    }
}
