<?php

declare(strict_types=1);

namespace Tests\Command;

use App\Command\LogRotationCommand;
use App\Mcp\Health\LogLocator;
use PHPUnit\Framework\TestCase;

/**
 * Rotation quotidienne des journaux non gérés par Monolog (6.63.0) : cronlog.txt nu (sortie de la
 * crontab, 118 Mo le 18/11/2026) et error_log vidés, copie datée compressée, rétention appliquée,
 * rotations Monolog (cronlog-AAAA-MM-JJ.txt) jamais touchées.
 */
final class LogRotationCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/log-rotation-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/logs', 0777, true);
        mkdir($this->tmp . '/var/cache', 0777, true);
        $_ENV['LOG_FILE_PATH'] = 'logs/cronlog.txt';
        unset($_ENV['LOG_RETENTION_DAYS']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
        unset($_ENV['LOG_FILE_PATH'], $_ENV['LOG_RETENTION_DAYS']);
    }

    private function command(): LogRotationCommand
    {
        $locator = new LogLocator($this->tmp, ['LOG_FILE_PATH' => 'logs/cronlog.txt'], $this->tmp . '/logs/error_log');

        return new LogRotationCommand($locator, null, null, $this->tmp . '/var/cache');
    }

    private function read(string $archive): string
    {
        return str_ends_with($archive, '.gz') ? (string) gzdecode((string) file_get_contents($archive)) : (string) file_get_contents($archive);
    }

    public function testCurrentFileIsArchivedThenEmptiedAndMonologCopiesAreLeftAlone(): void
    {
        $logs = $this->tmp . '/logs';
        file_put_contents($logs . '/cronlog.txt', str_repeat("Déjà à jour.\n", 1000));
        file_put_contents($logs . '/cronlog-2026-11-17.txt', "[2026-11-17 23:55:02] [INFO] --- Fin orchestrateur CRON ---\n");
        file_put_contents($logs . '/error_log', "[18-Nov-2026 11:55:02] PHP Warning: x\n");
        $now = (int) strtotime('2026-11-18 00:01:00');

        $result = $this->command()->execute($now);

        self::assertFalse($result['skipped']);
        self::assertCount(2, $result['rotated']);
        clearstatcache();
        self::assertSame(0, filesize($logs . '/cronlog.txt'), 'fichier courant vidé (même fichier)');
        self::assertSame(0, filesize($logs . '/error_log'));
        $archive = $logs . '/cronlog.txt.2026-11-17' . (function_exists('gzopen') ? '.gz' : '');
        self::assertFileExists($archive);
        self::assertSame(str_repeat("Déjà à jour.\n", 1000), $this->read($archive));
        self::assertStringContainsString('PHP Warning', $this->read($logs . '/error_log.2026-11-17' . (function_exists('gzopen') ? '.gz' : '')));
        self::assertStringContainsString('Fin orchestrateur', (string) file_get_contents($logs . '/cronlog-2026-11-17.txt'), 'rotation Monolog intacte');

        // Une seule rotation par jour : un passage suivant ne touche à rien.
        file_put_contents($logs . '/cronlog.txt', "ligne du jour\n");
        self::assertTrue($this->command()->execute($now + 60)['skipped']);
        clearstatcache();
        self::assertSame(strlen("ligne du jour\n"), filesize($logs . '/cronlog.txt'));
    }

    public function testRetentionDeletesOnlyArchivesOlderThanTheLimit(): void
    {
        $logs = $this->tmp . '/logs';
        file_put_contents($logs . '/cronlog.txt', "x\n");
        foreach (['2026-10-01', '2026-10-18', '2026-10-19', '2026-11-10'] as $day) {
            file_put_contents("{$logs}/cronlog.txt.{$day}.gz", 'old');
        }
        file_put_contents($logs . '/cronlog-2026-10-01.txt', 'monolog');
        $now = (int) strtotime('2026-11-18 00:01:00');

        $result = $this->command()->execute($now);

        self::assertSame(30, $this->command()->retentionDays(), 'défaut 30 j');
        self::assertFileDoesNotExist($logs . '/cronlog.txt.2026-10-01.gz');
        self::assertFileDoesNotExist($logs . '/cronlog.txt.2026-10-18.gz');
        self::assertFileExists($logs . '/cronlog.txt.2026-10-19.gz', 'limite = aujourd\'hui − 30 j, gardée');
        self::assertFileExists($logs . '/cronlog.txt.2026-11-10.gz');
        self::assertFileExists($logs . '/cronlog-2026-10-01.txt', 'rétention Monolog : LOG_ROTATE_DAYS, pas ici');
        self::assertCount(2, $result['deleted']);

        // Paramétrable : 7 j.
        $_ENV['LOG_RETENTION_DAYS'] = '7';
        @unlink($this->tmp . '/var/cache/' . LogRotationCommand::STATE_FILENAME);
        $this->command()->execute($now);
        self::assertFileDoesNotExist($logs . '/cronlog.txt.2026-10-19.gz');
        self::assertFileDoesNotExist($logs . '/cronlog.txt.2026-11-10.gz', 'limite = 11/11 avec 7 j');
        self::assertNotSame([], glob($logs . '/cronlog.txt.2026-11-17*') ?: [], 'archive de la veille gardée');
    }

    public function testEmptyFileIsNotArchived(): void
    {
        touch($this->tmp . '/logs/cronlog.txt');
        $result = $this->command()->execute((int) strtotime('2026-11-18 00:01:00'));

        self::assertSame([], $result['rotated']);
        self::assertSame([], glob($this->tmp . '/logs/cronlog.txt.*') ?: []);
    }
}
