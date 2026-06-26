<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Config\TableConfig;
use App\Repository\OutputRepository;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Cohérence mapping paramètres page contrôle ↔ GPIO (contrat firmware GET).
 */
class OutputRepositoryTest extends TestCase
{
    /** Clés utilisées dans control.twig (data-parameter / params.*) */
    private const CONTROL_TWIG_PARAMETERS = [
        'mail',
        'aqThr',
        'taThr',
        'chauff',
        'bouffeMatin',
        'bouffeMidi',
        'bouffeSoir',
        'tempsGros',
        'tempsPetits',
        'tempsRemplissageSec',
        'limFlood',
        'FreqWakeUp',
        'angleReposGros',
        'angleDistribGros',
        'angleInterGros',
        'angleReposPetits',
        'angleDistribPetits',
        'angleInterPetits',
    ];

    /** GPIO actionneurs affichés dans control.twig */
    private const CONTROL_TWIG_ACTUATOR_GPIOS = [2, 15, 16, 18, 101, 108, 109, 110, 115, 117];

    /** GPIO listés dans OutputController::getOutputsState (firmware poll) */
    private const FIRMWARE_STATE_GPIOS = [
        2, 15, 16, 18,
        100, 101, 102, 103, 104, 105, 106, 107,
        108, 109, 110,
        111, 112, 113, 114, 115, 116,
        118, 119, 120, 121, 122, 123,
        117,
    ];

    public function testParameterGpioMapContainsAllControlTwigKeys(): void
    {
        $map = OutputRepository::getParameterGpioMap();

        foreach (self::CONTROL_TWIG_PARAMETERS as $key) {
            $this->assertArrayHasKey(
                $key,
                $map,
                "Clé paramètre manquante dans PARAMETER_GPIO_MAP: {$key}"
            );
            $this->assertGreaterThan(0, $map[$key]);
        }
    }

    /** GPIO server-only (politique notifications) exclus du poll firmware */
    private const FIRMWARE_EXCLUDED_SERVER_ONLY = [124, 125];

    public function testFirmwareStateGpioListIncludesControlActuatorsAndParameters(): void
    {
        $map = OutputRepository::getParameterGpioMap();

        foreach (self::CONTROL_TWIG_ACTUATOR_GPIOS as $gpio) {
            $this->assertContains(
                $gpio,
                self::FIRMWARE_STATE_GPIOS,
                "GPIO actionneur {$gpio} absent de la liste getOutputsState"
            );
        }

        foreach ($map as $gpio) {
            if (in_array($gpio, self::FIRMWARE_EXCLUDED_SERVER_ONLY, true)) {
                continue;
            }
            $this->assertContains(
                $gpio,
                self::FIRMWARE_STATE_GPIOS,
                "GPIO paramètre {$gpio} absent de la liste getOutputsState"
            );
        }
    }

    public function testServerOnlyNotificationGpiosAreExcludedFromFirmwarePoll(): void
    {
        $gpioValues = array_values(OutputRepository::getParameterGpioMap());
        foreach (self::FIRMWARE_EXCLUDED_SERVER_ONLY as $gpio) {
            $this->assertContains($gpio, $gpioValues);
            $this->assertContains($gpio, \App\Config\Ffp3GpioMap::serverOnlyGpios());
        }
    }

    public function testGpio117IsServerOnlyExtension(): void
    {
        $map = OutputRepository::getParameterGpioMap();
        $this->assertArrayNotHasKey('aquariumPumpForce', $map);
        $this->assertContains(117, self::FIRMWARE_STATE_GPIOS);
        $this->assertContains(117, self::CONTROL_TWIG_ACTUATOR_GPIOS);
    }

    public function testEnsureServoAngleRowsIgnoresConcurrentDuplicateInsert(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $duplicate = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $duplicate->errorInfo = ['23000', 1062, 'Duplicate entry'];

        $pdo->method('prepare')->willReturn($stmt);
        $stmt->method('execute')->willThrowException($duplicate);

        $repository = new class ($pdo) extends OutputRepository {
            protected function fetchOne(string $sql, array $params = []): ?array
            {
                if (($params[':gpio'] ?? null) === 16) {
                    return ['board' => '1'];
                }

                return null;
            }
        };

        $repository->ensureServoAngleRowsExist();
        $this->addToAssertionCount(1);
    }

    public function testEnsureServoAngleRowsRethrowsNonDuplicateInsertError(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);
        $exception = new PDOException('SQLSTATE[42S22]: Column not found: 1054 Unknown column');
        $exception->errorInfo = ['42S22', 1054, 'Unknown column'];

        $pdo->method('prepare')->willReturn($stmt);
        $stmt->method('execute')->willThrowException($exception);

        $repository = new class ($pdo) extends OutputRepository {
            protected function fetchOne(string $sql, array $params = []): ?array
            {
                if (($params[':gpio'] ?? null) === 16) {
                    return ['board' => '1'];
                }

                return null;
            }
        };

        $this->expectException(PDOException::class);
        $repository->ensureServoAngleRowsExist();
    }

    public function testUpdateStateByIdDoesNotOverwriteManualFeedCounter(): void
    {
        $pdo = $this->makeSqliteOutputsPdo();
        $repo = new OutputRepository($pdo);

        $updated = $repo->updateStateById(1, 0, 'web');

        $this->assertFalse($updated);
        $this->assertSame('12', (string) $pdo->query('SELECT state FROM ffp3Outputs WHERE id = 1')->fetchColumn());
    }

    public function testIncrementFeedCounterRequiresMatchingGpio(): void
    {
        $pdo = $this->makeSqliteOutputsPdo();
        $repo = new OutputRepository($pdo);

        $counter = $repo->incrementFeedCounter(2, 108);

        $this->assertNull($counter);
        $this->assertSame('1', (string) $pdo->query('SELECT state FROM ffp3Outputs WHERE id = 2')->fetchColumn());
    }

    public function testIncrementFeedCounterUpdatesMatchingManualFeedGpioOnly(): void
    {
        $pdo = $this->makeSqliteOutputsPdo();
        $repo = new OutputRepository($pdo);

        $counter = $repo->incrementFeedCounter(1, 108);

        $this->assertSame(13, $counter);
        $this->assertSame('13', (string) $pdo->query('SELECT state FROM ffp3Outputs WHERE id = 1')->fetchColumn());
    }

    private function makeSqliteOutputsPdo(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        TableConfig::setEnvironment('prod');
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => '2026-06-26 11:00:00');
        $pdo->exec('CREATE TABLE ffp3Outputs (
            id INTEGER PRIMARY KEY,
            board TEXT,
            gpio INTEGER,
            name TEXT,
            state TEXT,
            requestTime TEXT,
            lastModifiedBy TEXT
        )');
        $pdo->exec("INSERT INTO ffp3Outputs (id, board, gpio, name, state, lastModifiedBy) VALUES
            (1, '1', 108, 'Nourrir petits poissons', '12', 'web'),
            (2, '1', 16, 'Pompe aquarium', '1', 'web')");

        return $pdo;
    }
}
