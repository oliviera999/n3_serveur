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

            // Identifiant unique pour retrouver l'erreur dans les logs (production)
            $errorId = substr(bin2hex(random_bytes(8)), 0, 12);
            $uri = (string) $request->getUri();
            error_log(sprintf(
                '[n3 500] [%s] %s %s — %s: %s in %s:%d',
                $errorId,
                $request->getMethod(),
                $uri,
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            error_log('[n3 500] [' . $errorId . '] Trace: ' . $e->getTraceAsString());

            // Créer une réponse d'erreur (avec ID pour corrélation dans les logs)
            $response = new \Slim\Psr7\Response();
            $body = "Une erreur serveur est survenue. Veuillez réessayer ultérieurement.\n\n";
            $body .= "Référence : " . $errorId . " (à indiquer en cas de signalement.)";
            $response->getBody()->write($body);

            return $response->withStatus(500)
                           ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }
}

