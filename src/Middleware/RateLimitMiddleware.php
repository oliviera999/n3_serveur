<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Security\RateLimiter;
use App\Util\ClientIpResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Middleware de limitation de débit par IP.
 *
 * Borne le nombre de requêtes par IP cliente sur une fenêtre glissante pour
 * une portée donnée (ex. « login »). À attacher aux routes sensibles, en
 * complément des protections applicatives (limiteur par session côté login).
 *
 * Au-delà du seuil, répond 429 avec un en-tête Retry-After.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimiter $limiter,
        private string $scope = 'default',
        private int $maxAttempts = 20,
        private int $windowSeconds = 600,
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $key = $this->scope . ':' . $this->clientIp($request);
        $count = $this->limiter->hit($key, $this->windowSeconds);

        if ($count > $this->maxAttempts) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write((string) json_encode([
                'success' => false,
                'error' => 'Trop de requêtes. Veuillez réessayer dans quelques minutes.',
            ]));

            return $response
                ->withStatus(429)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) $this->windowSeconds);
        }

        return $handler->handle($request);
    }

    /**
     * Détermine l'IP cliente.
     *
     * Logique extraite en 6.34.0 vers {@see \App\Util\ClientIpResolver} — à
     * l'identique — pour que les limiteurs firmware (`/post-data`, heartbeats), qui
     * faisaient confiance à `X-Forwarded-For` sans condition, bénéficient du même
     * durcissement « S1 » : l'en-tête n'est cru que si `REMOTE_ADDR` appartient à
     * `TRUSTED_PROXIES`, sinon `REMOTE_ADDR` (non usurpable) fait autorité.
     */
    private function clientIp(Request $request): string
    {
        return ClientIpResolver::resolve($request);
    }
}
