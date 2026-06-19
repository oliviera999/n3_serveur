<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use PDO;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;

/**
 * Tests d'intégration HTTP de bout en bout pour le panneau de contrôle FFP3
 * (toggle / parameters / state), exercés contre une VRAIE base MySQL.
 *
 * Architecture :
 *  - le front-controller réel `public/index.php` est servi par un serveur PHP
 *    intégré (`php -S`) démarré par le test, avec un environnement dédié
 *    (ENV=test → tables ffp3Outputs2 / ffp3Data2, AUTH_METHOD=both, ADMIN_TOKEN) ;
 *  - les écritures protégées (toggle/parameters) sont atteintes via `?token=`
 *    qui authentifie la requête ET l'exempte de CSRF (jeton hors-bande non
 *    falsifiable en cross-site) — voir AuthGuardMiddleware + CsrfMiddleware ;
 *  - chaque assertion vérifie l'effet réel en base via une connexion PDO directe.
 *
 * Skip propre (comme tests/Integration/*) si la BDD de test n'est pas joignable
 * (variables DB_* absentes, MySQL down, ou php-cli sans pdo_mysql), afin de
 * rester vert en CI sans MySQL.
 *
 * Lancement (voir docs/TESTS_INTEGRATION_HTTP.md) :
 *   docker compose -f docker-compose.integration.yml up -d
 *   export DB_HOST=127.0.0.1 DB_PORT=3310 DB_NAME=n3_http_test \
 *          DB_USER=n3_http DB_PASS=n3_http_pass ADMIN_TOKEN=integration-token
 *   composer test:integration
 */
#[BackupGlobals(false)]
final class OutputControlHttpIntegrationTest extends TestCase
{
    /** Environnement applicatif testé (tables suffixées « 2 », routes /api/outputs-test/*). */
    private const ENV = 'test';

    /** Jeton d'admin hors-bande : authentifie + exempte de CSRF les écritures. */
    private const TOKEN = 'integration-http-token';

    private static ?PDO $pdo = null;

    /** @var resource|null */
    private static $serverProcess = null;

    private static string $baseUrl = '';

    /** @var array<int, resource> */
    private static array $pipes = [];

