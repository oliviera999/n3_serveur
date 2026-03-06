<?php
/**
 * Point d'entree racine - Redirige vers public/index.php
 *
 * Si le DocumentRoot pointe vers serveur/ plutot que serveur/public/,
 * ce fichier assure le routage vers le front-controller Slim 4.
 */

if (!function_exists('n3DebugLog')) {
    function n3DebugLog(string $hypothesisId, string $location, string $message, array $data = []): void
    {
        $payload = [
            'sessionId' => '944511',
            'runId' => isset($_SERVER['REQUEST_TIME_FLOAT']) ? (string) $_SERVER['REQUEST_TIME_FLOAT'] : uniqid('req_', true),
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000),
        ];

        @file_put_contents(
            __DIR__ . '/../debug-944511.log',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );
    }
}

$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($requestPath !== '') {
    // #region agent log
    if (
        str_starts_with($requestPath, '/assets/')
        || str_contains($requestPath, 'msp1-data.php')
        || str_contains($requestPath, 'n3pp-data.php')
        || $requestPath === '/'
    ) {
        n3DebugLog(
            'H1',
            'serveur/index.php:11',
            'Entree front controller racine',
            [
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
                'request_path' => $requestPath,
                'script_name' => $_SERVER['SCRIPT_NAME'] ?? null,
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
                'root_assets_css_exists' => is_file(__DIR__ . '/assets/css/main.css'),
                'public_assets_css_exists' => is_file(__DIR__ . '/public/assets/css/main.css'),
            ]
        );
    }
    // #endregion
}

require __DIR__ . '/public/index.php';
