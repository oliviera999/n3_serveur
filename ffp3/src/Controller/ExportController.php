<?php

namespace App\Controller;

use App\Repository\SensorReadRepository;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

class ExportController
{
    public function __construct(
        private SensorReadRepository $sensorReadRepo
    ) {
    }

    /**
     * Point d'entrée HTTP : /export-data?start=YYYY-MM-DD[+HH:ii:ss]&end=YYYY-MM-DD[+HH:ii:ss]
     * Valide les paramètres, produit un CSV en streaming puis termine le script.
     */
    public function downloadCsv(Request $request, Response $response): Response
    {
        // Récupération des paramètres GET
        $queryParams = $request->getQueryParams();
        $startParam = $queryParams['start'] ?? null;
        $endParam   = $queryParams['end']   ?? null;

        try {
            $start = $startParam ? new DateTimeImmutable($startParam) : new DateTimeImmutable('-1 day');
            $end   = $endParam   ? new DateTimeImmutable($endParam)   : new DateTimeImmutable();
        } catch (\Exception $e) {
            $response->getBody()->write('Paramètres de date invalides');
            return $response->withStatus(400)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        try {

            // Fichier temporaire pour éviter de charger tout en mémoire
            $tmpFile = tempnam(sys_get_temp_dir(), 'export_');
            $nbLines = $this->sensorReadRepo->exportCsv($start, $end, $tmpFile);

            if ($nbLines === 0) {
                @unlink($tmpFile);
                $response->getBody()->write('Aucune donnée pour la période demandée');
                return $response->withStatus(204)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }

            // Lire le contenu du fichier et l'écrire dans la réponse
            $csvContent = file_get_contents($tmpFile);
            @unlink($tmpFile);
            
            $response->getBody()->write($csvContent);
            
            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="sensor-data.csv"')
                ->withHeader('Content-Length', (string) strlen($csvContent));
                
        } catch (Throwable $e) {
            $response->getBody()->write('Erreur serveur');
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }
}