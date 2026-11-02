<?php

declare(strict_types=1);

namespace Tests\Mcp;

use App\Config\TableConfig;
use App\Mcp\Control\ControlPolicy;
use App\Mcp\Control\OutputWriter;
use App\Mcp\McpConfig;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Support\ActiveAlertsReader;
use App\Mcp\Support\FieldCatalog;
use App\Mcp\Support\GalleryReader;
use App\Mcp\Support\ReadOnlyTransaction;
use App\Mcp\Support\TimeWindow;
use App\Mcp\ToolException;
use App\Mcp\ToolRegistry;
use App\Mcp\Tools\AlertsTool;
use App\Mcp\Tools\ApplyPlanTool;
use App\Mcp\Tools\DescribeTool;
use App\Mcp\Tools\GalleryLatestTool;
use App\Mcp\Tools\GalleryListTool;
use App\Mcp\Tools\LatestTool;
use App\Mcp\Tools\OutputsStateTool;
use App\Mcp\Tools\OverviewTool;
use App\Mcp\Tools\PlanOutputChangeTool;
use App\Mcp\Tools\SeriesTool;
use App\Mcp\Tools\StatsTool;
use App\Repository\BoardRepository;
use App\Repository\McpOAuthRepository;
use App\Repository\McpReadRepository;
use App\Repository\MspOutputRepository;
use App\Repository\N3ppOutputRepository;
use App\Repository\OutputMonitorRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use App\Service\OfflineThresholdResolver;
use App\Service\OutputService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

/**
 * Outils MCP contre une base SQLite : conversions d'unités, agrégation par bucket,
 * absence de données personnelles / secrets, refus d'écriture des outils de lecture,
 * pilotage (désactivé par défaut, plan → apply sur environnement de test, garde-fous).
 */
final class McpToolsTest extends TestCase
{
    private PDO $pdo;
    private string $tmp;

    protected function setUp(): void
    {
        $this->pdo = McpSqliteFixture::pdo();
        // Les repositories FFP3 historiques écrivent `NOW()` (MySQL) : équivalent SQLite.
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
        McpSqliteFixture::seedFfp3($this->pdo);
        McpSqliteFixture::seedFfp3($this->pdo, 'ffp3Data2', 3);
        McpSqliteFixture::seedOutputs($this->pdo, 'ffp3Outputs');
        McpSqliteFixture::seedOutputs($this->pdo, 'ffp3Outputs2');
        $this->tmp = sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/var/cache', 0777, true);
    }

    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private static function ctx(string $role = 'reader', array $scopes = ['iot.read']): McpContext
    {
        $ids = ['reader' => 1, 'operator' => 2, 'admin' => 3];

        return new McpContext($ids[$role] ?? 1, $role, $role, $scopes, 'client-test');
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(McpServer $server, string $tool, array $arguments = [], ?McpContext $ctx = null): array
    {
        $r = $server->handle([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments],
        ], $ctx ?? self::ctx());

        return $r['result'];
    }

    private static function data(array $result): array
    {
        return json_decode($result['content'][0]['text'], true);
    }

    private function server(?McpConfig $config = null): McpServer
    {
        $config ??= McpSqliteFixture::config(['MCP_ALLOWED_ENVS' => 'prod,s3,test']);
        $read = new McpReadRepository($this->pdo);
        $store = new McpOAuthRepository($this->pdo);
        $boards = new BoardRepository($this->pdo);
        $writer = new OutputWriter(
            new OutputService(new OutputRepository($this->pdo), $boards, new SensorReadRepository($this->pdo)),
            new OutputRepository($this->pdo),
            new N3ppOutputRepository($this->pdo, $boards),
            new MspOutputRepository($this->pdo, $boards)
        );
        $audit = $this->createMock(ControlAuditLogger::class);
        $alerts = new ActiveAlertsReader($this->tmp . '/var/cache', $this->tmp . '/flag');
        $gallery = new GalleryReader($this->tmp);
        $tools = [
            new OverviewTool($config, $read, new OfflineThresholdResolver(new OutputMonitorRepository($this->pdo)), $alerts),
            new DescribeTool($config),
            new LatestTool($config, $read),
            new SeriesTool($config, $read),
            new StatsTool($config, $read),
            new OutputsStateTool($config, $read),
            new AlertsTool($config, $read, $alerts),
            new GalleryListTool($config, $gallery),
            new GalleryLatestTool($config, $gallery),
            new PlanOutputChangeTool($config, $read, $store),
            new ApplyPlanTool($config, $store, $read, $writer, $audit),
        ];

        return new McpServer(new ToolRegistry($tools, $config), new ReadOnlyTransaction($this->pdo), $this->createMock(LogService::class), $config);
    }

