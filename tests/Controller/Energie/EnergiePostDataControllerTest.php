<?php

declare(strict_types=1);

namespace Tests\Controller\Energie;

use App\Config\TableConfig;
use App\Controller\Energie\EnergiePostDataController;
use App\Middleware\RawPostBodyMiddleware;
use App\Repository\EnergieSensorRepository;
use App\Security\SignatureValidator;
use App\Service\LogService;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\EnergieSqliteSchema;

/**
 * Contrat POST du banc énergie (`/energie[-test]/post-data`) : auth HMAC / api_key
 * (même schéma que MSP1/N3PP), validation sensor/version, champs numériques
 * optionnels (NULL si absents ou non numériques) et routage prod / energie_test
 * vers la bonne table — sur SQLite en mémoire avec le VRAI repository.
 */
final class EnergiePostDataControllerTest extends TestCase
{
    private const API_KEY = 'energie-test-key';
    private const SIG_SECRET = 'energie-hmac-secret';

    /** @var array<string, string|null> */
    private array $previousEnv = [];
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        foreach (['API_KEY', 'API_SIG_SECRET', 'HMAC_STRICT_MODE', 'HMAC_NONCE_REQUIRED', 'SIG_VALID_WINDOW', 'FIRMWARE_RATE_LIMIT_MAX'] as $key) {
            $this->previousEnv[$key] = isset($_ENV[$key]) ? (string) $_ENV[$key] : null;
            unset($_ENV[$key]);
        }
        $_ENV['API_KEY'] = self::API_KEY;
        $_ENV['HMAC_STRICT_MODE'] = 'false';
        $_ENV['HMAC_NONCE_REQUIRED'] = 'false';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        EnergieSqliteSchema::create($this->pdo);

