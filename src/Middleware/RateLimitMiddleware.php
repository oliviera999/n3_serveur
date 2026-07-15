<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Security\RateLimiter;
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
     * X-Forwarded-For est trivialement falsifiable : un client malveillant peut
     * l'envoyer pour se répartir sous des IP arbitraires et contourner la
     * limitation (S1). On ne fait donc confiance à cet en-tête QUE si la requête
     * provient d'un proxy de confiance (REMOTE_ADDR ∈ TRUSTED_PROXIES, CSV
     * d'IP/CIDR lu dans l'environnement, vide par défaut). Sinon, on s'en tient
     * à REMOTE_ADDR, non usurpable au niveau applicatif.
     */
    private function clientIp(Request $request): string
    {
        $server = $request->getServerParams();
        $remote = $server['REMOTE_ADDR'] ?? '';
        $remote = is_string($remote) ? $remote : '';

        if ($remote !== '' && $this->isTrustedProxy($remote)) {
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }

        return $remote !== '' ? $remote : 'unknown';
    }

    /**
     * Vrai si l'IP appartient à la liste des proxys de confiance
     * (`TRUSTED_PROXIES`, CSV d'IP ou de plages CIDR IPv4/IPv6).
     */
    private function isTrustedProxy(string $ip): bool
    {
        $raw = $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES');
        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if ($this->ipInCidr($ip, $entry)) {
                    return true;
                }
                continue;
            }
            if (inet_pton($entry) !== false && inet_pton($entry) === inet_pton($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Teste l'appartenance d'une IP (v4/v6) à une plage CIDR.
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $maskLen] = array_pad(explode('/', $cidr, 2), 2, '');
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        // Familles d'adresses différentes (IPv4 vs IPv6) : pas de correspondance.
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bits = (int) $maskLen;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
