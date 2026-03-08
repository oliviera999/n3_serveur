<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Analyse des error_log pour le debug en production.
 * Route : GET /admin/analyze-errors (protégée par auth).
 */
class AnalyzeErrorsController
{
    public function __invoke(Request $request, Response $response): Response
    {
        $projectRoot = dirname(__DIR__, 2);
        $scriptPath = $projectRoot . '/tools/analyze_errors.php';

        if (!is_readable($scriptPath)) {
            $response->getBody()->write("Script d'analyse introuvable.");
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        require_once $scriptPath;

        $result = runAnalysis($projectRoot);
        $text = formatSummaryAsText($result);

        $response->getBody()->write($text);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