        TableConfig::setEnvironment('energie_test');
    }

    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
        foreach ($this->previousEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    private function controller(): EnergiePostDataController
    {
        return new EnergiePostDataController(
            $this->createMock(LogService::class),
            null,
            null,
            null,
            new EnergieSensorRepository($this->pdo),
        );
    }

    /**
     * @param array<string, string> $body
     */
    private function post(array $body, string $path = '/energie-test/post-data'): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($body);
    }

    private function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->controller()->handle($request, (new ResponseFactory())->createResponse());
    }

    /**
     * @return array<string, string>
     */
    private function validPayload(): array
    {
        return [
            'api_key' => self::API_KEY,
            'sensor' => 'energie',
            'version' => '0.3.1',
            'PanneauV' => '18.42',
            'PanneauI' => '1.234',
            'PanneauP' => '22.73',
            'PanneauImax' => '1.5',
            'BatterieV' => '12.81',
            'BatterieVmin' => '12.70',
            'BatterieI' => '-0.512',
            'BatterieImin' => '-0.9',
            'BatterieImax' => '0.2',
            'BatterieP' => '-6.56',
            'BatterieVadc' => '12.75',
            'ConsoV' => '12.60',
            'ConsoI' => '0.480',
            'ConsoP' => '6.05',
            'ConsoImax' => '0.61',
            'EnergiePanneauWh' => '0.0631',
            'EnergieConsoWh' => '0.0168',
            'BatterieAh' => '-1.234',
            'BatterieSoc' => '78',
            'InaStatus' => '7',
            'I2cErreurs' => '0',
            'Rssi' => '-61',
            'FreeHeap' => '183456',
            'BootCount' => '4',
            'Uptime' => '86400',
        ];
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRow(string $table): array
    {
        $row = $this->pdo->query("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        return $row;
    }

    public function testValidApiKeyInsertsRowInTestTable(): void
    {
        $response = $this->handle($this->post($this->validPayload()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Donnees enregistrees', (string) $response->getBody());
        $this->assertSame(1, $this->countRows('energieDataTest'));
        $this->assertSame(0, $this->countRows('energieData'));

        $row = $this->lastRow('energieDataTest');
        $this->assertSame('energie', $row['sensor']);
        $this->assertSame('0.3.1', $row['version']);
        $this->assertEqualsWithDelta(18.42, (float) $row['PanneauV'], 1e-9);
        $this->assertEqualsWithDelta(-0.512, (float) $row['BatterieI'], 1e-9);
        $this->assertEqualsWithDelta(-6.56, (float) $row['BatterieP'], 1e-9);
        $this->assertEqualsWithDelta(0.0631, (float) $row['EnergiePanneauWh'], 1e-9);
        $this->assertEqualsWithDelta(78.0, (float) $row['BatterieSoc'], 1e-9);
        $this->assertSame(7, (int) $row['InaStatus']);
        $this->assertSame(-61, (int) $row['Rssi']);
        $this->assertSame(86400, (int) $row['Uptime']);
        $this->assertNotEmpty($row['reading_time']);
    }

    public function testProdEnvironmentWritesProdTable(): void
    {
        TableConfig::setEnvironment('prod');

        $response = $this->handle($this->post($this->validPayload(), '/energie/post-data'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->countRows('energieData'));
        $this->assertSame(0, $this->countRows('energieDataTest'));
    }

    public function testInvalidApiKeyIsRejected(): void
    {
        $payload = $this->validPayload();
        $payload['api_key'] = 'wrong';

        $response = $this->handle($this->post($payload));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(0, $this->countRows('energieDataTest'));
    }

    public function testMissingApiKeyAndSignatureIsRejected(): void
    {
        $payload = $this->validPayload();
        unset($payload['api_key']);

        $this->assertSame(401, $this->handle($this->post($payload))->getStatusCode());
        $this->assertSame(0, $this->countRows('energieDataTest'));
    }

    public function testValidLegacyHmacAuthenticatesEvenWithWrongApiKey(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;
        $ts = time();
        $payload = $this->validPayload();
        $payload['api_key'] = 'wrong';
        $payload['timestamp'] = (string) $ts;
        $payload['signature'] = SignatureValidator::createSignature($ts, self::SIG_SECRET);

        $this->assertSame(200, $this->handle($this->post($payload))->getStatusCode());
        $this->assertSame(1, $this->countRows('energieDataTest'));
    }

    public function testInvalidLegacyHmacIsRejected(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;
        $payload = $this->validPayload();
        $payload['timestamp'] = (string) time();
        $payload['signature'] = str_repeat('0', 64);

        $this->assertSame(401, $this->handle($this->post($payload))->getStatusCode());
        $this->assertSame(0, $this->countRows('energieDataTest'));
    }

    public function testIncompleteLegacyHmacIsRejected(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;
        $payload = $this->validPayload();
        $payload['timestamp'] = (string) time();

        $this->assertSame(401, $this->handle($this->post($payload))->getStatusCode());
    }

    public function testValidXSigHeadersAuthenticateEvenWithWrongApiKey(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;
        $payload = $this->validPayload();
        $payload['api_key'] = 'wrong';
        $rawBody = http_build_query($payload);
        $ts = time();
        $nonce = $ts . '-42';
        $signature = SignatureValidator::createSignatureForBody($ts, $nonce, $rawBody, self::SIG_SECRET);

        $request = $this->post($payload)
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $rawBody)
            ->withHeader('X-Sig-Timestamp', (string) $ts)
            ->withHeader('X-Sig-Nonce', $nonce)
            ->withHeader('X-Sig-Hmac', $signature);

        $this->assertSame(200, $this->handle($request)->getStatusCode());
        $this->assertSame(1, $this->countRows('energieDataTest'));
    }

    public function testInvalidXSigHeadersFallBackToApiKeyAndRejectWrongKey(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;
        $payload = $this->validPayload();
        $payload['api_key'] = 'wrong';
        $rawBody = http_build_query($payload);

        $request = $this->post($payload)
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $rawBody)
            ->withHeader('X-Sig-Timestamp', (string) time())
            ->withHeader('X-Sig-Nonce', 'n-1')
            ->withHeader('X-Sig-Hmac', str_repeat('a', 64));

        // Repli non strict (comme MSP1/N3PP) : signature X-Sig invalide → api_key, ici fausse → 401.
        $this->assertSame(401, $this->handle($request)->getStatusCode());
        $this->assertSame(0, $this->countRows('energieDataTest'));
    }

    public function testInvalidXSigHeadersFallBackToValidApiKey(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;
        $payload = $this->validPayload();
        $rawBody = http_build_query($payload);

        $request = $this->post($payload)
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $rawBody)
            ->withHeader('X-Sig-Timestamp', (string) time())
            ->withHeader('X-Sig-Nonce', 'n-1')
            ->withHeader('X-Sig-Hmac', str_repeat('a', 64));

        $this->assertSame(200, $this->handle($request)->getStatusCode());
        $this->assertSame(1, $this->countRows('energieDataTest'));
    }

    public function testInvalidXSigHeadersRejectedInStrictMode(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;
        $_ENV['HMAC_STRICT_MODE'] = 'true';
        $payload = $this->validPayload();
        $rawBody = http_build_query($payload);

        $request = $this->post($payload)
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $rawBody)
            ->withHeader('X-Sig-Timestamp', (string) time())
            ->withHeader('X-Sig-Nonce', 'n-1')
            ->withHeader('X-Sig-Hmac', str_repeat('a', 64));

        $this->assertSame(401, $this->handle($request)->getStatusCode());
        $this->assertSame(0, $this->countRows('energieDataTest'));
    }

    public function testSignatureSentWithoutServerSecretReturns500(): void
    {
        $payload = $this->validPayload();
        $payload['timestamp'] = (string) time();
        $payload['signature'] = str_repeat('a', 64);

        $this->assertSame(500, $this->handle($this->post($payload))->getStatusCode());
    }

    public function testMissingSensorIsRejected(): void
    {
        $payload = $this->validPayload();
        unset($payload['sensor']);

        $response = $this->handle($this->post($payload));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->countRows('energieDataTest'));
    }

    public function testMissingVersionIsRejected(): void
    {
        $payload = $this->validPayload();
        $payload['version'] = '   ';

        $this->assertSame(400, $this->handle($this->post($payload))->getStatusCode());
        $this->assertSame(0, $this->countRows('energieDataTest'));
    }

    public function testEmptyBodyIsRejected(): void
    {
        $this->assertSame(400, $this->handle($this->post([]))->getStatusCode());
    }

    public function testGetIsRejected(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/energie-test/post-data');

        $this->assertSame(405, $this->handle($request)->getStatusCode());
    }

    public function testNonNumericAndAbsentFieldsAreStoredAsNull(): void
    {
        $payload = [
            'api_key' => self::API_KEY,
            'sensor' => str_repeat('s', 40),
            'version' => '0.3.1',
            'PanneauV' => 'nan-ish',
            'BatterieV' => '',
            'BatterieSoc' => 'abc',
            'InaStatus' => 'x7',
            'Rssi' => '-70',
        ];

        $this->assertSame(200, $this->handle($this->post($payload))->getStatusCode());

        $row = $this->lastRow('energieDataTest');
        $this->assertSame(30, strlen((string) $row['sensor']), 'sensor tronqué à 30 caractères (VARCHAR(30))');
        $this->assertNull($row['PanneauV']);
        $this->assertNull($row['BatterieV']);
        $this->assertNull($row['BatterieSoc']);
        $this->assertNull($row['InaStatus']);
        $this->assertNull($row['ConsoP'], 'champ absent → NULL');
        $this->assertNull($row['Uptime'], 'champ absent → NULL');
        $this->assertSame(-70, (int) $row['Rssi']);
    }
}
