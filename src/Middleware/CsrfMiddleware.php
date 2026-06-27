<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Security\AuthService;
use App\Security\CsrfService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Middleware de protection CSRF pour les écritures pilotées par le navigateur.
 *
 * Stratégie (cf. AUDIT_PAGE_CONTROL_DISTANT §CSRF) :
 *  - ne s'applique qu'aux méthodes non sûres (POST/PUT/PATCH/DELETE) ;
 *  - ne s'applique qu'aux chemins d'écriture explicitement listés (liste
 *    positive) afin de NE JAMAIS impacter les endpoints machine (ESP32 :
 *    post-data, heartbeat, firmware, /state) qui n'y figurent pas ;
 *  - exempte les requêtes authentifiées par un token d'accès explicite
 *    (`?token=` valide) : une requête portant un secret non-ambiant n'est pas
 *    falsifiable en cross-site, donc pas vulnérable au CSRF (et cela préserve
 *    l'automatisation/liens partagés) ;
 *  - pour les écritures authentifiées par cookie de session (le vrai risque
 *    CSRF), exige un token CSRF valide via l'en-tête `X-CSRF-Token` ou le
 *    champ de formulaire `_csrf_token`.
 *
 * En cas d'échec : 403 JSON.
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * Chemins d'écriture protégés (suffixes). Limité aux actions navigateur du
     * panneau de contrôle effectivement câblées côté front (header X-CSRF-Token).
     *
     * @var list<string>
     */
    private const DEFAULT_PROTECTED_PATTERNS = [
        '#/api/outputs[0-9]*(-test)?/toggle(-test)?$#',
        '#/api/outputs[0-9]*(-test)?/trigger-feed$#',
        '#/api/outputs[0-9]*(-test)?/parameters$#',
        '#/api/outputs[0-9]*(-test)?/trigger-ota-check$#',
        '#/api/outputs[0-9]*(-test)?/notification-policy$#',
    ];

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param list<string>|null $protectedPatterns Permet de surcharger la liste (tests).
     */
    public function __construct(
        private CsrfService $csrfService,
        private AuthService $authService,
        private ?array $protectedPatterns = null,
    ) {
        if ($this->protectedPatterns === null) {
            $this->protectedPatterns = self::DEFAULT_PROTECTED_PATTERNS;
        }
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        if (!$this->isProtected($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        // Requête authentifiée par token explicite : non vulnérable au CSRF.
        if ($this->authService->isAuthenticatedByToken($request->getQueryParams())) {
            return $handler->handle($request);
        }

        if (!$this->csrfService->validateToken($this->extractToken($request))) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write((string) json_encode([
                'success' => false,
                'error' => 'Token CSRF invalide ou manquant.',
            ]));

            return $response
                ->withStatus(403)
                ->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }

    private function isProtected(string $path): bool
    {
        foreach ((array) $this->protectedPatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->getHeaderLine('X-CSRF-Token');
        if ($header !== '') {
            return $header;
        }

        $body = $request->getParsedBody();
        if (is_array($body) && isset($body[CsrfService::getFieldName()]) && is_string($body[CsrfService::getFieldName()])) {
            return $body[CsrfService::getFieldName()];
        }

        return null;
    }
}
