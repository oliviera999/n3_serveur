<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Env;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Validation de la clé API device (firmwares ESP32 / ESP32-CAM).
 * Convention : header X-Api-Key (prioritaire) ou paramètre api_key (GET/POST).
 */
final class DeviceApiKeyValidator
{
    public static function isValidRequest(Request $request, array $params = []): bool
    {
        Env::load();
        $expected = trim((string) ($_ENV['API_KEY'] ?? ''));
        if ($expected === '') {
            return false;
        }

        $provided = trim($request->getHeaderLine('X-Api-Key'));
        if ($provided === '' && isset($params['api_key']) && is_scalar($params['api_key'])) {
            $provided = trim((string) $params['api_key']);
        }
        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