    public static function setUpBeforeClass(): void
    {
        $pdo = self::tryConnect();
        if ($pdo === null) {
            // Pas de BDD : on laisse chaque test se marquer skipped dans setUp().
            return;
        }
        self::$pdo = $pdo;
        self::loadSchema($pdo);
        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        self::$pdo = null;
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped(
                'BDD HTTP d\'intégration non joignable (DB_* absentes, MySQL down ou pdo_mysql manquant). '
                . 'Voir docs/TESTS_INTEGRATION_HTTP.md.'
            );
        }
        if (self::$serverProcess === null) {
            self::markTestSkipped('Serveur HTTP intégré non démarré.');
        }
        // Repart d'un schéma propre/déterministe avant chaque test.
        self::loadSchema(self::$pdo);
    }

    // ── GET /state ─────────────────────────────────────────────────────────

    public function testGetStateReturns200WithGpioStructure(): void
    {
        [$status, $headers, $body] = $this->httpGet('/api/outputs-test/state');

        self::assertSame(200, $status, 'GET state doit répondre 200');
        self::assertStringContainsString('application/json', strtolower($headers['content-type'] ?? ''));

        $json = json_decode($body, true);
        self::assertIsArray($json, 'Le corps doit être un objet JSON');

        // GPIO numériques critiques attendus par l'ESP32.
        foreach ([2, 15, 16, 18, 102, 105] as $gpio) {
            self::assertArrayHasKey((string) $gpio, $json, "GPIO {$gpio} attendu dans la réponse state");
        }
        // La pompe réserve (GPIO 18) vaut 1 dans le schéma de test.
        self::assertSame(1, (int) $json['18']);
        // Témoins « dernier état Data » ajoutés pour la page de contrôle.
        self::assertArrayHasKey('dataStates', $json);
    }

    // ── POST /toggle ───────────────────────────────────────────────────────

    public function testToggleChangesStateInDatabaseAndReturnsOk(): void
    {
        $id = $this->outputIdForGpio(2);
        self::assertGreaterThan(0, $id);
        self::assertSame('0', $this->currentStateById($id), 'État initial GPIO 2 = 0');

        [$status, , $body] = $this->httpPost('/api/outputs-test/toggle', [
            'id' => $id,
            'state' => 1,
            'gpio' => 2,
        ]);

        self::assertSame(200, $status);
        $json = json_decode($body, true);
        self::assertSame('ok', $json['status'] ?? null);
        self::assertSame($id, (int) $json['id']);
        self::assertSame(1, (int) $json['state']);

        // Effet réel persisté en base.
        self::assertSame('1', $this->currentStateById($id), 'GPIO 2 doit valoir 1 après toggle');
        self::assertSame('web', $this->lastModifiedByById($id), 'lastModifiedBy doit être « web »');
    }

    public function testToggleInvalidStateReturns400AndDoesNotPersist(): void
    {
        $id = $this->outputIdForGpio(15);
        self::assertSame('0', $this->currentStateById($id));

        // state=2 invalide (seul 0/1 accepté).
        [$status, , $body] = $this->httpPost('/api/outputs-test/toggle', [
            'id' => $id,
            'state' => 2,
            'gpio' => 15,
        ]);

        self::assertSame(400, $status);
        $json = json_decode($body, true);
        self::assertSame('error', $json['status'] ?? null);
        self::assertSame('0', $this->currentStateById($id), 'Aucune persistance sur paramètres invalides');
    }

    public function testToggleWithoutTokenIsForbiddenByCsrf(): void
    {
        $id = $this->outputIdForGpio(16);

        // Sans ?token= : la requête tombe sous la protection CSRF (cookie/session
        // absent) → 403 JSON. L'état ne doit pas changer.
        [$status, , $body] = $this->httpPost('/api/outputs-test/toggle', [
            'id' => $id,
            'state' => 1,
            'gpio' => 16,
        ], false);

        self::assertSame(403, $status, 'Écriture sans jeton doit être rejetée (CSRF)');
        $json = json_decode($body, true);
        self::assertIsArray($json);
        self::assertFalse($json['success'] ?? true);
        self::assertSame('0', $this->currentStateById($id), 'État inchangé après rejet CSRF');
    }

    public function testToggleAquariumPumpForceOffPersistsEvenWithStaleId(): void
    {
        // GPIO 117 absent du schéma minimal : créé à la volée par ensureAquariumPumpForceRowExists.
        [$status] = $this->httpGet('/api/outputs-test/state?fresh=1');
        self::assertSame(200, $status);

        $gpio117Id = $this->outputIdForGpio(117);
        self::assertGreaterThan(0, $gpio117Id);

        // Activer le forçage avec le bon id.
        [$status, , $body] = $this->httpPost('/api/outputs-test/toggle', [
            'id' => $gpio117Id,
            'state' => 1,
            'gpio' => 117,
        ]);
        self::assertSame(200, $status);
        self::assertSame('1', $this->currentStateByGpio(117));

        // Désactiver avec un id obsolète : doit quand même passer à 0 (update par GPIO).
        [$status, , $body] = $this->httpPost('/api/outputs-test/toggle', [
            'id' => 99999,
            'state' => 0,
            'gpio' => 117,
        ]);
        self::assertSame(200, $status);
        $json = json_decode($body, true);
        self::assertSame('ok', $json['status'] ?? null);
        self::assertSame(0, (int) ($json['state'] ?? -1));
        self::assertSame('0', $this->currentStateByGpio(117), 'GPIO 117 doit être à 0 après désactivation');
    }

    // ── POST /parameters ───────────────────────────────────────────────────

    public function testUpdateParametersPersistsValues(): void
    {
        $gpioAqThreshold = 102; // param « aqThr »
        $gpioBouffeMatin = 105; // param « bouffeMatin »

        self::assertSame('18', $this->currentStateByGpio($gpioAqThreshold));

        [$status, , $body] = $this->httpPost('/api/outputs-test/parameters', [
            'aqThr' => 25,
            'bouffeMatin' => 7,
        ]);

        self::assertSame(200, $status);
        $json = json_decode($body, true);
        self::assertSame('ok', $json['status'] ?? null);
        self::assertGreaterThanOrEqual(2, (int) ($json['updated'] ?? 0), 'Au moins 2 paramètres mis à jour');

        // Persistance réelle.
        self::assertSame('25', $this->currentStateByGpio($gpioAqThreshold));
        self::assertSame('7', $this->currentStateByGpio($gpioBouffeMatin));
        self::assertSame('web', $this->lastModifiedByByGpio($gpioAqThreshold));
    }

    public function testUpdateParametersRejectsOutOfRangeFeedingHour(): void
    {
        $gpioBouffeMatin = 105;
        self::assertSame('8', $this->currentStateByGpio($gpioBouffeMatin));

        // Heure de nourrissage hors plage [0..23] → 400 (validation métier), pas 500.
        [$status, , $body] = $this->httpPost('/api/outputs-test/parameters', [
            'bouffeMatin' => 99,
        ]);

        self::assertSame(400, $status);
        $json = json_decode($body, true);
        self::assertSame('error', $json['status'] ?? null);
        self::assertSame('8', $this->currentStateByGpio($gpioBouffeMatin), 'Aucune persistance sur horaire invalide');
    }

    // ── Helpers BDD ────────────────────────────────────────────────────────

    private function outputIdForGpio(int $gpio): int
    {
        $stmt = self::$pdo->prepare('SELECT id FROM ffp3Outputs2 WHERE gpio = :g LIMIT 1');
        $stmt->execute([':g' => $gpio]);
        return (int) $stmt->fetchColumn();
    }

    private function currentStateById(int $id): string
    {
        $stmt = self::$pdo->prepare('SELECT state FROM ffp3Outputs2 WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return (string) $stmt->fetchColumn();
    }

    private function currentStateByGpio(int $gpio): string
    {
        $stmt = self::$pdo->prepare('SELECT state FROM ffp3Outputs2 WHERE gpio = :g LIMIT 1');
        $stmt->execute([':g' => $gpio]);
        return (string) $stmt->fetchColumn();
    }

    private function lastModifiedByById(int $id): ?string
    {
        $stmt = self::$pdo->prepare('SELECT lastModifiedBy FROM ffp3Outputs2 WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    private function lastModifiedByByGpio(int $gpio): ?string
    {
        $stmt = self::$pdo->prepare('SELECT lastModifiedBy FROM ffp3Outputs2 WHERE gpio = :g LIMIT 1');
        $stmt->execute([':g' => $gpio]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    // ── Helpers HTTP ───────────────────────────────────────────────────────

    /**
     * @return array{0:int, 1:array<string,string>, 2:string} [status, headers, body]
     */
    private function httpGet(string $path): array
    {
        return $this->httpRequest('GET', $this->withToken($path), null);
    }

    /**
     * @param array<string, scalar> $form
     * @return array{0:int, 1:array<string,string>, 2:string} [status, headers, body]
     */
    private function httpPost(string $path, array $form, bool $withToken = true): array
    {
        $url = $withToken ? $this->withToken($path) : $path;
        return $this->httpRequest('POST', $url, http_build_query($form));
    }

    private function withToken(string $path): string
    {
        $sep = str_contains($path, '?') ? '&' : '?';
        return $path . $sep . 'token=' . rawurlencode(self::TOKEN);
    }

    /**
     * @return array{0:int, 1:array<string,string>, 2:string} [status, headers, body]
     */
    private function httpRequest(string $method, string $path, ?string $body): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            self::fail("Requête HTTP {$method} {$path} échouée : {$err}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, $headers, (string) $resp];
    }

    // ── Bootstrap BDD / serveur ────────────────────────────────────────────

    private static function tryConnect(): ?PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            return null;
        }
        $host = getenv('DB_HOST') ?: '';
        $name = getenv('DB_NAME') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS');
        $port = getenv('DB_PORT') ?: '3306';
        if ($host === '' || $name === '' || $user === '') {
            return null;
        }
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
        try {
            return new PDO($dsn, $user, $pass === false ? '' : $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException) {
            return null;
        }
    }

    private static function loadSchema(PDO $pdo): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/schema.sql');
        // Exécute les instructions une à une (MySQL via PDO ne gère pas toujours
        // le multi-statements selon le driver/buffered).
        foreach (self::splitSql($sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    /**
     * Découpe naïf par « ; » en fin de ligne — suffisant pour notre schéma sans
     * procédures stockées ni « ; » dans des littéraux.
     *
     * @return list<string>
     */
    private static function splitSql(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $statements = [];
        $current = '';
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $current .= $line . "\n";
            if (str_ends_with($trimmed, ';')) {
                $statements[] = $current;
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $statements[] = $current;
        }
        return $statements;
    }

    private static function startServer(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $docRoot = $projectRoot . '/public';

        $host = '127.0.0.1';
        $port = self::pickFreePort($host);
        self::$baseUrl = sprintf('http://%s:%d', $host, $port);

        // Environnement applicatif passé au serveur intégré.
        $env = array_merge($_ENV, getenv(), [
            'ENV' => self::ENV,
            'AUTH_METHOD' => 'both',
            'ADMIN_TOKEN' => self::TOKEN,
            'CACHE_ENABLED' => 'false',
            'APP_TIMEZONE' => 'Europe/Paris',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cmd = sprintf(
            '%s -d display_errors=0 -S %s:%d -t %s',
            escapeshellarg(PHP_BINARY),
            $host,
            $port,
            escapeshellarg($docRoot)
        );

        $process = proc_open($cmd, $descriptors, self::$pipes, $projectRoot, $env);
        if (!is_resource($process)) {
            self::markTestSkipped('Impossible de démarrer le serveur HTTP intégré (proc_open).');
        }
        self::$serverProcess = $process;

        // Attendre que le serveur réponde (jusqu'à ~5 s).
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $ch = curl_init(self::$baseUrl . '/ping');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_NOBODY => false,
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($resp !== false && $code === 200) {
                $ready = true;
                break;
            }
            usleep(100_000);
        }

        if (!$ready) {
            self::stopServer();
            self::markTestSkipped('Le serveur HTTP intégré n\'a pas répondu à /ping.');
        }
    }

    private static function pickFreePort(string $host): int
    {
        $sock = @stream_socket_server('tcp://' . $host . ':0', $errno, $errstr);
        if ($sock === false) {
            return 8089; // repli raisonnable
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $parts = explode(':', (string) $name);
        return (int) end($parts);
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            foreach (self::$pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close(self::$serverProcess);
        }
        self::$serverProcess = null;
        self::$pipes = [];
    }
}
