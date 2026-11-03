<?php

declare(strict_types=1);

/**
 * Smoke test de bout en bout du connecteur MCP (OAuth + MCP), contre un serveur réel.
 *
 * Enchaîne exactement le parcours d'un client Claude :
 *   401 + WWW-Authenticate → métadonnées RFC 9728 → métadonnées RFC 8414 → DCR (/oauth/register)
 *   → GET /oauth/authorize (page + CSRF) → POST /oauth/authorize (identifiants) → code
 *   → /oauth/token (PKCE) → initialize → notifications/initialized (202) → tools/list
 *   → tools/call iot_overview → tools/call iot_health_report → rotation du refresh → réutilisation refusée (invalid_grant)
 *   → /oauth/revoke → jeton refusé.
 *
 * Usage (CLI uniquement) :
 *   php tools/mcp-smoke.php --base=http://127.0.0.1:8099 --user=admin --password=localadmin
 *   (MCP_SMOKE_PASSWORD peut remplacer --password pour ne pas l'exposer dans l'historique)
 *
 * Code de sortie 0 si tout passe, 1 sinon. Ne modifie aucune donnée IoT : il crée un client
 * DCR et des jetons (purgés par le CRON), et n'appelle que des outils de lecture.
 */

require_once __DIR__ . '/../src/Util/CliGuard.php';
\App\Util\CliGuard::assertCli('mcp-smoke.php');

require_once __DIR__ . '/../vendor/autoload.php';

$options = getopt('', ['base:', 'user:', 'password::', 'redirect::', 'tool::']);
$base = rtrim((string) ($options['base'] ?? 'http://127.0.0.1:8099'), '/');
$user = (string) ($options['user'] ?? 'admin');
$password = (string) ($options['password'] ?? (getenv('MCP_SMOKE_PASSWORD') ?: ''));
$redirect = (string) ($options['redirect'] ?? 'http://127.0.0.1:53682/callback');
$tool = (string) ($options['tool'] ?? 'iot_overview');
if ($password === '') {
    fwrite(STDERR, "Mot de passe requis : --password=… ou MCP_SMOKE_PASSWORD.\n");
    exit(2);
}

$failures = 0;
$cookieJar = tempnam(sys_get_temp_dir(), 'mcp-smoke-');

/**
 * @param array<string, string> $headers
 * @return array{status: int, headers: array<string, string>, body: string}
 */
$http = static function (string $method, string $url, ?string $body = null, array $headers = []) use ($cookieJar): array {
    $ch = curl_init($url);
    $responseHeaders = [];
    $list = [];
    foreach ($headers as $name => $value) {
        $list[] = $name . ': ' . $value;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $list,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $result = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => is_string($result) ? $result : ''];
};

