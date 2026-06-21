<?php

declare(strict_types=1);

namespace Tests\Controller\Msp;

use App\Config\TableConfig;
use App\Controller\Msp\MspHeartbeatController;
use App\Controller\N3pp\N3ppHeartbeatController;
use App\Service\LogService;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Couvre la factorisation portée par AbstractHeartbeatController (construction du
 * LegacyHeartbeatHandler + délégation de handle()), vue à travers les deux
 * sous-classes concrètes MSP1 et N3PP, sur SQLite en mémoire.
 *
 * Vérifie le contrat firmware : auth api_key, validation des champs, insertion en
 * table cible (résolue via TableConfig) et codes HTTP inchangés.
 */
final class MspHeartbeatControllerTest extends TestCase
{
    private string $previousApiKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        $this->previousApiKey = $_ENV['API_KEY'] ?? '';
        $_ENV['API_KEY'] = 'test-hb-key';
        TableConfig::setEnvironment('prod');
    }

    protected function tearDown(): void
    {
        $_ENV['API_KEY'] = $this->previousApiKey;
        TableConfig::setEnvironment('prod');
        parent::tearDown();
    }

    private function pdoWithTable(string $table): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE `{$table}` (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uptime INTEGER NOT NULL,
            freeHeap INTEGER NOT NULL,
            minHeap INTEGER NOT NULL,
            reboots INTEGER NOT NULL,
            rssi INTEGER,
            sensor TEXT,
            version TEXT,
            reading_time TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        return $pdo;
    }

    private function response()
    {
        return (new ResponseFactory())->createResponse();
    }

    private function postRequest(array $body)
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/heartbeat')
            ->withParsedBody($body);
    }

    public function testMspRejectsInvalidApiKey(): void
    {
        $pdo = $this->pdoWithTable(TableConfig::getMspHeartbeatTable());
        $controller = new MspHeartbeatController($this->createMock(LogService::class), $pdo);

        $result = $controller->handle($this->postRequest([
            'api_key' => 'wrong',
            'uptime' => '100',
            'free' => '50000',
            'min' => '40000',
            'reboots' => '1',
        ]), $this->response());

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM `' . TableConfig::getMspHeartbeatTable() . '`')->fetchColumn());
    }

    public function testMspRejectsMissingFields(): void
    {
        $pdo = $this->pdoWithTable(TableConfig::getMspHeartbeatTable());
        $controller = new MspHeartbeatController($this->createMock(LogService::class), $pdo);

        $result = $controller->handle($this->postRequest([
            'api_key' => 'test-hb-key',
            'uptime' => '100',
        ]), $this->response());

        $this->assertSame(400, $result->getStatusCode());
    }

    public function testMspInsertsIntoConfiguredTableOnValidPayload(): void
    {
        $table = TableConfig::getMspHeartbeatTable();
        $pdo = $this->pdoWithTable($table);
        $controller = new MspHeartbeatController($this->createMock(LogService::class), $pdo);

        $result = $controller->handle($this->postRequest([
            'api_key' => 'test-hb-key',
            'sensor' => 'msp1',
            'version' => '4.38',
            'uptime' => '3600',
            'free' => '120000',
            'min' => '80000',
            'reboots' => '3',
            'rssi' => '-65',
        ]), $this->response());

        $this->assertSame(200, $result->getStatusCode());
        $this->assertStringContainsString('OK', (string) $result->getBody());

        $row = $pdo->query("SELECT * FROM `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(3600, (int) $row['uptime']);
        $this->assertSame(120000, (int) $row['freeHeap']);
        $this->assertSame(-65, (int) $row['rssi']);
        $this->assertSame('msp1', $row['sensor']);
    }

    public function testN3ppInsertsIntoN3ppTable(): void
    {
        $table = TableConfig::getN3ppHeartbeatTable();
        $pdo = $this->pdoWithTable($table);
        $controller = new N3ppHeartbeatController($this->createMock(LogService::class), $pdo);

        $result = $controller->handle($this->postRequest([
            'api_key' => 'test-hb-key',
            'sensor' => 'n3pp',
            'version' => '4.38',
            'uptime' => '7200',
            'free' => '90000',
            'min' => '70000',
            'reboots' => '5',
        ]), $this->response());

        $this->assertSame(200, $result->getStatusCode());
        $row = $pdo->query("SELECT * FROM `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(7200, (int) $row['uptime']);
        $this->assertSame('n3pp', $row['sensor']);
    }

    public function testRejectsNonPost(): void
    {
        $pdo = $this->pdoWithTable(TableConfig::getMspHeartbeatTable());
        $controller = new MspHeartbeatController($this->createMock(LogService::class), $pdo);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/heartbeat');
        $result = $controller->handle($request, $this->response());

        $this->assertSame(405, $result->getStatusCode());
    }
}
