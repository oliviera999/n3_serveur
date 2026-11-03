<?php

/**
 * Routes du connecteur MCP (Claude) et de son serveur d'autorisation OAuth embarqué.
 * Inclus depuis public/index.php — la variable $app doit être en scope.
 *
 * Actives uniquement si MCP_ENABLED=true (sinon 404 Slim, comportement inchangé).
 *
 *  POST   /mcp                                          JSON-RPC MCP (Bearer OAuth)
 *  GET|DELETE /mcp                                      405 (serveur sans état)
 *  GET    /.well-known/oauth-protected-resource[/mcp]   RFC 9728
 *  GET    /.well-known/oauth-authorization-server[/…]   RFC 8414
 *  POST   /oauth/register                               RFC 7591
 *  GET|POST /oauth/authorize                            connexion + consentement
 *  POST   /oauth/token                                  jetons (PKCE, rotation)
 *  POST   /oauth/revoke                                 RFC 7009
 *  GET    /health/report.json                           rapport de santé (en-tête X-Health-Token,
 *                                                       actif dès que MCP_HEALTH_TOKEN est défini,
 *                                                       indépendamment de MCP_ENABLED ; sinon 404)
 *
 * Ces chemins ne sont ni dans `protected_paths` (pas de session web exigée : l'accès est
 * contrôlé par le jeton OAuth) ni dans le périmètre de CsrfMiddleware (liste positive) :
 * le formulaire /oauth/authorize porte son propre jeton CSRF. Doc : docs/MCP.md.
 */

use App\Controller\HealthReportController;
use App\Controller\McpController;
use App\Controller\OAuth\OAuthController;
use App\Mcp\McpConfig;

/** @var \Slim\App $app */
/** @var \Psr\Container\ContainerInterface $container */
$mcpConfig = $container->get(McpConfig::class);

// Rapport de santé pour la supervision externe : jeton dédié (404 tant qu'il est vide),
// limiteur de débit propre. Hors du bloc MCP_ENABLED : surveille le site même connecteur coupé.
if ($mcpConfig->healthToken() !== '') {
    $app->get('/health/report.json', [HealthReportController::class, 'report'])->add($container->get('mcp.health_rate_limit'));
}

if (!$mcpConfig->isEnabled()) {
    return;
}

$mcpRateLimit = $container->get('mcp.rate_limit');
$oauthRateLimit = $container->get('mcp.oauth_rate_limit');

$app->post('/mcp', [McpController::class, 'post'])->add($mcpRateLimit);
$app->map(['GET', 'DELETE'], '/mcp', [McpController::class, 'methodNotAllowed']);

$app->get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource']);
$app->get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResource']);
$app->get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer']);
// Variante « suffixée » parfois essayée par les clients quand l'issuer a un chemin.
$app->get('/.well-known/oauth-authorization-server/mcp', [OAuthController::class, 'authorizationServer']);

$app->post('/oauth/register', [OAuthController::class, 'register'])->add($oauthRateLimit);
$app->get('/oauth/authorize', [OAuthController::class, 'authorizeForm']);
$app->post('/oauth/authorize', [OAuthController::class, 'authorizeSubmit'])->add($oauthRateLimit);
$app->post('/oauth/token', [OAuthController::class, 'token'])->add($oauthRateLimit);
$app->post('/oauth/revoke', [OAuthController::class, 'revoke'])->add($oauthRateLimit);
$app->options('/oauth/{endpoint:token|revoke|register}', [OAuthController::class, 'preflight']);
$app->options('/.well-known/{doc:oauth-protected-resource|oauth-authorization-server}[/mcp]', [OAuthController::class, 'preflight']);
