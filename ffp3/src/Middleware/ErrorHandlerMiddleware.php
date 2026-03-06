<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\ErrorAlertService;
use App\Service\LogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Middleware de gestion centralisée des erreurs
 * 
 * Capture toutes les exceptions non gérées, les log et retourne une réponse HTTP appropriée
 * Enregistre également les erreurs pour détection répétée et alertes automatiques
 */
class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LogService $logger,
        private ErrorAlertService $errorAlert
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (HttpNotFoundException $e) {
            // 404 : log explicite dans error_log (même fichier que les traces PHP) pour diagnostic
            $uri = (string) $request->getUri();
            $line = sprintf('[FFP3 404] %s %s', $request->getMethod(), $uri);
            error_log($line);
            $this->logger->info($line);

            $response = new \Slim\Psr7\Response();
            $response->getBody()->write('Not found.');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        } catch (Throwable $e) {
            $errorMessage = 'Exception non gérée';

            // Logger l'erreur avec contexte
            $this->logger->error($errorMessage, [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'url' => (string) $request->getUri(),
                'method' => $request->getMethod(),
            ]);

            // Enregistrer l'erreur pour détection répétée
            $this->errorAlert->recordError($errorMessage, [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'url' => (string) $request->getUri(),
                'method' => $request->getMethod(),
            ]);

            // Créer une réponse d'erreur
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write('Une erreur serveur est survenue. Veuillez réessayer ultérieurement.');

            return $response->withStatus(500)
                           ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }
}

