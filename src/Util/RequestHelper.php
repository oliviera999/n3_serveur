<?php

declare(strict_types=1);

namespace App\Util;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Helper pour l'extraction et la validation des paramètres de requête.
 * 
 * Unifie la logique d'extraction : JSON > ParsedBody > QueryParams
 */
class RequestHelper
{
    /**
     * Extrait les paramètres d'une requête (JSON, form-data ou query string)
     *
     * Ordre de priorité :
     * 1. Body JSON (si Content-Type: application/json)
     * 2. Parsed body (form-data)
     * 3. Query params
     *
     * @param Request $request Objet Request PSR-7
     * @return array Paramètres extraits
     */
    public static function extractParams(Request $request): array
    {
        $params = [];

        if ($request->getMethod() === 'POST') {
            $contentType = strtolower($request->getHeaderLine('Content-Type'));

            // Priorité 1 : JSON body
            if (str_contains($contentType, 'application/json')) {
                $rawBody = (string)$request->getBody();
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $params = $decoded;
                }
            }

            // Priorité 2 : Parsed body (form-data)
            if ($params === []) {
                $parsedBody = $request->getParsedBody();
                if (is_array($parsedBody)) {
                    $params = $parsedBody;
                }
            }
        }

        // Priorité 3 : Query params
        if ($params === []) {
            $params = $request->getQueryParams();
        }

        return $params;
    }

    /**
     * Vérifie que la méthode HTTP est celle attendue
     *
     * @param Request $request Objet Request PSR-7
     * @param string $expectedMethod Méthode attendue (GET, POST, etc.)
     * @return bool True si la méthode correspond
     */
    public static function isMethod(Request $request, string $expectedMethod): bool
    {
        return strtoupper($request->getMethod()) === strtoupper($expectedMethod);
    }

    /**
     * Extrait un paramètre entier avec valeur par défaut
     *
     * @param array $params Tableau de paramètres
     * @param string $key Clé du paramètre
     * @param int $default Valeur par défaut
     * @return int
     */
    public static function getInt(array $params, string $key, int $default = 0): int
    {
        return isset($params[$key]) ? (int)$params[$key] : $default;
    }

    /**
     * Extrait un paramètre string avec valeur par défaut
     *
     * @param array $params Tableau de paramètres
     * @param string $key Clé du paramètre
     * @param string $default Valeur par défaut
     * @return string
     */
    public static function getString(array $params, string $key, string $default = ''): string
    {
        return isset($params[$key]) ? (string)$params[$key] : $default;
    }
}
