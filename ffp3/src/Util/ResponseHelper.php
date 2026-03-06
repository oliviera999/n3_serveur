<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Helper pour standardiser les réponses HTTP dans les Controllers.
 * 
 * Élimine la duplication des patterns de réponses JSON/text.
 */
class ResponseHelper
{
    /**
     * Retourne une réponse JSON
     *
     * @param Response $response Objet Response PSR-7
     * @param array $data Données à encoder en JSON
     * @param int $status Code HTTP (200 par défaut)
     * @return Response
     */
    public static function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Retourne une réponse texte brut
     *
     * @param Response $response Objet Response PSR-7
     * @param string $message Message texte
     * @param int $status Code HTTP (200 par défaut)
     * @return Response
     */
    public static function text(Response $response, string $message, int $status = 200): Response
    {
        $response->getBody()->write($message);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withStatus($status);
    }

    /**
     * Réponse texte avec Content-Length et Connection: close.
     * Réduit le risque de buffer proxy et signale la fin de la réponse (latence ESP32/o2switch).
     *
     * @param Response $response Objet Response PSR-7
     * @param string $message Message texte
     * @param int $status Code HTTP (200 par défaut)
     * @return Response
     */
    public static function textClose(Response $response, string $message, int $status = 200): Response
    {
        $response->getBody()->write($message);
        $len = strlen($message);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Length', (string) $len)
            ->withHeader('Connection', 'close')
            ->withStatus($status);
    }

    /**
     * Retourne une réponse d'erreur JSON standardisée
     *
     * @param Response $response Objet Response PSR-7
     * @param string $message Message d'erreur
     * @param int $status Code HTTP (400 par défaut)
     * @return Response
     */
    public static function error(Response $response, string $message, int $status = 400): Response
    {
        return self::json($response, [
            'status' => 'error',
            'message' => $message,
        ], $status);
    }

    /**
     * Retourne une réponse de succès JSON standardisée
     *
     * @param Response $response Objet Response PSR-7
     * @param array $data Données additionnelles à inclure
     * @param int $status Code HTTP (200 par défaut)
     * @return Response
     */
    public static function success(Response $response, array $data = [], int $status = 200): Response
    {
        return self::json($response, array_merge(['status' => 'ok'], $data), $status);
    }
}