$check = static function (bool $ok, string $label, string $detail = '') use (&$failures): void {
    echo ($ok ? '  [OK]   ' : '  [ÉCHEC] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$json = static fn (string $body): array => is_array($d = json_decode($body, true)) ? $d : [];
$started = microtime(true);
echo "Smoke MCP contre {$base}\n";

// 1. 401 + WWW-Authenticate
$r = $http('POST', $base . '/mcp', '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}', ['Content-Type' => 'application/json']);
$www = $r['headers']['www-authenticate'] ?? '';
$check($r['status'] === 401, 'POST /mcp sans jeton → 401', 'HTTP ' . $r['status']);
$check(str_contains($www, 'resource_metadata="' . $base . '/.well-known/oauth-protected-resource/mcp"'), 'WWW-Authenticate porte resource_metadata', $www);

// 2. Métadonnées
$prm = $json($http('GET', $base . '/.well-known/oauth-protected-resource/mcp')['body']);
$check(($prm['resource'] ?? null) === $base . '/mcp', 'RFC 9728 : resource = <base>/mcp', (string) ($prm['resource'] ?? '?'));
$issuer = (string) (($prm['authorization_servers'] ?? [])[0] ?? '');
$asm = $json($http('GET', $base . '/.well-known/oauth-authorization-server')['body']);
$check(($asm['issuer'] ?? null) === $issuer && in_array('S256', (array) ($asm['code_challenge_methods_supported'] ?? []), true), 'RFC 8414 : issuer + PKCE S256');
$check(($asm['client_id_metadata_document_supported'] ?? false) === true && in_array('none', (array) ($asm['token_endpoint_auth_methods_supported'] ?? []), true), 'CIMD annoncé + client public');

// 3. DCR
$reg = $http('POST', (string) $asm['registration_endpoint'], (string) json_encode([
    'client_name' => 'Smoke MCP',
    'redirect_uris' => [$redirect],
    'token_endpoint_auth_method' => 'none',
    'grant_types' => ['authorization_code', 'refresh_token'],
]), ['Content-Type' => 'application/json']);
$clientId = (string) ($json($reg['body'])['client_id'] ?? '');
$check($reg['status'] === 201 && $clientId !== '', 'DCR /oauth/register → 201', $clientId);

// 4. Autorisation
$verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$state = bin2hex(random_bytes(8));
$query = http_build_query([
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => str_replace('53682', '61999', $redirect), // port différent : loopback port-agnostique
    'scope' => 'iot.read offline_access',
    'state' => $state,
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'resource' => $base . '/mcp',
]);
$page = $http('GET', (string) $asm['authorization_endpoint'] . '?' . $query);
preg_match('/name="_mcp_csrf" value="([a-f0-9]+)"/', $page['body'], $m);
$csrf = $m[1] ?? '';
$check($page['status'] === 200 && $csrf !== '', 'GET /oauth/authorize → page de consentement (port loopback différent accepté)', 'HTTP ' . $page['status']);

parse_str($query, $form);
$form += ['_mcp_csrf' => $csrf, 'username' => $user, 'password' => $password, 'decision' => 'allow'];
$auth = $http('POST', (string) $asm['authorization_endpoint'], http_build_query($form), ['Content-Type' => 'application/x-www-form-urlencoded']);
$location = $auth['headers']['location'] ?? '';
parse_str((string) parse_url($location, PHP_URL_QUERY), $cb);
$code = (string) ($cb['code'] ?? '');
$check($auth['status'] === 302 && $code !== '' && ($cb['state'] ?? '') === $state, 'POST /oauth/authorize → 302 + code + state', 'HTTP ' . $auth['status'] . ' ' . (string) ($cb['error_description'] ?? ''));

// 5. Jetons
$t0 = microtime(true);
$tok = $http('POST', (string) $asm['token_endpoint'], http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'client_id' => $clientId,
    'redirect_uri' => (string) $form['redirect_uri'],
    'code_verifier' => $verifier,
    'resource' => $base . '/mcp',
]), ['Content-Type' => 'application/x-www-form-urlencoded']);
$tokens = $json($tok['body']);
$access = (string) ($tokens['access_token'] ?? '');
$refresh = (string) ($tokens['refresh_token'] ?? '');
$check($tok['status'] === 200 && $access !== '' && $refresh !== '', 'POST /oauth/token (code + PKCE) → access + refresh', sprintf('%d ms', (int) ((microtime(true) - $t0) * 1000)));

// 6. MCP
$mcp = static function (array $message, string $token) use ($http, $base): array {
    return $http('POST', $base . '/mcp', (string) json_encode($message), [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer ' . $token,
        'MCP-Protocol-Version' => '2025-06-18',
    ]);
};
$init = $mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
    'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'mcp-smoke', 'version' => '1'],
]], $access);
$initBody = $json($init['body']);
$check($init['status'] === 200 && ($initBody['result']['protocolVersion'] ?? '') === '2025-06-18', 'initialize → protocolVersion 2025-06-18', (string) ($initBody['result']['serverInfo']['version'] ?? ''));
$notif = $mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $access);
$check($notif['status'] === 202 && $notif['body'] === '', 'notifications/initialized → 202 sans corps');
$list = $json($mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $access)['body']);
$names = array_column((array) ($list['result']['tools'] ?? []), 'name');
$check(in_array('iot_overview', $names, true), 'tools/list → ' . count($names) . ' outils', implode(', ', $names));
$t0 = microtime(true);
$call = $json($mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => new stdClass()]], $access)['body']);
$isError = $call['result']['isError'] ?? true;
$text = (string) ($call['result']['content'][0]['text'] ?? '');
$check($isError === false && $text !== '', "tools/call {$tool}", sprintf('%d ms, %d octets', (int) ((microtime(true) - $t0) * 1000), strlen($text)));
echo '         ' . mb_substr($text, 0, 300) . (mb_strlen($text) > 300 ? '…' : '') . "\n";

