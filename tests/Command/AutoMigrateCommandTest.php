<?php

declare(strict_types=1);

namespace Tests\Command;

use App\Command\AutoMigrateCommand;
use App\Mcp\Sql\MigrationCatalog;
use App\Mcp\Sql\MigrationRunner;
use App\Mcp\Sql\SchemaReader;
use App\Service\LogService;
use App\Service\NotificationService;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\McpSqliteFixture;

/**
 * Migrations automatiques (6.61.0) : le CRON applique seul les migrations additives du dépôt.
 */
final class AutoMigrateCommandTest extends TestCase
{
    private PDO $pdo;
    private string $dir;
    private string $state;
    /** @var list<string> */
    private array $reports = [];

    protected function setUp(): void
    {
        $this->pdo = McpSqliteFixture::pdo();
        McpSqliteFixture::seedFfp3($this->pdo);
        $this->dir = sys_get_temp_dir() . '/auto-migr-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/m', 0777, true);
        $this->state = $this->dir . '/state.json';
        file_put_contents($this->dir . '/m/2026_11_new_col.sql', "ALTER TABLE ffp3Data ADD COLUMN wlAquaStatus TEXT;\nALTER TABLE ffp3Data2 ADD COLUMN wlAquaStatus TEXT;\n");
        file_put_contents($this->dir . '/m/2026_07_drop.sql', "DROP TABLE ffp3Data2;\n");
        unset($_ENV['AUTO_MIGRATE_ENABLED'], $_ENV['AUTO_MIGRATE_EXCLUDE']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        unset($_ENV['AUTO_MIGRATE_ENABLED'], $_ENV['AUTO_MIGRATE_EXCLUDE']);
    }

    private function command(): AutoMigrateCommand
    {
        $schema = new SchemaReader($this->pdo);
        $runner = new MigrationRunner($this->pdo, $schema, new MigrationCatalog($this->dir . '/m', $schema));
        $notifier = $this->createMock(NotificationService::class);
        $notifier->method('sendScheduledReport')->willReturnCallback(function ($severity, string $subject, string $message): bool {
            $this->reports[] = $subject . "\n" . $message;

            return true;
        });

        return new AutoMigrateCommand($runner, $this->createMock(LogService::class), $this->state, null, null, $notifier);
    }

    public function testAppliesPendingAdditiveMigrationsAndIgnoresNonAdditive(): void
    {
        $result = $this->command()->execute(now: 1000);
        self::assertTrue($result['checked']);
        self::assertSame(['2026_11_new_col.sql'], array_column($result['applied'], 'file'));
        $schema = new SchemaReader($this->pdo);
        self::assertTrue($schema->columnExists('ffp3Data', 'wlAquaStatus'));
        self::assertTrue($schema->columnExists('ffp3Data2', 'wlAquaStatus'));
        self::assertTrue($schema->tableExists('ffp3Data2'), 'DROP jamais exécuté');
        self::assertCount(1, $this->reports);
        self::assertStringContainsString('appliquée', $this->reports[0]);
    }

    public function testNotDueUntilIntervalOrFolderChange(): void
    {
        $cmd = $this->command();
        $cmd->execute(now: 1000);
        self::assertFalse($cmd->execute(now: 1060)['checked'], 'ni échéance ni changement');

        file_put_contents($this->dir . '/m/2026_12_other.sql', "ALTER TABLE ffp3Data ADD COLUMN extraCol TEXT;\n");
        $again = $cmd->execute(now: 1120);
        self::assertTrue($again['checked'], 'nouveau fichier déployé → examen immédiat');
        self::assertSame(['2026_12_other.sql'], array_column($again['applied'], 'file'));
        self::assertTrue($cmd->execute(now: 1120 + 900)['checked'], 'échéance atteinte');
    }

    public function testFailedFileRetriedAfter24hOrWhenContentChanges(): void
    {
        file_put_contents($this->dir . '/m/2026_11_bad.sql', "ALTER TABLE ffp3Data ADD COLUMN bad NOT_A_TYPE NOT NULL;\n");
        $cmd = $this->command();
        $first = $cmd->execute(now: 1000);
        self::assertSame(['2026_11_bad.sql'], array_column($first['failed'], 'file'));
        self::assertStringContainsString('échec', end($this->reports));

        $second = $cmd->execute(force: false, now: 1000 + 900);
        self::assertSame([], $second['failed'], 'pas de DDL rejoué chaque passage');
        self::assertContains('2026_11_bad.sql', array_column($second['skipped'], 'file'));

        file_put_contents($this->dir . '/m/2026_11_bad.sql', "ALTER TABLE ffp3Data ADD COLUMN bad TEXT;\n");
        $fixed = $cmd->execute(now: 1000 + 1800);
        self::assertSame(['2026_11_bad.sql'], array_column($fixed['applied'], 'file'), 'fichier corrigé → retenté');
    }

    public function testDisabledAndExcluded(): void
    {
        $_ENV['AUTO_MIGRATE_ENABLED'] = 'false';
        self::assertFalse($this->command()->execute(now: 1000)['checked']);

        $_ENV['AUTO_MIGRATE_ENABLED'] = 'true';
        $_ENV['AUTO_MIGRATE_EXCLUDE'] = '2026_11_new_col.sql';
        $r = $this->command()->execute(now: 1000);
        self::assertSame([], $r['applied']);
        self::assertSame('2026_11_new_col.sql', $r['skipped'][0]['file']);
        self::assertFalse((new SchemaReader($this->pdo))->columnExists('ffp3Data', 'wlAquaStatus'));
    }
}
