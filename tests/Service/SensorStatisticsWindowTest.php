<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\SensorStatisticsService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

/**
 * `lastReadingsWindow` : la fenêtre des N dernières lignes distingue marée vivante, capteur figé
 * et capteur muet. SQLite reçoit un agrégat STDDEV (population, NULL ignorés) équivalent à MySQL.
 */
final class SensorStatisticsWindowTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = McpSqliteFixture::pdo();
        $this->pdo->sqliteCreateAggregate(
            'STDDEV',
            static function (?array $ctx, int $row, mixed $value): array {
                $ctx ??= [];
                if ($value !== null) {
                    $ctx[] = (float) $value;
                }

                return $ctx;
            },
            static function (?array $ctx): ?float {
                if ($ctx === null || $ctx === []) {
                    return null;
                }
                $mean = array_sum($ctx) / count($ctx);

                return sqrt(array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $ctx)) / count($ctx));
            },
            1
        );
    }

    /**
     * @param list<float|null> $values plus ancienne d'abord
     */
    private function seed(array $values): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO ffp3Data (sensor, version, EauAquarium, reading_time) VALUES ('ffp3', '15.31', ?, ?)");
        $n = count($values);
        foreach ($values as $i => $v) {
            $stmt->execute([$v, date('Y-m-d H:i:s', time() - ($n - $i) * 60)]);
        }
    }

    public function testFrozenValueHasOneDistinctAndZeroStddev(): void
    {
        $this->seed(array_merge([95.0, 101.0, 108.0], array_fill(0, 60, 81.0)));
        $w = (new SensorStatisticsService($this->pdo))->lastReadingsWindow('EauAquarium', 60);
        self::assertSame(['rows' => 60, 'non_null' => 60, 'distinct' => 1, 'stddev' => 0.0], $w);
    }

    public function testMutedWindowHasNoValueAndNullStddev(): void
    {
        $this->seed(array_merge(array_fill(0, 10, 81.0), array_fill(0, 60, null)));
        $w = (new SensorStatisticsService($this->pdo))->lastReadingsWindow('EauAquarium', 60);
        self::assertSame(60, $w['rows']);
        self::assertSame(0, $w['non_null']);
        self::assertNull($w['stddev']);
    }

    public function testLiveTideWindow(): void
    {
        $values = [];
        for ($i = 0; $i < 60; $i++) {
            $values[] = 90.0 + ($i % 6) * 4;
        }
        $this->seed($values);
        $w = (new SensorStatisticsService($this->pdo))->lastReadingsWindow('EauAquarium', 60);
        self::assertSame(6, $w['distinct']);
        self::assertGreaterThan(1.0, $w['stddev']);
    }
}
