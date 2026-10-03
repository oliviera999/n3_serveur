<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Config\TableConfig;
use DI\ContainerBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\EnergieSqliteSchema;

/**
 * Bout en bout des routes `config/routes_energie.php` : vraie application Slim,
 * vrai container (config/dependencies.php, PDO remplacé par SQLite en mémoire),
 * EnvironmentMiddleware par préfixe — POST firmware, API temps réel et page.
 */
final class EnergieRoutesTest extends TestCase
{
    private const API_KEY = 'energie-routes-key';

    private PDO $pdo;
    /** @var array<string, string|null> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        foreach (['API_KEY', 'API_SIG_SECRET', 'HMAC_STRICT_MODE', 'FIRMWARE_RATE_LIMIT_MAX'] as $key) {
            $this->previousEnv[$key] = isset($_ENV[$key]) ? (string) $_ENV[$key] : null;
            unset($_ENV[$key]);
        }
        $_ENV['API_KEY'] = self::API_KEY;

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        EnergieSqliteSchema::create($this->pdo);
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

    /**
     * @return App<\Psr\Container\ContainerInterface|null>
     */
    private function app(): App
    {
        $pdo = $this->pdo;
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions(__DIR__ . '/../../config/dependencies.php');
        $builder->addDefinitions([PDO::class => static fn (): PDO => $pdo]);

        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        require __DIR__ . '/../../config/routes_energie.php';
        $app->addRoutingMiddleware();

        return $app;
    }

    /**
     * @param array<string, string>|null $body
     */
    private function request(App $app, string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withParsedBody($body);
        }
        $response = $app->handle($request);
        TableConfig::resetRequestEnvironment();

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($data, (string) $response->getBody());

        return $data;
    }

    public function testFirmwarePostThenRealtimeApiOnTestPrefix(): void
    {
        $app = $this->app();

        $post = $this->request($app, 'POST', '/energie-test/post-data', [
            'api_key' => self::API_KEY,
            'sensor' => 'energie',
            'version' => '0.3.1',
            'PanneauP' => '21.5',
            'BatterieI' => '-0.42',
            'BatterieSoc' => '81',
        ]);
        $this->assertSame(200, $post->getStatusCode(), (string) $post->getBody());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM energieDataTest')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM energieData')->fetchColumn());

        $latest = $this->json($this->request($app, 'GET', '/energie-test/api/realtime/sensors/latest'));
        $this->assertEqualsWithDelta(21.5, (float) $latest['sensors']['PanneauP'], 1e-9);
        $this->assertEqualsWithDelta(-0.42, (float) $latest['sensors']['BatterieI'], 1e-9);
        $this->assertArrayHasKey('Uptime', $latest['sensors']);

        $since = $this->json($this->request($app, 'GET', '/energie-test/api/realtime/sensors/since/' . (time() - 60)));
        $this->assertSame(1, $since['count']);

        $health = $this->json($this->request($app, 'GET', '/energie-test/api/realtime/system/health'));
        $this->assertTrue($health['online']);
        $this->assertSame(1, $health['readings_today']);

        $outputs = $this->json($this->request($app, 'GET', '/energie-test/api/realtime/outputs/state'));
        $this->assertSame([], $outputs['outputs']);

        $alerts = $this->json($this->request($app, 'GET', '/energie-test/api/realtime/alerts/active'));
        $this->assertSame(0, $alerts['count']);

        // Le préfixe prod lit sa propre table (vide).
        $prodLatest = $this->json($this->request($app, 'GET', '/energie/api/realtime/sensors/latest'));
        $this->assertSame([], $prodLatest['sensors']);
    }

    public function testProdPrefixWritesProdTableAndRejectsBadKey(): void
    {
        $app = $this->app();

        $bad = $this->request($app, 'POST', '/energie/post-data', [
            'api_key' => 'wrong', 'sensor' => 'energie', 'version' => '0.3.1',
        ]);
        $this->assertSame(401, $bad->getStatusCode());

        $ok = $this->request($app, 'POST', '/energie/post-data', [
            'api_key' => self::API_KEY, 'sensor' => 'energie', 'version' => '0.3.1', 'ConsoP' => '4.2',
        ]);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM energieData')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM energieDataTest')->fetchColumn());
    }

    public function testDataPageRendersOnBothPrefixes(): void
    {
        $app = $this->app();
        $this->request($app, 'POST', '/energie-test/post-data', [
            'api_key' => self::API_KEY, 'sensor' => 'energie', 'version' => '0.3.1', 'PanneauP' => '12.5',
        ]);

        $test = $this->request($app, 'GET', '/energie-test');
        $this->assertSame(200, $test->getStatusCode());
        $html = (string) $test->getBody();
        $this->assertStringContainsString('Banc énergie', $html);
        $this->assertStringContainsString('data-sensor="PanneauP"', $html);
        $this->assertStringContainsString('energieDataTest', $html);

        $prod = $this->request($app, 'GET', '/energie');
        $this->assertSame(200, $prod->getStatusCode());
        $this->assertStringContainsString('Aucune donnée enregistrée', (string) $prod->getBody());
    }
}
