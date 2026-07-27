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
 *    (`?token=`, en-tête `Authorization: Bearer` / `X-Admin-Token`) : un secret
 *    non-ambiant n'est pas falsifiable en cross-site, donc pas vulnérable au
 *    CSRF (et cela préserve l'automatisation / les liens partagés de contrôle) ;
 *    ⚠️ M4 : la cible est de migrer le front vers l'en-tête `X-Admin-Token` puis
 *    de retirer le token en URL — voir AuthService::isAuthenticatedByToken ;
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
        '#/api/nav-pages/toggle$#',
        // Action de maintenance déclenchée depuis la page supervision (session admin) :
        // écriture d'état (déplacement de photos en corbeille) -> jeton CSRF exigé.
        '#/admin/api/gallery/auto-sort-all$#',
        // Vidage des caches (POST) : action d'écriture. Exclut clear-cache-page
        // (page d'affichage en GET) via le negative lookahead. Couvre les variantes
        // d'environnement (clear-cache, -test, 3, 3-test, -s3-test).
        '#/admin/clear-cache(?!-page)[0-9a-z-]*$#',
        '#/admin/api/hmac-audit/toggle$#',
        '#/admin/api/hmac-audit/toggle-policy$#',
        '#/admin/api/hmac-audit/reset-policy$#',
        '#/admin/api/operational-settings$#',
        '#/admin/api/operational-settings/reset$#',
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

        // Requête authentifiée par token EXPLICITE (en-tête `Authorization: Bearer` /
        // `X-Admin-Token`, ou `?token=`) : un secret que le navigateur n'envoie pas tout
        // seul n'est pas falsifiable en cross-site, donc non vulnérable au CSRF.
        //
        // CORRECTION 6.32.0 : ce test appelait `isAuthenticatedByToken()`, qui vérifie
        // AUSSI le cookie `admin_token` — ambiant par nature (posé 30 jours par
        // `setAdminTokenCookie()`, réémis automatiquement par le navigateur). L'exemption
        // contredisait donc sa propre justification. `SameSite=Lax` limitait la portée
        // pratique (un POST cross-site ne transporte pas le cookie), mais ne couvre ni un
        // sous-domaine same-site compromis, ni un assouplissement futur de l'attribut.
        // On n'exempte plus que les deux canaux réellement non-ambiants.
        if ($this->hasExplicitToken($request)) {
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

    /**
     * Vrai si la requête porte un token admin valide par un canal NON-AMBIANT :
     * en-tête (`Authorization: Bearer` / `X-Admin-Token`) ou paramètre d'URL `?token=`.
     *
     * Exclut délibérément le cookie `admin_token` : le navigateur l'attache seul, donc
     * il ne prouve pas l'intention de l'utilisateur — c'est exactement ce contre quoi
     * le jeton CSRF protège. Une requête portée par le seul cookie devra donc fournir
     * un `X-CSRF-Token` / `_csrf_token` valide, comme n'importe quelle écriture de session.
     */
    private function hasExplicitToken(Request $request): bool
    {
        if ($this->authService->hasValidHeaderToken()) {
            return true;
        }

        // ⚠️ M4 : le token en query string fuit dans les logs, le Referer et l'historique.
        // Conservé tant que le front propage le token par l'URL (cf. AuthService).
        $queryToken = $request->getQueryParams()['token'] ?? null;
        if (!is_scalar($queryToken)) {
            return false;
        }
        $queryToken = (string) $queryToken;

        return $queryToken !== '' && $this->authService->validateToken($queryToken);
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
