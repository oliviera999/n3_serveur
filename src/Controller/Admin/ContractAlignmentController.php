<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Contract\ContractAlignmentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * API admin : état d'alignement des contrats firmware ↔ serveur.
 */
final class ContractAlignmentController
{
    public function __construct(
        private ContractAlignmentService $alignmentService,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        $results = $this->alignmentService->checkAll();
        $summary = ['ok' => 0, 'warn' => 0, 'error' => 0];
        foreach ($results as $row) {
            $status = (string) ($row['status'] ?? 'ok');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $this->json($response, [
            'success' => true,
            'summary' => $summary,
            'families' => $results,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (string) ($args['id'] ?? '');
        $result = $this->alignmentService->checkById($id);
        if ($result === null) {
            return $this->json($response, [
                'success' => false,
                'error' => 'Contrat inconnu.',
            ], 404);
        }

        return $this->json($response, [
            'success' => true,
            'family' => $result,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
