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

        // Lecture du fichier temporaire en streaming (par blocs) vers le corps de la
        // réponse, plutôt qu'un file_get_contents chargeant tout le CSV en mémoire.
        // La taille (Content-Length) est lue séparément via filesize().
        $contentLength = filesize($tmpFile);
        $handle = fopen($tmpFile, 'r');
        if ($handle === false) {
            @unlink($tmpFile);
            throw new \RuntimeException('Impossible de lire le fichier ' . $tmpFile);
        }

        $bodyStream = $response->getBody();
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) {
                break;
            }
            $bodyStream->write($chunk);
        }
        fclose($handle);
        @unlink($tmpFile);

        $filename = $filenamePrefix . '_' . date('YmdHis') . '.csv';

        $response = $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        if ($contentLength !== false) {
            $response = $response->withHeader('Content-Length', (string) $contentLength);
        }

        return $response;
    }
}
