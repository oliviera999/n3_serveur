<?php

declare(strict_types=1);

namespace Tests\Mcp;

use App\Config\TableConfig;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\Support\ReadOnlyTransaction;
use App\Mcp\ToolRegistry;
use App\Mcp\Tools\LatestTool;
use App\Mcp\Tools\SensorHealthTool;
use App\Repository\McpReadRepository;
use App\Service\LogService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

/**
 * Capteur muet / figé : `iot_sensor_health` et le bloc `missing_fields` de `iot_latest`.
 *
 * Scénario rejoué : l'incident FFP3 du 30/10/2026 (docs/DIAGNOSTIC_FFP3_NIVEAU_AQUARIUM_2026-11.md) —
 * EauAquarium varie, se fige à 81 mm, puis devient NULL (champ omis du POST) ; les autres capteurs
 * restent normaux.
 */
final class SensorHealthToolTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = McpSqliteFixture::pdo();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ffp3Data (sensor, version, TempAir, Humidite, TempEau, EauAquarium, EauReserve, EauPotager, diffMaree, etatPompeAqua, mail, reading_time)'
            . " VALUES ('ffp3', '15.31', ?, ?, ?, ?, ?, ?, 0, 1, 'secret@example.org', ?)"
        );
        $now = time();
        // 40 mesures toutes les 10 min (la plus ancienne il y a 400 min) :
        //   10 variées → 24 figées à 81 mm (≈ 4 h) → 6 vides (champ absent).
        for ($k = 39; $k >= 0; $k--) {
            $age = $k * 600 + 60;
            if ($k >= 30) {
                $aqua = 90 + ($k % 4) * 6;       // marées visibles
            } elseif ($k >= 6) {
                $aqua = 81;                       // figé
            } else {
                $aqua = null;                     // muet
            }
            $stmt->execute([
                20 + ($k % 2) * 0.5, 60 + ($k % 3), 24 + ($k % 3) * 0.2, $aqua, 400 + ($k % 2) * 3, 300 + ($k % 5) * 4,
                date('Y-m-d H:i:s', $now - $age),
            ]);
        }
    }

    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
    }

    private function server(): McpServer
    {
        $config = McpSqliteFixture::config();
        $read = new McpReadRepository($this->pdo);
        $tools = [new LatestTool($config, $read), new SensorHealthTool($config, $read)];

        return new McpServer(new ToolRegistry($tools, $config), new ReadOnlyTransaction($this->pdo), $this->createMock(LogService::class), $config);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments, string $role = 'reader'): array
    {
        $r = $this->server()->handle([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ], new McpContext(1, $role, $role, ['iot.read'], 'client-test'));

        return $r['result'];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function data(array $result): array
    {
        self::assertFalse($result['isError'], (string) ($result['content'][0]['text'] ?? ''));

        return json_decode($result['content'][0]['text'], true);
    }

    public function testMutedAfterFrozenSensorIsReportedAbsentWithItsFrozenHistory(): void
    {
        $data = self::data($this->call('iot_sensor_health', ['family' => 'ffp3', 'from' => '-12h']));
        self::assertTrue($data['is_production']);
        self::assertSame(40, $data['readings']);
        self::assertSame(['EauAquarium'], $data['suspect_fields']);

        $aqua = $data['fields']['EauAquarium'];
        self::assertSame('absent', $aqua['status']);
        self::assertSame(6, $aqua['null_readings']);
        self::assertSame(8.1, $aqua['last_value'], '81 mm → 8,1 cm');
        self::assertSame('cm', $aqua['unit']);
        self::assertSame(24, $aqua['frozen_readings']);
        self::assertSame(23 * 600, $aqua['frozen_for_seconds']);
        self::assertSame(5 * 600, $aqua['null_for_seconds']);
        self::assertFalse($aqua['since_is_lower_bound'], 'le figement commence après une valeur différente');
        self::assertSame(0.85, $aqua['coverage']);

        self::assertSame('ok', $data['fields']['TempEau']['status']);
        self::assertSame('ok', $data['fields']['EauPotager']['status']);
        self::assertStringNotContainsString('secret@example.org', (string) json_encode($data));
    }

    public function testFrozenSensorIsDetectedBeforeItGoesMute(): void
    {
        // Fenêtre arrêtée avant les NULL : le capteur est « seulement » figé (≈ 4 h ≥ 120 min).
        $to = date('Y-m-d\TH:i', time() - 6 * 600 + 30);
        $data = self::data($this->call('iot_sensor_health', ['family' => 'ffp3', 'fields' => ['EauAquarium'], 'from' => '-12h', 'to' => $to]));
        self::assertSame('frozen', $data['fields']['EauAquarium']['status']);
        self::assertSame(0, $data['fields']['EauAquarium']['null_readings']);

        // Seuil plus long que le figement observé : plus de statut « frozen ».
        $data = self::data($this->call('iot_sensor_health', ['family' => 'ffp3', 'fields' => ['EauAquarium'], 'from' => '-12h', 'to' => $to, 'frozen_minutes' => 600]));
        self::assertSame('ok', $data['fields']['EauAquarium']['status']);
    }

    public function testAnalyseClassifiesIntermittentNoDataAndAllNull(): void
    {
        $rows = static fn (array $values): array => array_map(
            static fn (int $i, mixed $v): array => ['EauAquarium' => $v, 'reading_time' => date('Y-m-d H:i:s', 1_800_000_000 - $i * 600)],
            array_keys($values),
            $values
        );
        self::assertSame('intermittent', SensorHealthTool::analyse('ffp3', 'EauAquarium', $rows([null, 120, 110, 100]), 7200)['status']);
        self::assertSame('ok', SensorHealthTool::analyse('ffp3', 'EauAquarium', $rows([120, 110, 100]), 7200)['status']);
        self::assertSame('no_data', SensorHealthTool::analyse('ffp3', 'EauAquarium', [], 7200)['status']);
        $allNull = SensorHealthTool::analyse('ffp3', 'EauAquarium', $rows([null, null]), 7200);
        self::assertSame('absent', $allNull['status']);
        self::assertTrue($allNull['since_is_lower_bound']);
        self::assertNull($allNull['last_value']);
    }

    public function testWindowIsCappedAndArgumentsStrict(): void
    {
        $tooWide = $this->call('iot_sensor_health', ['family' => 'ffp3', 'from' => '-40d']);
        self::assertTrue($tooWide['isError']);
        self::assertSame('window_too_large', json_decode($tooWide['content'][0]['text'], true)['code']);

        $unknown = $this->call('iot_sensor_health', ['family' => 'ffp3', 'sql' => 'DELETE FROM ffp3Data']);
        self::assertTrue($unknown['isError']);

        $tool = new SensorHealthTool(McpSqliteFixture::config(), new McpReadRepository($this->pdo));
        self::assertTrue($tool->isReadOnly());
        self::assertFalse($tool->inputSchema()['additionalProperties']);
        self::assertSame(40, (int) $this->pdo->query('SELECT COUNT(*) FROM ffp3Data')->fetchColumn(), 'aucune écriture');
    }

    public function testLatestListsMutedPhysicalSensors(): void
    {
        $data = self::data($this->call('iot_latest', ['family' => 'ffp3']));
        self::assertArrayNotHasKey('EauAquarium', $data['values']);
        self::assertCount(1, $data['missing_fields'], 'seul le capteur physique muet est signalé');
        $missing = $data['missing_fields'][0];
        self::assertSame('EauAquarium', $missing['field']);
        self::assertSame(8.1, $missing['last_value']);
        self::assertSame(6, $missing['null_readings']);
        self::assertSame(5 * 600, $missing['null_for_seconds']);
        self::assertFalse($missing['lookback_exceeded']);
        self::assertStringContainsString('iot_sensor_health', $data['note']);
    }

    public function testLatestWithoutMutedSensorHasNoMissingBlock(): void
    {
        $this->pdo->exec('UPDATE ffp3Data SET EauAquarium = 95 WHERE EauAquarium IS NULL');
        $data = self::data($this->call('iot_latest', ['family' => 'ffp3']));
        self::assertArrayNotHasKey('missing_fields', $data);
        self::assertSame(9.5, $data['values']['EauAquarium']['value']);
    }
}
