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

        // Plage sans donnée + message explicite demandé : 204 No Content (comportement historique).
        if ($nbLines === 0 && $emptyMessage !== null) {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
            $response->getBody()->write($emptyMessage);
            return $response
                ->withStatus(204)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $filename = $filenamePrefix . '_' . date('YmdHis') . '.csv';
        $response = $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        // Plage sans donnée et AUCUN message : on renvoie un CSV VIDE VALIDE plutôt qu'un
        // HTTP 500. Choix retenu (meilleure UX) : livrer un fichier CSV téléchargeable avec la
        // ligne d'en-tête (colonnes) seule — le client reçoit un fichier ouvrable listant les
        // colonnes attendues, sans avoir à gérer un statut d'erreur.
        // SensorReadRepository::exportCsv écrit toujours l'en-tête (même sans donnée) : le
        // fichier existe donc et est streamé ci-dessous. Garde-fou : si un repository n'a produit
        // aucun fichier (0 ligne, autres familles), on renvoie un corps vide (Content-Length: 0)
        // au lieu de planter sur filesize()/fopen() d'un fichier inexistant (bug B1).
        if (!is_file($tmpFile)) {
            return $response->withHeader('Content-Length', '0');
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

        if ($contentLength !== false) {
            $response = $response->withHeader('Content-Length', (string) $contentLength);
        }

        return $response;
    }
}