// 6 bis. Rapport de santé (exploitation, v6.52.0) : statut + résumé, sans le détail.
if (in_array('iot_health_report', $names, true)) {
    $t0 = microtime(true);
    $health = $json($mcp(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => [
        'name' => 'iot_health_report', 'arguments' => ['summary_only' => true],
    ]], $access)['body']);
    $healthData = json_decode((string) ($health['result']['content'][0]['text'] ?? ''), true);
    $healthStatus = is_array($healthData) ? (string) ($healthData['status'] ?? '') : '';
    $check(
        ($health['result']['isError'] ?? true) === false && in_array($healthStatus, ['vert', 'orange', 'rouge'], true),
        'tools/call iot_health_report → statut ' . ($healthStatus !== '' ? $healthStatus : '?'),
        sprintf('%d ms', (int) ((microtime(true) - $t0) * 1000))
    );
    echo '         ' . mb_substr(is_array($healthData) ? (string) ($healthData['summary'] ?? '') : '', 0, 300) . "\n";
} else {
    $check(false, 'tools/call iot_health_report', 'outil absent de tools/list (serveur < 6.52.0 ?)');
}

// 7. Rotation du refresh + réutilisation
$rot = $json($http('POST', (string) $asm['token_endpoint'], http_build_query([
    'grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId,
]), ['Content-Type' => 'application/x-www-form-urlencoded'])['body']);
$newRefresh = (string) ($rot['refresh_token'] ?? '');
$check($newRefresh !== '' && $newRefresh !== $refresh, 'Refresh → nouveau couple (rotation)');
$reuse = $json($http('POST', (string) $asm['token_endpoint'], http_build_query([
    'grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $clientId,
]), ['Content-Type' => 'application/x-www-form-urlencoded'])['body']);
$check(($reuse['error'] ?? '') === 'invalid_grant', 'Réutilisation de l\'ancien refresh → invalid_grant');
$afterReuse = $json($http('POST', (string) $asm['token_endpoint'], http_build_query([
    'grant_type' => 'refresh_token', 'refresh_token' => $newRefresh, 'client_id' => $clientId,
]), ['Content-Type' => 'application/x-www-form-urlencoded'])['body']);
$check(($afterReuse['error'] ?? '') === 'invalid_grant', 'Famille révoquée après réutilisation (nouveau refresh aussi refusé)');

// 8. Rejeu du code d'autorisation (doit être refusé)
$replay = $http('POST', (string) $asm['token_endpoint'], http_build_query([
    'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $clientId,
    'redirect_uri' => (string) $form['redirect_uri'], 'code_verifier' => $verifier,
]), ['Content-Type' => 'application/x-www-form-urlencoded']);
$check(($json($replay['body'])['error'] ?? '') === 'invalid_grant', 'Rejeu du code → invalid_grant (et jetons issus du code révoqués)');

// 9. Révocation
$revokeTarget = (string) ($rot['access_token'] ?? $access);
$http('POST', (string) $asm['revocation_endpoint'], http_build_query(['token' => $revokeTarget, 'client_id' => $clientId]), ['Content-Type' => 'application/x-www-form-urlencoded']);
$after = $mcp(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping'], $revokeTarget);
$check($after['status'] === 401, 'Jeton révoqué → 401 sur /mcp');

@unlink((string) $cookieJar);
printf("\n%s en %.1f s — %d échec(s)\n", $failures === 0 ? 'SMOKE OK' : 'SMOKE EN ÉCHEC', microtime(true) - $started, $failures);
exit($failures === 0 ? 0 : 1);
