<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Capture le corps POST brut avant consommation par mod_php (php://input vide sous form-urlencoded).
 * Ajouter en dernier via $app->add() pour exécution en premier sur la requête entrante.
 */
final class RawPostBodyMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'raw_post_body';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'POST' && $request->getAttribute(self::ATTRIBUTE) === null) {
            $raw = (string) $request->getBody();
            $request = $request->withAttribute(self::ATTRIBUTE, $raw);
        }

        return $handler->handle($request);
    }
}
