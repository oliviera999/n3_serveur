<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\ErrorAlertService;
use App\Service\LogService;
use App\Service\TemplateRenderer;
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
        private ErrorAlertService $errorAlert,
        private ?TemplateRenderer $renderer = null,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (HttpNotFoundException $e) {
            // 404 : log explicite dans error_log et cronlog pour diagnostic
            $uri = (string) $request->getUri();
            $line = sprintf('[%s] [n3-iot 404] %s %s', date('Y-m-d H:i:s'), $request->getMethod(), $uri);
            error_log($line);
            $this->logger->info($line);

            return $this->renderErrorPage(404, 'Page introuvable', 'La page demandée n\'existe pas ou a été déplacée.');
        } catch (Throwable $e) {
            $errorId = substr(bin2hex(random_bytes(8)), 0, 12);
            $uri = (string) $request->getUri();
            $method = $request->getMethod();
            $errorMessage = 'Exception non gérée';

            // Cronlog : une ligne précise avec message, fichier, ligne, méthode, URL
            $this->logger->error(
                'Exception non gérée [{error_id}]: {message} in {file}:{line} — {method} {url}',
                [
                    'error_id' => $errorId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'method' => $method,
                    'url' => $uri,
                ]
            );

            // Enregistrer l'erreur pour détection répétée
            $this->errorAlert->recordError($errorMessage, [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'url' => $uri,
                'method' => $method,
            ]);

            // error_log : ligne de résumé datée + trace (pour diagnostic côté serveur)
            $ts = date('Y-m-d H:i:s');
            error_log(sprintf(
                '[%s] [n3 500] [%s] %s %s — %s: %s in %s:%d',
                $ts,
                $errorId,
                $method,
                $uri,
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            error_log(sprintf('[%s] [n3 500] [%s] Trace: %s', $ts, $errorId, $e->getTraceAsString()));

            return $this->renderErrorPage(
                500,
                'Erreur serveur',
                'Une erreur serveur est survenue. Veuillez réessayer ultérieurement.',
                $errorId
            );
        }
    }

    private function renderErrorPage(int $status, string $heading, string $message, ?string $reference = null): Response
    {
        if ($this->renderer !== null) {
            try {
                $html = $this->renderer->render('error_page.twig', [
                    'page_title' => $heading . ' - n3 iot',
                    'heading' => $heading,
                    'message' => $message,
                    'reference' => $reference,
                    'nav_active' => 'home',
                    'environment' => \App\Config\TableConfig::getDefaultEnvironment(),
                    'load_enhancement_scripts' => false,
                    'load_realtime_styles' => false,
                ]);
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write($html);

                return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
            } catch (\Throwable) {
                // Fallback texte si le template échoue
            }
        }

        $response = new \Slim\Psr7\Response();
        $body = $heading . "\n\n" . $message;
        if ($reference !== null) {
            $body .= "\n\nRéférence : " . $reference;
        }
        $response->getBody()->write($body);

        return $response->withStatus($status)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
