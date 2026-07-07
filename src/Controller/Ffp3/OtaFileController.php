<?php

declare(strict_types=1);

namespace App\Controller\Ffp3;

use App\Service\OperationalSettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sert les fichiers OTA (firmware .bin / metadata .json) depuis le dossier `ota/`,
 * en streaming (chunks 64 Ko, sans charger le fichier entier en mémoire).
 *
 * Fonctionnalités :
 *  - Protection path traversal (`..` / chemin vide → 400) ; base dir fixe.
 *  - Auth opt-in via OTA_REQUIRE_AUTH=true (X-Api-Key / X-OTA-Key / ?api_key=).
 *  - Téléchargement complet (200) — comportement historique inchangé.
 *  - HTTP Range (206 Partial Content) : permet la reprise / le download résumable
 *    côté firmware (audit S3 — prépare l'unification des modes de download OTA).
 *    `Accept-Ranges: bytes` est toujours annoncé.
 *
 * Conformité RFC 7233 : un en-tête `Range` **non analysable** est ignoré (réponse
 * 200 complète) ; seul un range syntaxiquement valide mais hors limites donne 416.
 *
 * Extrait de public/index.php pour testabilité (cf. OtaFileControllerTest).
 */
final class OtaFileController
{
    private const CHUNK = 65536;

    /** @param string $otaDir Dossier racine des fichiers OTA (sans slash final requis). */
    public function __construct(
        private string $otaDir,
        private ?OperationalSettingsService $operationalSettings = null,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';
        if ($path === '' || strpos($path, '..') !== false) {
            return $response->withStatus(400);
        }

        $authReject = $this->enforceAuth($request, $response, $path);
        if ($authReject !== null) {
            return $authReject;
        }

        $base = rtrim($this->otaDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $file = $base . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!is_file($file)) {
            return $response->withStatus(404);
        }

        $fileSize = filesize($file);
        if ($fileSize === false) {
            return $response->withStatus(500);
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if ($ext === 'json') {
            $response = $response->withHeader('Content-Type', 'application/json');
        } elseif ($ext === 'bin') {
            $response = $response->withHeader('Content-Type', 'application/octet-stream');
        }
        // Toujours annoncer le support des requêtes partielles.
        $response = $response->withHeader('Accept-Ranges', 'bytes');

        // Analyse de l'en-tête Range (le cas échéant).
        $range = $this->parseRange($request->getHeaderLine('Range'), $fileSize);

        if ($range === 'unsatisfiable') {
            return $response
                ->withHeader('Content-Range', 'bytes */' . $fileSize)
                ->withStatus(416);
        }

        if ($range === null) {
            // Pas de Range exploitable → réponse complète (comportement historique).
            $response = $response->withHeader('Content-Length', (string) $fileSize);
            return $this->stream($response, $file, 0, $fileSize);
        }

        // Range valide → 206 Partial Content.
        [$start, $end] = $range;
        $length = $end - $start + 1;
        $response = $response
            ->withHeader('Content-Length', (string) $length)
            ->withHeader('Content-Range', "bytes {$start}-{$end}/{$fileSize}")
            ->withStatus(206);

        return $this->stream($response, $file, $start, $length);
    }

    /**
     * Analyse un en-tête Range pour un fichier de taille $fileSize.
     *
     * @return array{0:int,1:int}|null|string  [start,end] si range valide ;
     *   null si pas de Range ou Range non analysable (→ servir 200 complet) ;
     *   'unsatisfiable' si range syntaxiquement valide mais hors limites (→ 416).
     */
    private function parseRange(string $header, int $fileSize): array|null|string
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        // Une seule plage « bytes=start-end | start- | -suffix ». Multi-range non géré
        // (rare, inutile pour l'OTA) → ignoré comme non analysable.
        if (preg_match('/^bytes=(\d*)-(\d*)$/', $header, $m) !== 1) {
            return null; // non analysable → RFC 7233 : ignorer, servir 200
        }
        $startStr = $m[1];
        $endStr = $m[2];
        if ($startStr === '' && $endStr === '') {
            return null; // « bytes=- » : invalide → ignorer
        }

        if ($startStr === '') {
            // Suffixe : derniers N octets.
            $suffix = (int) $endStr;
            if ($suffix === 0) {
                return 'unsatisfiable';
            }
            $start = $suffix >= $fileSize ? 0 : $fileSize - $suffix;
            $end = $fileSize - 1;
        } else {
            $start = (int) $startStr;
            if ($start >= $fileSize) {
                return 'unsatisfiable';
            }
            $end = $endStr === '' ? $fileSize - 1 : (int) $endStr;
            if ($end < $start) {
                return 'unsatisfiable';
            }
            if ($end > $fileSize - 1) {
                $end = $fileSize - 1;
            }
        }

        return [$start, $end];
    }

    private function stream(Response $response, string $file, int $offset, int $length): Response
    {
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            return $response->withStatus(500);
        }
        if ($offset > 0) {
            fseek($stream, $offset, SEEK_SET);
        }
        $body = $response->getBody();
        $remaining = $length;
        while ($remaining > 0 && !feof($stream)) {
            $chunk = fread($stream, (int) min(self::CHUNK, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body->write($chunk);
            $remaining -= strlen($chunk);
        }
        fclose($stream);

        return $response;
    }

    private function enforceAuth(Request $request, Response $response, string $path): ?Response
    {
        $requireAuth = $this->operationalSettings?->bool('OTA_REQUIRE_AUTH', false)
            ?? filter_var($_ENV['OTA_REQUIRE_AUTH'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        if (!$requireAuth) {
            return null;
        }
        $expectedKey = trim((string) ($_ENV['API_KEY'] ?? ''));
        if ($expectedKey === '') {
            error_log('[OTA] OTA_REQUIRE_AUTH=true mais API_KEY non configuree');
            return $response->withStatus(500);
        }
        $providedKey = trim($request->getHeaderLine('X-Api-Key'));
        if ($providedKey === '') {
            $providedKey = trim($request->getHeaderLine('X-OTA-Key'));
        }
        if ($providedKey === '') {
            $params = $request->getQueryParams();
            if (isset($params['api_key']) && is_scalar($params['api_key'])) {
                $providedKey = trim((string) $params['api_key']);
            }
        }
        if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            error_log(sprintf('[OTA] rejet auth path=%s ip=%s', $path, $_SERVER['REMOTE_ADDR'] ?? 'n/a'));
            return $response->withStatus(401);
        }

        return null;
    }
}
