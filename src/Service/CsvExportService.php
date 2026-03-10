<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Export CSV générique pour les données capteurs.
 * Factorise le pattern fichier temporaire -> lecture -> suppression
 * commun à Aquaponie, Dashboard, MspData, N3ppData.
 */
class CsvExportService
{
    /**
     * Exporte des données capteurs en CSV via un repository.
     *
     * Le repository doit implémenter une méthode exportCsv(string $start, string $end, string $path)
     * retournant le nombre de lignes écrites.
     *
     * @param object   $repository Objet avec méthode exportCsv()
     * @param string   $startDate  Date de début (Y-m-d H:i:s)
     * @param string   $endDate    Date de fin (Y-m-d H:i:s)
     * @param Response $response   Réponse PSR-7
     * @param string   $filenamePrefix Préfixe du fichier CSV
     * @param string|null $emptyMessage Message si aucune donnée (retourne 204 si fourni)
     * @return Response
     */
    public function export(
        object $repository,
        string $startDate,
        string $endDate,
        Response $response,
        string $filenamePrefix = 'export',
        ?string $emptyMessage = null
    ): Response {
        $tmpFile = sys_get_temp_dir() . '/' . $filenamePrefix . '_' . time() . '.csv';

        $nbLines = $repository->exportCsv($startDate, $endDate, $tmpFile);

        if ($nbLines === 0) {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
            if ($emptyMessage !== null) {
                $response->getBody()->write($emptyMessage);
                return $response
                    ->withStatus(204)
                    ->withHeader('Content-Type', 'text/plain; charset=utf-8');
            }
        }

        $csvContent = file_get_contents($tmpFile);
        @unlink($tmpFile);

        $response->getBody()->write($csvContent);

        $filename = $filenamePrefix . '_' . date('YmdHis') . '.csv';

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($csvContent));
    }
}
