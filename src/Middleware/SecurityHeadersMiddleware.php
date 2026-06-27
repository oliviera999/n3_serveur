<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Middleware d'ajout des headers de sécurité HTTP.
 *
 * Headers couverts :
 *   - X-Content-Type-Options : nosniff (anti MIME-sniffing)
 *   - X-Frame-Options : SAMEORIGIN (anti clickjacking)
 *   - Referrer-Policy : strict-origin-when-cross-origin (limite Referer)
 *   - Permissions-Policy : geolocation/microphone/camera bloques par defaut
 *   - Content-Security-Policy : restrictive avec ouverture pour Highcharts/CDN locaux
 *                               et iframes Google Sheets (frame-src docs.google.com)
 *   - Strict-Transport-Security : HTTPS detecte via scheme OU X-Forwarded-Proto
 *
 * Override via .env :
 *   - SECURITY_CSP=<policy>            : remplace la CSP par defaut.
 *   - SECURITY_FORCE_HSTS=true|false   : envoie HSTS meme si scheme HTTP detecte
 *                                        (utile derriere reverse proxy mutualise).
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * CSP par defaut (assouplie pour les pages avec Highcharts/Chart.js et scripts inline existants).
     * Privilegier `nonce` + retrait de 'unsafe-inline' dans un cycle ulterieur.
     */
    private const DEFAULT_CSP = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://code.highcharts.com https://cdn.jsdelivr.net https://code.jquery.com; "
        // Google Fonts : la feuille de style est servie par fonts.googleapis.com et
        // les fichiers de police par fonts.gstatic.com (cf. layout.twig). Sans ces
        // origines, la CSP bloque le <link rel="stylesheet"> Google Fonts.
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "img-src 'self' data: blob:; "
        . "font-src 'self' data: https://fonts.gstatic.com; "
        . "connect-src 'self'; "
        // Graphiques Google Sheets publies (pubchart) embarques en <iframe> sur
        // les pages aquaponie/MSP. Sans frame-src explicite, le fallback default-src
        // 'self' bloque ces iframes et les graphiques ne s'affichent pas.
        . "frame-src 'self' https://docs.google.com; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "object-src 'none'";

    private const DEFAULT_PERMISSIONS = 'geolocation=(), microphone=(), camera=(), payment=(), usb=()';

    public function process(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        $csp = trim((string) ($_ENV['SECURITY_CSP'] ?? '')) ?: self::DEFAULT_CSP;

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', self::DEFAULT_PERMISSIONS)
            ->withHeader('Content-Security-Policy', $csp);

        if ($this->isHttps($request)) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }

    /**
     * Detecte HTTPS via scheme PSR-7, X-Forwarded-Proto, SERVER_PORT 443
     * ou SECURITY_FORCE_HSTS=true (production derriere reverse proxy mutualise).
     */
    private function isHttps(Request $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }
        $forwardedProto = $request->getHeaderLine('X-Forwarded-Proto');
        if ($forwardedProto !== '' && strtolower($forwardedProto) === 'https') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return filter_var($_ENV['SECURITY_FORCE_HSTS'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }
}
