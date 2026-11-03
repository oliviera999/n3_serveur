<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mcp\Health\HealthReportBuilder;
use App\Mcp\McpConfig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * `GET /health/report.json` — rapport de santé pour une supervision externe (workflow GitHub
 * Actions `.github/workflows/health-check.yml`).
 *
 *  - jeton dédié MCP_HEALTH_TOKEN, lu UNIQUEMENT dans l'en-tête `X-Health-Token` (jamais dans
 *    l'URL : pas de trace dans les journaux d'accès), comparé en temps constant (`hash_equals`) ;
 *  - jeton non configuré → 404 (l'endpoint n'existe pas) ; jeton absent ou faux → 401 sans détail ;
 *  - aucun lien avec la session web, ADMIN_TOKEN ni les jetons OAuth MCP ;
 *  - réponse : statut, résumé, raisons et rapport complet (aucun secret ni donnée personnelle),
 *    `Cache-Control: no-store` ; HTTP 200 quel que soit le statut (le client décide : le
 *    workflow échoue si `status` = `rouge`).
 *
 * La route est déclarée hors du bloc MCP_ENABLED (config/routes_mcp.php) : le rapport sert à
 * surveiller le site même si le connecteur Claude est désactivé ; c'est le jeton qui l'active.
 */
final class HealthReportController
{
    public const HEADER = 'X-Health-Token';

    public function __construct(
        private HealthReportBuilder $builder,
        private McpConfig $config,
    ) {
    }

    public function report(Request $request, Response $response): Response
    {
        $expected = $this->config->healthToken();
        if ($expected === '') {
            $response->getBody()->write('Not found.');

            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        $given = trim($request->getHeaderLine(self::HEADER));
        if ($given === '' || !hash_equals($expected, $given)) {
            return McpController::json($response, 401, ['error' => 'unauthorized'])
                ->withHeader('WWW-Authenticate', 'X-Health-Token');
        }

        $checkTls = strtolower((string) ($request->getQueryParams()['tls'] ?? '1')) !== '0';
        $result = $this->builder->build($checkTls);

        return McpController::json($response, 200, [
            'status' => $result['status'],
            'summary' => $result['summary'],
            'reasons' => $result['reasons'],
            'report' => $result['report'],
        ]);
    }
}
