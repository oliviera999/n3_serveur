<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Config\Database;
use App\Notification\AlertThrottler;
use App\Notification\NotificationCategory;
use App\Notification\Severity;
use App\Service\LogService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests de AlertThrottler.
 *
 * La persistance repose sur Database::getConnection() (singleton MySQL) et sur du SQL
 * spécifique MySQL (SHOW TABLES, NOW(), DATE_SUB), NON exécutable sous SQLite. Sont donc
 * vérifiés ici les éléments traitables sans MySQL réel :
 *  - le court-circuit cooldown <= 0 ;
 *  - le CONTRAT DE ROBUSTESSE « fail-open » : si la base est indisponible ou incompatible,
 *    allow() autorise l'envoi (on préfère un doublon à une alerte perdue) et journalise,
 *    et record() reste silencieux (jamais d'exception propagée).
 */
final class AlertThrottlerTest extends TestCase
{
    /** @var LogService&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;
    private AlertThrottler $throttler;

    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');
        $this->logger = $this->createMock(LogService::class);
        $this->throttler = new AlertThrottler($this->logger);
        $this->resetDatabaseSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetDatabaseSingleton();
    }

    private function resetDatabaseSingleton(): void
    {
        $prop = new ReflectionProperty(Database::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function injectSqliteConnection(): void
    {
        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO sqlite driver non disponible');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $prop = new ReflectionProperty(Database::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $pdo);
    }

    public function testZeroOrNegativeCooldownAlwaysAllows(): void
    {
        self::assertTrue($this->throttler->allow('clé', 0));
        self::assertTrue($this->throttler->allow('clé', -10));
    }

    public function testAllowFailsOpenWhenDatabaseUnavailable(): void
    {
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $var) {
            unset($_ENV[$var]);
            putenv($var);
        }

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('AlertThrottler indisponible'));

        self::assertTrue($this->throttler->allow('ffp3:offline', 900));
    }

    public function testAllowFailsOpenOnIncompatibleBackend(): void
    {
        // SQLite ne supporte pas SHOW TABLES : ensureTableExists échoue -> fail-open.
        $this->injectSqliteConnection();

        self::assertTrue($this->throttler->allow('ffp3:offline', 900));
    }

    public function testRecordNeverThrowsOnPersistenceFailure(): void
    {
        $this->injectSqliteConnection();

        $this->throttler->record(
            'ffp3:offline',
            Severity::Critical,
            NotificationCategory::Availability,
            'destinataire@test.local',
            'sujet'
        );

        // Pas d'exception => le test atteint cette assertion.
        $this->addToAssertionCount(1);
    }
}