    // ── Lecture ───────────────────────────────────────────────────────

    public function testLatestConvertsWaterLevelsAndOmitsPersonalData(): void
    {
        $result = $this->call($this->server(), 'iot_latest', ['family' => 'ffp3']);
        self::assertFalse($result['isError']);
        $data = self::data($result);
        self::assertSame('prod', $data['env']);
        self::assertSame(18.0, $data['values']['EauAquarium']['value'], '180 mm → 18 cm');
        self::assertSame('cm', $data['values']['EauAquarium']['unit']);
        self::assertSame('mm', $data['values']['diffMaree']['unit']);
        self::assertArrayNotHasKey('mail', $data['values']);
        self::assertStringNotContainsString('secret@example.org', $result['content'][0]['text']);
    }

    public function testLatestOnTestEnvReadsSuffixedTable(): void
    {
        $data = self::data($this->call($this->server(), 'iot_latest', ['family' => 'ffp3', 'env' => 'test']));
        self::assertSame('test', $data['env']);
        self::assertFalse($data['is_production']);
        self::assertSame(TableConfig::getDefaultEnvironment(), TableConfig::getEnvironment());
    }

    public function testEnvOutsideAllowListIsRefused(): void
    {
        $result = $this->call($this->server(McpSqliteFixture::config()), 'iot_latest', ['family' => 'ffp3', 'env' => 'test']);
        self::assertTrue($result['isError']);
    }

