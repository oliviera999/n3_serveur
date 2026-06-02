<?php

declare(strict_types=1);

namespace App\Controller\Pgl;

use App\Config\PglConfig;
use App\Repository\PglRepository;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PglRealtimeApiController
{
    public function __construct(
        private PglRepository $repository,
    ) {
    }

    public function getSystemHealth(Request $request, Response $response): Response
    {
        try {
            $data = $this->repository->getSystemHealth(PglConfig::ONLINE_THRESHOLD_SECONDS);
            return ResponseHelper::json($response, $data);
        } catch (\Throwable $e) {
            return ResponseHelper::json($response, ['error' => 'Erreur serveur', 'detail' => $e->getMessage()], 500);
        }
    }
}
