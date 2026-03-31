<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\TableConfig;
use PDO;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;

/**
 * Base pour les tests d'integration MySQL (Docker local / snapshot).
 * backupGlobals desactive : sinon PHPUnit peut restaurer $_ENV entre hooks et casser TableConfig / Env.
 */
#[BackupGlobals(false)]
abstract class IntegrationDbTestCase extends TestCase
{
    protected const MIN_ROWS_FFP3_SNAPSHOT = 5_000;

    private bool $envKeyWasPresent = false;

    private string $savedEnvValue = 'prod';

    protected function setUp(): void
    {
        parent::setUp();
        $this->envKeyWasPresent = array_key_exists('ENV', $_ENV);
        if ($this->envKeyWasPresent) {
            $this->savedEnvValue = (string) $_ENV['ENV'];
        }
        TableConfig::setEnvironment('prod');
    }

    protected function tearDown(): void
    {
        if ($this->envKeyWasPresent) {
            $_ENV['ENV'] = $this->savedEnvValue;
        } else {
            unset($_ENV['ENV']);
        }
        parent::tearDown();
    }

    protected function createPdoOrSkip(): PDO
    {
        $host = getenv('DB_HOST') ?: '';
        $db = getenv('DB_NAME') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS');
        if ($host === '' || $db === '') {
            self::markTestSkipped('Variables DB_HOST / DB_NAME absentes (hors stack Docker ?).');
        }
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $db);
        try {
            return new PDO($dsn, $user, $pass === false ? '' : $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            self::markTestSkipped('BDD non joignable : ' . $e->getMessage());
        }
    }

    protected function requireProductionSnapshotOrSkip(PDO $pdo): void
    {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM ffp3Data')->fetchColumn();
        if ($n < self::MIN_ROWS_FFP3_SNAPSHOT) {
            self::markTestSkipped(sprintf(
                'ffp3Data contient %d lignes (seuil snapshot %d). Importer un dump avec tools/import-mysql-dump-to-local-docker.ps1.',
                $n,
                self::MIN_ROWS_FFP3_SNAPSHOT
            ));
        }
    }
}
