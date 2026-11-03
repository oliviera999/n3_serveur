<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * Client HTTP cURL durci pour les Client ID Metadata Documents :
 * HTTPS uniquement, aucune redirection suivie, délai total court, taille plafonnée,
 * protocole limité à HTTPS (pas de file://, gopher://…).
 */
final class CurlHttpFetcher implements HttpFetcher
{
    public function get(string $url, int $timeoutSeconds, int $maxBytes, ?string $pinnedIp = null): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Extension cURL indisponible.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Initialisation cURL impossible.');
        }
        $body = '';
        $tooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(2, $timeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'iot.olution.info-oauth/1.0 (+CIMD)',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0; // interrompt le transfert
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($pinnedIp !== null) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
            $ip = str_contains($pinnedIp, ':') ? '[' . $pinnedIp . ']' : $pinnedIp;
            curl_setopt($handle, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $ip]);
        }
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($tooLarge) {
            throw new \RuntimeException('Document trop volumineux (> ' . $maxBytes . ' octets).');
        }
        if ($ok === false) {
            throw new \RuntimeException('Récupération impossible : ' . $error);
        }

        return ['status' => $status, 'body' => $body, 'content_type' => $contentType];
    }
}
