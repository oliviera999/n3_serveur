<?php

declare(strict_types=1);

/**
 * Vérifie la découverte OAuth / MCP (well-known + 401 sur POST /mcp).
 *
 * Usage (CLI uniquement) :
 *   php c:\projets_code\IOT_n3\serveur\tools\mcp-check-discovery.php --base=https://iot.olution.info
 *
 * Code de sortie 0 si tout est OK, 1 sinon.
 */

require_once __DIR__ . '/../src/Util/CliGuard.php';
\App\Util\CliGuard::assertCli('mcp-check-discovery.php');

$options = getopt('', ['base:', 'help']);
if (array_key_exists('help', $options)) {
    fwrite(STDOUT, "Usage: php mcp-check-discovery.php [--base=https://iot.olution.info]\n");
    exit(0);
}

$base = rtrim((string) ($options['base'] ?? 'https://iot.olution.info'), '/');

if (!extension_loaded('curl')) {
    fwrite(STDERR, "[FAIL] Extension PHP curl absente. Installez php_curl, ou utilisez :\n");
    fwrite(STDERR, "  c:\\projets_code\\IOT_n3\\serveur\\tools\\mcp-activate.ps1 -Action check\n");
    exit(1);
}

/**
 * @return array{status: int, headers: array<string, string>, body: string, error: string}
 */
$http = static function (string $method, string $url, ?string $body = null, array $headers = []): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'curl_init a échoué'];
    }
    $responseHeaders = [];
    $list = [];
    foreach ($headers as $name => $value) {
        $list[] = $name . ': ' . $value;
    }
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $list,
        CURLOPT_USERAGENT => 'n3-mcp-check-discovery/1.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ];
    // Sur Windows, le store CA de PHP est souvent incomplet — tenter le bundle système.
    if (defined('CURLSSLOPT_NATIVE_CA') && defined('CURLOPT_SSL_OPTIONS')) {
        $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($ch, $opts);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $result = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($result === false) {
        return [
            'status' => 0,
            'headers' => [],
            'body' => '',
            'error' => $err !== '' ? "curl #{$errno}: {$err}" : "curl #{$errno}",
        ];
    }

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $result, 'error' => $err];
};

$ok = true;
$say = static function (bool $pass, string $label, string $detail = '') use (&$ok): void {
    if (!$pass) {
        $ok = false;
    }
    echo ($pass ? '[OK]  ' : '[FAIL] ') . $label;
    if ($detail !== '') {
        echo ' — ' . $detail;
    }
    echo PHP_EOL;
};

$hint404 = 'HTTP 404 : MCP probablement inactif (MCP_ENABLED=false) ou rewrite /.well-known (docs/MCP.md § 7)';
$hint0 = 'HTTP 0 : TLS/réseau côté PHP (souvent CA manquant sous Windows). Réessayez mcp-activate.ps1 -Action check (curl.exe)';

echo "=== MCP discovery — {$base} ===" . PHP_EOL;
echo 'PHP ' . PHP_VERSION . ' | curl ' . (curl_version()['version'] ?? '?') . PHP_EOL . PHP_EOL;

$prm = $http('GET', $base . '/.well-known/oauth-protected-resource/mcp');
$prmJson = json_decode($prm['body'], true);
$resourceOk = $prm['status'] === 200
    && is_array($prmJson)
    && (($prmJson['resource'] ?? null) === $base . '/mcp');
$prmDetail = 'HTTP ' . $prm['status'];
if ($prm['error'] !== '' && $prm['status'] === 0) {
    $prmDetail .= ' | ' . $prm['error'] . ' | ' . $hint0;
} elseif ($prm['status'] === 404) {
    $prmDetail .= ' | ' . $hint404;
} elseif (is_array($prmJson)) {
    $prmDetail .= ', resource=' . (string) ($prmJson['resource'] ?? '');
} elseif ($prm['body'] !== '' && $prm['status'] !== 200) {
    $prmDetail .= ', corps=' . substr(preg_replace('/\s+/', ' ', $prm['body']) ?? '', 0, 80);
}
$say($resourceOk, 'oauth-protected-resource/mcp', $prmDetail);

$as = $http('GET', $base . '/.well-known/oauth-authorization-server');
$asJson = json_decode($as['body'], true);
$asOk = $as['status'] === 200
    && is_array($asJson)
    && isset($asJson['authorization_endpoint'], $asJson['token_endpoint'])
    && in_array('S256', $asJson['code_challenge_methods_supported'] ?? [], true);
$asDetail = 'HTTP ' . $as['status'];
if ($as['error'] !== '' && $as['status'] === 0) {
    $asDetail .= ' | ' . $as['error'] . ' | ' . $hint0;
} elseif ($as['status'] === 404) {
    $asDetail .= ' | ' . $hint404;
} else {
    $methods = is_array($asJson) ? implode(',', $asJson['token_endpoint_auth_methods_supported'] ?? []) : '';
    if ($methods !== '') {
        $asDetail .= ", auth={$methods}";
    }
}
$say($asOk, 'oauth-authorization-server', $asDetail);

$ping = $http(
    'POST',
    $base . '/mcp',
    '{"jsonrpc":"2.0","id":1,"method":"ping"}',
    ['Content-Type' => 'application/json', 'Accept' => 'application/json']
);
$www = $ping['headers']['www-authenticate'] ?? '';
$pingOk = $ping['status'] === 401 && $www !== '' && (
    stripos($www, 'resource_metadata') !== false || stripos($www, 'Bearer') !== false
);
$pingDetail = 'HTTP ' . $ping['status'];
if ($ping['error'] !== '' && $ping['status'] === 0) {
    $pingDetail .= ' | ' . $ping['error'] . ' | ' . $hint0;
} elseif ($ping['status'] === 404) {
    $pingDetail .= ' | ' . $hint404;
} elseif ($www !== '') {
    $pingDetail .= ', WWW-Authenticate présent';
}
$say($pingOk, 'POST /mcp sans jeton → 401', $pingDetail);

echo PHP_EOL;
if ($ok) {
    echo 'SMOKE DISCOVERY OK — brancher ensuite Claude / Cursor / ChatGPT / Gemini (docs/MCP_ACTIVATION.md).' . PHP_EOL;
    exit(0);
}

echo 'ÉCHEC — docs/MCP_ACTIVATION.md (A5–A7) : activer MCP_ENABLED=true sur le serveur, puis retester.' . PHP_EOL;
echo 'Sous Windows, privilégier : c:\projets_code\IOT_n3\serveur\tools\mcp-activate.ps1 -Action check' . PHP_EOL;
exit(1);
