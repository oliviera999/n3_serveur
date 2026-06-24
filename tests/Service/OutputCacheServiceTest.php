<?php

namespace Tests\Service;

use App\Service\OutputCacheService;
use PDO;
use PHPUnit\Framework\TestCase;

class OutputCacheServiceTest extends TestCase
{
    private PDO $pdo;
    private OutputCacheService $service;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        // Configure environment
        putenv('ENV=prod');
        $_ENV['ENV'] = 'prod';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ffp3Outputs (
            gpio INTEGER PRIMARY KEY,
            state INTEGER
        )');

        // Insert test data
        $this->pdo->exec('INSERT INTO ffp3Outputs (gpio, state) VALUES (16, 1)');
        $this->pdo->exec('INSERT INTO ffp3Outputs (gpio, state) VALUES (18, 0)');
        $this->pdo->exec('INSERT INTO ffp3Outputs (gpio, state) VALUES (110, 1)');

        $this->pdo->exec('CREATE TABLE ffp3OtaTrigger (
            env TEXT PRIMARY KEY,
            pending INTEGER NOT NULL DEFAULT 0
        )');

        $this->service = new OutputCacheService($this->pdo);
    }

    public function testGetOutputsStateReturnsEmptyArrayForEmptyList(): void
    {
        $result = $this->service->getOutputsState($this->pdo, []);

        $this->assertSame([], $result);
    }

    public function testGetOutputsStateReturnsCorrectStates(): void
    {
        $result = $this->service->getOutputsState($this->pdo, [16, 18]);

        $this->assertArrayHasKey('16', $result);
        $this->assertArrayHasKey('18', $result);
    }

    public function testGetOutputsStateAlwaysReadsFromBdd(): void
    {
        // Cache supprimé (v5.x) : chaque appel lit la BDD directement.
        $result = $this->service->getOutputsState($this->pdo, [16, 18]);
        $this->assertArrayHasKey('16', $result);
        $this->assertArrayHasKey('18', $result);
    }

    public function testTriggerOtaCheckPersistedThenConsumedOnce(): void
    {
        $this->service->setTriggerOtaCheckRequested();
        $first = $this->service->getOutputsState($this->pdo, [16]);
        $this->assertTrue($first['triggerOtaCheck'] ?? false);

        $second = $this->service->getOutputsState($this->pdo, [16]);
        $this->assertArrayNotHasKey('triggerOtaCheck', $second);
    }

    public function testTriggerOtaCheckCreatesMissingTableBeforePersisting(): void
    {
        $this->pdo->exec('DROP TABLE ffp3OtaTrigger');
        $this->service->setTriggerOtaCheckRequested();

        $first = $this->service->getOutputsState($this->pdo, [16]);
        $this->assertTrue($first['triggerOtaCheck'] ?? false);
    }

    public function testReadOnlyStateDoesNotConsumeOtaTrigger(): void
    {
        $this->service->setTriggerOtaCheckRequested();

        $readOnly = $this->service->getOutputsState($this->pdo, [16], true, false);
        $this->assertArrayNotHasKey('triggerOtaCheck', $readOnly);

        $firmwarePoll = $this->service->getOutputsState($this->pdo, [16]);
        $this->assertTrue($firmwarePoll['triggerOtaCheck'] ?? false);

        $afterConsume = $this->service->getOutputsState($this->pdo, [16]);
        $this->assertArrayNotHasKey('triggerOtaCheck', $afterConsume);
    }

    /**
     * Contrat dbvars (firmware) : tous les GPIO de config 100-116 doivent être présents
     * dans la réponse, même absents en BDD (valeur par défaut), sinon le firmware reçoit un
     * champ manquant. Vérifie aussi la présence des alias symboliques (double format).
     */
    public function testGetOutputsStateReturnsAllConfigGpiosEvenWhenAbsentFromDb(): void
    {
        $configGpios = range(100, 116);
        $result = $this->service->getOutputsState($this->pdo, $configGpios, true, false);

        foreach ($configGpios as $gpio) {
            $this->assertArrayHasKey((string) $gpio, $result, "GPIO {$gpio} manquant dans dbvars");
        }
        // Alias symboliques attendus par le firmware (web_server.cpp::fillDbVarsJson).
        $this->assertArrayHasKey('bouffeMatin', $result);
        $this->assertArrayHasKey('chauffageThreshold', $result);
        $this->assertArrayHasKey('FreqWakeUp', $result);
    }

    /**
     * La réponse JSON dbvars doit rester sous la limite dédiée du firmware ESP32-WROOM
     * (outputs/state = 2048 o depuis GPIO 118-123, compact, sans PRETTY_PRINT).
     * Valeurs réalistes (email long).
     * Garde-fou contre un ajout de champ qui ferait silencieusement tronquer côté firmware.
     */
    public function testGetOutputsStateJsonStaysUnderFirmwareBuffer(): void
    {
        // Email réaliste relativement long sur GPIO 100.
        $this->pdo->exec("INSERT INTO ffp3Outputs (gpio, state) VALUES (100, 'aquaponie.ferme.exploitation@example.com')");
        $firmwareGpios = [
            2, 15, 16, 18,
            100, 101, 102, 103, 104, 105, 106, 107,
            108, 109, 110,
            111, 112, 113, 114, 115, 116,
            118, 119, 120, 121, 122, 123,
            117,
        ];
        $result = $this->service->getOutputsState($this->pdo, $firmwareGpios, true, false);

        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($json);
        $this->assertArrayHasKey('118', $result);
        $this->assertArrayHasKey('angleReposGros', $result);
        $this->assertLessThan(
            2048,
            strlen($json),
            'Réponse dbvars >= 2048 o : risque de troncature sur buffer ESP32-WROOM (' . strlen($json) . ' o)'
        );
    }
}