    public function testSeriesAggregatesInBucketsAndComputesStats(): void
    {
        $data = self::data($this->call($this->server(), 'iot_series', [
            'family' => 'ffp3', 'fields' => ['TempEau', 'EauAquarium'], 'from' => '-3h', 'interval' => '1h',
        ]));
        self::assertSame('1h', $data['interval']);
        self::assertGreaterThanOrEqual(2, $data['points_count']);
        self::assertLessThanOrEqual(4, $data['points_count']);
        self::assertSame(array_sum(array_column($data['points'], 'n')), 12, 'toutes les mesures dans les buckets');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:00:00$/', $data['points'][0]['t'], 'bucket aligné sur l\'heure');
        self::assertSame('cm', $data['stats']['EauAquarium']['unit']);
        self::assertGreaterThanOrEqual(18.0, $data['stats']['EauAquarium']['min']);
        self::assertLessThanOrEqual(21.0, $data['stats']['EauAquarium']['max']);
    }

    public function testSeriesRejectsUnknownFieldWindowTooLargeAndTooManyPoints(): void
    {
        $server = $this->server();
        $r = $this->call($server, 'iot_series', ['family' => 'n3pp', 'fields' => ['EauAquarium']]);
        self::assertSame('unknown_field', self::data($r)['code']);
        $r = $this->call($server, 'iot_series', ['family' => 'ffp3', 'fields' => ['TempEau'], 'from' => '-200d']);
        self::assertSame('window_too_large', self::data($r)['code']);
        $r = $this->call($server, 'iot_series', ['family' => 'ffp3', 'fields' => ['TempEau'], 'from' => '-30d', 'interval' => '5min']);
        self::assertSame('too_many_points', self::data($r)['code']);
    }

    public function testAutoIntervalKeepsAtMost500Points(): void
    {
        foreach ([3600 => '5min', 86400 => '5min', 7 * 86400 => '1h', 179 * 86400 => '1d'] as $span => $expected) {
            $now = new \DateTimeImmutable('2026-11-02 12:00:00');
            $window = TimeWindow::parse(null, null, $span, 180, $now);
            $interval = $window->resolveInterval('auto');
            self::assertSame($expected, $interval['name'], 'span ' . $span);
            self::assertLessThanOrEqual(TimeWindow::MAX_POINTS, (int) ceil($span / $interval['seconds']));
        }
    }

    public function testBucketToDateIsWallClock(): void
    {
        self::assertSame('2000-01-01 00:00:00', McpReadRepository::bucketToDate(0));
        self::assertSame('2026-03-29 03:00:00', McpReadRepository::bucketToDate(
            (new \DateTimeImmutable('2026-03-29 03:00:00', new \DateTimeZone('UTC')))->getTimestamp() - 946684800
        ));
    }

    public function testStatsCountsReadingsPerField(): void
    {
        $data = self::data($this->call($this->server(), 'iot_stats', ['family' => 'ffp3', 'fields' => ['TempEau', 'Luminosite']]));
        self::assertSame(12, $data['readings']);
        self::assertSame(12, $data['fields']['TempEau']['count']);
        self::assertSame(['Luminosite'], $data['empty_fields']);
    }

    public function testOverviewReportsModulesAndActiveAlerts(): void
    {
        file_put_contents($this->tmp . '/var/cache/derived_alerts_n3pp.json', json_encode(['soilDry' => true, 'batteryLow' => true]));
        file_put_contents($this->tmp . '/var/cache/availability_state.json', json_encode(['heartbeat:offline:MSP1' => ['status' => 'offline', 'since' => time() - 7200]]));
        $data = self::data($this->call($this->server(), 'iot_overview'));
        $byFamily = array_column($data['modules'], null, 'family');
        self::assertSame('en_ligne', $byFamily['ffp3']['status']);
        self::assertSame('15.31', $byFamily['ffp3']['firmware_version']);
        self::assertSame(18.0, $byFamily['ffp3']['latest']['EauAquarium']['value']);
        self::assertSame('jamais_vu', $byFamily['n3pp']['status']);
        self::assertArrayHasKey('pgl', $byFamily);
        self::assertSame(3, $data['active_alerts_total']);
        self::assertSame(2, $byFamily['n3pp']['active_alerts']);
    }

    public function testOutputsStateHidesEmailGpios(): void
    {
        $result = $this->call($this->server(), 'iot_outputs_state', ['family' => 'ffp3']);
        $data = self::data($result);
        $gpios = array_merge(array_column($data['actuators'], 'gpio'), array_column($data['parameters'], 'gpio'));
        self::assertContains(16, $gpios);
        self::assertNotContains(100, $gpios);
        self::assertNotContains(101, $gpios);
        self::assertStringNotContainsString('secret@example.org', $result['content'][0]['text']);
        self::assertSame('esp32', $data['actuators'][0]['changed_by']);
    }

    public function testAlertsNeverExposeRecipientsAndRedactSecrets(): void
    {
        $this->pdo->exec("INSERT INTO notification_log (alert_key, severity, category, recipient, subject, sent_at) VALUES"
            . " ('ffp3:flood', 'critical', 'hydraulic', 'parent@example.org', 'Trop-plein — prévenir prof@example.org', '" . date('Y-m-d H:i:s') . "')");
        $this->pdo->exec("INSERT INTO error_alerts (message, normalized_message, created_at) VALUES"
            . " ('x', 'PDO échec password=hunter2 token abcdefabcdefabcdefabcdefabcdefabcdef12', '" . date('Y-m-d H:i:s') . "')");
        $result = $this->call($this->server(), 'iot_alerts', ['family' => 'ffp3']);
        $text = $result['content'][0]['text'];
        self::assertStringNotContainsString('parent@example.org', $text);
        self::assertStringNotContainsString('prof@example.org', $text);
        self::assertStringNotContainsString('hunter2', $text);
        self::assertStringNotContainsString('abcdefabcdefabcdefabcdefabcdefabcdef12', $text);
        $data = self::data($result);
        self::assertSame('ffp3:flood', $data['notifications'][0]['key']);
        self::assertSame(1, $data['server_errors'][0]['occurrences']);
    }

    public function testDescribeExplainsKeyFieldsAndGpios(): void
    {
        $data = self::data($this->call($this->server(), 'iot_describe', ['family' => 'ffp3']));
        self::assertStringContainsString('DISTANCE', $data['families']['ffp3']['fields']['EauAquarium']['meaning']);
        self::assertSame('Pompe aquarium (etatPompeAqua)', $data['families']['ffp3']['gpio']['16']);
        self::assertArrayNotHasKey('mail', $data['families']['ffp3']['fields']);
        $all = self::data($this->call($this->server(), 'iot_describe'));
        self::assertSame(FieldCatalog::FAMILIES, array_keys($all['families']));
    }

    public function testGalleryLatestReturnsResizedJpegImage(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD indisponible');
        }
        mkdir($this->tmp . '/uploads/ffp3', 0777, true);
        $image = imagecreatetruecolor(1600, 1200);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 160, 70));
        imagejpeg($image, $this->tmp . '/uploads/ffp3/0000000002_2026-11-02_10-00-00_a.jpg', 95);
        imagejpeg($image, $this->tmp . '/uploads/ffp3/0000000001_2026-11-01_10-00-00_b.jpg', 95);
        file_put_contents($this->tmp . '/uploads/ffp3/notes.txt', 'ignorer');
        $_ENV['GALLERY_FFP3_DIR'] = 'uploads/ffp3';

        $result = $this->call($this->server(), 'iot_gallery_latest', ['slug' => 'ffp3', 'previous' => true]);
        self::assertFalse($result['isError']);
        $images = array_values(array_filter($result['content'], static fn (array $c): bool => $c['type'] === 'image'));
        self::assertCount(2, $images);
        self::assertSame('image/jpeg', $images[0]['mimeType']);
        $decoded = (string) base64_decode($images[0]['data'], true);
        self::assertLessThan(1_000_000, strlen($decoded));
        $size = getimagesizefromstring($decoded);
        self::assertSame(800, $size[0]);
        self::assertSame('0000000002_2026-11-02_10-00-00_a.jpg', self::data($result)['photos'][0]['filename']);

        $list = self::data($this->call($this->server(), 'iot_gallery_list', ['slug' => 'ffp3', 'per_page' => 1]));
        self::assertSame(2, $list['total']);
        self::assertSame(2, $list['pages']);
        $missing = $this->call($this->server(), 'iot_gallery_latest', ['slug' => 'ffp3', 'filename' => '../../etc.jpg']);
        self::assertTrue($missing['isError']);
    }

    // ── Garde lecture seule ───────────────────────────────────────────

    public function testReadOnlyTransactionNeverPersistsWrites(): void
    {
        $guard = new ReadOnlyTransaction($this->pdo);
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM ffp3Data')->fetchColumn();
        $guard->run(function (): void {
            $this->pdo->exec("INSERT INTO ffp3Data (reading_time) VALUES ('2026-01-01 00:00:00')");
            $this->pdo->exec('DELETE FROM ffp3Outputs');
        });
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM ffp3Data')->fetchColumn());
        self::assertGreaterThan(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ffp3Outputs')->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReadToolsLeaveDatabaseUnchanged(): void
    {
        $snapshot = fn (): string => md5((string) json_encode([
            $this->pdo->query('SELECT * FROM ffp3Data ORDER BY id')->fetchAll(),
            $this->pdo->query('SELECT * FROM ffp3Outputs ORDER BY id')->fetchAll(),
            $this->pdo->query('SELECT * FROM mcp_control_plans')->fetchAll(),
        ]));
        $before = $snapshot();
        $server = $this->server();
        foreach (['iot_overview' => [], 'iot_latest' => ['family' => 'ffp3'], 'iot_outputs_state' => ['family' => 'ffp3'],
            'iot_series' => ['family' => 'ffp3', 'fields' => ['TempEau']], 'iot_stats' => ['family' => 'ffp3'], 'iot_alerts' => []] as $tool => $args) {
            self::assertFalse($this->call($server, $tool, $args)['isError'], $tool);
        }
        self::assertSame($before, $snapshot());
    }

    // ── Pilotage ──────────────────────────────────────────────────────

    private function controlServer(array $extra = []): McpServer
    {
        return $this->server(McpSqliteFixture::config(array_merge(['MCP_CONTROL_ENABLED' => 'true'], $extra)));
    }

    private static function op(): McpContext
    {
        return self::ctx('operator', ['iot.read', 'iot.control']);
    }

    public function testControlDisabledByDefault(): void
    {
        $result = $this->call($this->server(), 'iot_plan_output_change', ['family' => 'ffp3', 'gpio' => 2, 'value' => 1, 'env' => 'test'], self::op());
        self::assertTrue($result['isError']);
        self::assertSame('forbidden', self::data($result)['code']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mcp_control_plans')->fetchColumn());
    }

    public function testReaderCannotPilotEvenWhenEnabled(): void
    {
        $result = $this->call($this->controlServer(), 'iot_plan_output_change', ['family' => 'ffp3', 'gpio' => 2, 'value' => 1, 'env' => 'test'], self::ctx('reader', ['iot.read', 'iot.control']));
        self::assertSame('forbidden', self::data($result)['code']);
    }

    public function testPlanThenApplyOnTestEnvironment(): void
    {
        $server = $this->controlServer();
        $plan = self::data($this->call($server, 'iot_plan_output_change', ['family' => 'ffp3', 'gpio' => 2, 'value' => 1, 'env' => 'test'], self::op()));
        self::assertSame('0', $plan['before']);
        self::assertSame('1', $plan['after']);
        self::assertFalse($plan['is_production']);
        self::assertNotEmpty($plan['risks']);
        $state = fn (string $table): string => (string) $this->pdo->query("SELECT state FROM {$table} WHERE gpio = 2")->fetchColumn();
        self::assertSame('0', $state('ffp3Outputs2'), 'le plan n\'écrit rien');

        $applied = $this->call($server, 'iot_apply_plan', ['plan_id' => $plan['plan_id']], self::op());
        self::assertFalse($applied['isError'], $applied['content'][0]['text']);
        self::assertSame('1', $state('ffp3Outputs2'));
        self::assertSame('0', $state('ffp3Outputs'), 'la PRODUCTION n\'est pas touchée');
        self::assertSame('web', $this->pdo->query('SELECT lastModifiedBy FROM ffp3Outputs2 WHERE gpio = 2')->fetchColumn(), 'source serveur protégée du POST firmware');
        self::assertSame(TableConfig::getDefaultEnvironment(), TableConfig::getEnvironment());

        $again = $this->call($server, 'iot_apply_plan', ['plan_id' => $plan['plan_id']], self::op());
        self::assertSame('plan_already_applied', self::data($again)['code']);
    }

    public function testProductionNotPilotableByDefault(): void
    {
        $result = $this->call($this->controlServer(), 'iot_plan_output_change', ['family' => 'ffp3', 'gpio' => 2, 'value' => 1, 'env' => 'prod'], self::op());
        self::assertTrue($result['isError']);
        self::assertSame('invalid_arguments', self::data($result)['code']);
    }

    public function testPlanOwnedByAnotherUserIsRejected(): void
    {
        $server = $this->controlServer();
        $plan = self::data($this->call($server, 'iot_plan_output_change', ['family' => 'ffp3', 'gpio' => 15, 'value' => 1, 'env' => 'test'], self::op()));
        $result = $this->call($server, 'iot_apply_plan', ['plan_id' => $plan['plan_id']], self::ctx('admin', ['iot.read', 'iot.control']));
        self::assertSame('plan_not_found', self::data($result)['code']);
    }

    public function testApplyRefusesWhenStateChangedOrPlanExpired(): void
    {
        $server = $this->controlServer();
        $plan = self::data($this->call($server, 'iot_plan_output_change', ['family' => 'ffp3', 'gpio' => 2, 'value' => 1, 'env' => 'test'], self::op()));
        $this->pdo->exec("UPDATE ffp3Outputs2 SET state = '1' WHERE gpio = 2");
        self::assertSame('state_changed', self::data($this->call($server, 'iot_apply_plan', ['plan_id' => $plan['plan_id']], self::op()))['code']);

        $plan2 = self::data($this->call($server, 'iot_plan_output_change', ['family' => 'ffp3', 'gpio' => 15, 'value' => 1, 'env' => 'test'], self::op()));
        $this->pdo->exec("UPDATE mcp_control_plans SET expires_at = '2000-01-01 00:00:00' WHERE plan_id = '" . $plan2['plan_id'] . "'");
        self::assertSame('plan_expired', self::data($this->call($server, 'iot_apply_plan', ['plan_id' => $plan2['plan_id']], self::op()))['code']);
    }

    public function testSafeguards(): void
    {
        $server = $this->controlServer();
        $refuse = function (array $args) use ($server): string {
            $result = $this->call($server, 'iot_plan_output_change', $args + ['env' => 'test'], self::op());
            self::assertTrue($result['isError'], json_encode($args));

            return (string) self::data($result)['code'];
        };
        self::assertSame('refused_by_policy', $refuse(['family' => 'ffp3', 'gpio' => 16, 'value' => 0]), 'pas d\'arrêt de la pompe aquarium');
        self::assertSame('refused_by_policy', $refuse(['family' => 'ffp3', 'gpio' => 117, 'value' => 2]), 'pas de « forcer OFF »');
        self::assertSame('refused_by_policy', $refuse(['family' => 'ffp3', 'gpio' => 104, 'value' => 35]), 'chauffage borné');
        self::assertSame('invalid_arguments', $refuse(['family' => 'ffp3', 'gpio' => 110, 'value' => 1]), 'reset exclu');
        self::assertSame('invalid_arguments', $refuse(['family' => 'ffp3', 'gpio' => 100, 'value' => 1]), 'e-mail exclu');
        self::assertSame('refused_by_policy', $refuse(['family' => 'n3pp', 'gpio' => 105, 'value' => 60]), 'arrosage borné');
        self::assertNull(ControlPolicy::validate('ffp3', 16, 1), 'démarrage de la pompe autorisé');
    }

    public function testFeedingIsCappedPerDay(): void
    {
        $server = $this->controlServer(['MCP_FEED_DAILY_MAX' => '2']);
        for ($i = 0; $i < 2; $i++) {
            $plan = self::data($this->call($server, 'iot_plan_output_change', ['family' => 'ffp3', 'gpio' => 108, 'env' => 'test'], self::op()));
            $applied = $this->call($server, 'iot_apply_plan', ['plan_id' => $plan['plan_id']], self::op());
            self::assertFalse($applied['isError'], $applied['content'][0]['text']);
        }
        self::assertSame('5', (string) $this->pdo->query('SELECT state FROM ffp3Outputs2 WHERE gpio = 108')->fetchColumn(), 'compteur monotone 3 → 5');
        $third = $this->call($server, 'iot_plan_output_change', ['family' => 'ffp3', 'gpio' => 108, 'env' => 'test'], self::op());
        self::assertSame('feed_cap_reached', self::data($third)['code']);
    }

    public function testControlToolsAnnotations(): void
    {
        $config = McpSqliteFixture::config(['MCP_CONTROL_ENABLED' => 'true']);
        $apply = new ApplyPlanTool($config, new McpOAuthRepository($this->pdo), new McpReadRepository($this->pdo), $this->createMock(OutputWriter::class), $this->createMock(ControlAuditLogger::class));
        self::assertTrue($apply->annotations()['destructiveHint']);
        self::assertFalse($apply->annotations()['readOnlyHint']);
        self::assertFalse($apply->isReadOnly());
        self::assertSame(McpConfig::SCOPE_CONTROL, $apply->requiredScope());
    }

    public function testTimeWindowParsing(): void
    {
        $now = new \DateTimeImmutable('2026-11-02 12:00:00');
        $w = TimeWindow::parse('-12h', 'now', 86400, 180, $now);
        self::assertSame('2026-11-02 00:00:00', $w->fromSql());
        $this->expectException(ToolException::class);
        TimeWindow::parse('hier', null, 86400, 180, $now);
    }
}
