<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Util\CliGuard;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou structurel : tout script de maintenance (`tools/`, `bin/`,
 * `run-cron.php`) doit refuser une execution web.
 *
 * Motif (audit 2026-07, S11) : quand le DocumentRoot pointe sur la racine du
 * depot — la configuration prevue par le `.htaccess` racine — le routeur Slim
 * n'est atteint que pour les URL sans fichier correspondant
 * (`RewriteCond %{REQUEST_FILENAME} !-f`). Un `GET /tools/xxx.php` etait donc
 * execute par Apache sans authentification. Plusieurs de ces scripts ont des
 * effets de bord reels : `apply-recent-migrations.php` (`$pdo->exec()` sans
 * `--dry-run`), `fix_test_environment.php` / `check_tables_server.php`
 * (INSERT/DELETE), `cleanup_whitespace.php` (`file_put_contents`),
 * `bootstrap-admin-user.php` (creation d'un compte admin).
 *
 * Ce test echoue des qu'un nouveau script apparait sans garde : la liste est
 * decouverte sur le disque, pas figee.
 */
final class CliOnlyScriptsTest extends TestCase
{
    /**
     * Seule exception : ce fichier est un endpoint de mesure de latence, prevu
     * pour etre COPIE a la racine web d'un autre domaine (cf. son en-tete).
     * Il n'ouvre ni BDD ni fichier et n'expose aucun secret.
     */
    private const WEB_ALLOWED = ['tools/ping_standalone.php'];

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function maintenanceScriptProvider(): array
    {
        $root = self::projectRoot();
        $files = ['run-cron.php'];

        foreach (['tools', 'bin'] as $dir) {
            $absolute = $root . '/' . $dir;
            if (!is_dir($absolute)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $files[] = $dir . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($absolute) + 1));
            }
        }

        sort($files);

        $cases = [];
        foreach ($files as $relative) {
            if (in_array($relative, self::WEB_ALLOWED, true)) {
                continue;
            }
            $cases[$relative] = [$relative];
        }

        return $cases;
    }

    /**
     * @dataProvider maintenanceScriptProvider
     */
    public function testMaintenanceScriptRefusesWebExecution(string $relative): void
    {
        $path = self::projectRoot() . '/' . $relative;
        $source = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'CliGuard::assertCli(',
            $source,
            "{$relative} doit appeler \\App\\Util\\CliGuard::assertCli() (script hors-web)."
        );
    }

    /**
     * La garde doit precede tout effet de bord : autoload, connexion, ecriture.
     *
     * @dataProvider maintenanceScriptProvider
     */
    public function testGuardComesBeforeAnySideEffect(string $relative): void
    {
        $path = self::projectRoot() . '/' . $relative;
        $source = (string) file_get_contents($path);

        $guardPos = strpos($source, 'CliGuard::assertCli(');
        $this->assertIsInt($guardPos, "{$relative} : garde absente.");

        foreach (['vendor/autoload.php', 'Database::getConnection', 'file_put_contents', 'new PDO'] as $sideEffect) {
            $pos = strpos($source, $sideEffect);
            if ($pos === false) {
                continue;
            }
            $this->assertLessThan(
                $pos,
                $guardPos,
                "{$relative} : « {$sideEffect} » apparait avant CliGuard::assertCli()."
            );
        }
    }

    /**
     * Le `require_once` de la garde doit pointer sur un chemin qui existe :
     * il est ecrit en dur (la garde s'execute AVANT l'autoloader Composer),
     * donc une profondeur de repertoire erronee ne serait vue qu'a l'execution.
     *
     * @dataProvider maintenanceScriptProvider
     */
    public function testGuardRequirePathResolves(string $relative): void
    {
        $path = self::projectRoot() . '/' . $relative;
        $source = (string) file_get_contents($path);

        $this->assertSame(
            1,
            preg_match("#require_once __DIR__ \. '([^']+CliGuard\.php)';#", $source, $m),
            "{$relative} : require_once de CliGuard.php introuvable ou reecrit."
        );

        $resolved = realpath(dirname($path) . $m[1]);
        $this->assertNotFalse($resolved, "{$relative} : chemin {$m[1]} non resolvable.");
        $this->assertSame(realpath(self::projectRoot() . '/src/Util/CliGuard.php'), $resolved);
    }

    /**
     * Le point d'entree web, lui, ne doit surtout PAS porter la garde.
     */
    public function testWebEntryPointsAreNotGuarded(): void
    {
        foreach (['public/index.php', 'index.php'] as $relative) {
            $path = self::projectRoot() . '/' . $relative;
            if (!is_file($path)) {
                continue;
            }
            $this->assertStringNotContainsString(
                'CliGuard::assertCli(',
                (string) file_get_contents($path),
                "{$relative} est un point d'entree web : il ne doit pas etre garde."
            );
        }
    }

    public function testProviderActuallyFindsScripts(): void
    {
        $cases = self::maintenanceScriptProvider();
        $this->assertGreaterThan(10, count($cases), 'Le provider ne decouvre plus les scripts de maintenance.');
        $this->assertArrayHasKey('run-cron.php', $cases);
        $this->assertArrayHasKey('tools/apply-recent-migrations.php', $cases);
        $this->assertArrayNotHasKey('tools/ping_standalone.php', $cases);
    }

    /**
     * Les repertoires hors-web portent chacun un `.htaccess` de refus, filet
     * independant du `.htaccess` racine (remplace a chaque deploiement).
     */
    public function testSensitiveDirectoriesShipTheirOwnHtaccess(): void
    {
        foreach (['tools', 'bin', 'migrations'] as $dir) {
            $path = self::projectRoot() . '/' . $dir . '/.htaccess';
            $this->assertFileExists($path, "{$dir}/.htaccess manquant.");
            $this->assertStringContainsString('Require all denied', (string) file_get_contents($path));
        }
    }

    /**
     * Le `.htaccess` racine doit refuser les repertoires hors-web : le routeur
     * Slim ne les couvre pas (RewriteCond !-f).
     */
    public function testRootHtaccessDeniesNonWebDirectories(): void
    {
        $htaccess = (string) file_get_contents(self::projectRoot() . '/.htaccess');

        foreach (['tools', 'bin', 'tests', 'migrations', 'docker', 'docs'] as $dir) {
            $this->assertMatchesRegularExpression(
                '#^RewriteRule \^' . preg_quote($dir, '#') . '/ - \[F,L\]$#m',
                $htaccess,
                "Le .htaccess racine ne bloque pas {$dir}/."
            );
        }

        $this->assertMatchesRegularExpression(
            '#^RewriteRule \^run-cron\\\\\.php\$ - \[F,L\]$#m',
            $htaccess,
            'Le .htaccess racine ne bloque pas run-cron.php.'
        );
    }

    public function testGuardIsWiredOnRunCron(): void
    {
        $source = (string) file_get_contents(self::projectRoot() . '/run-cron.php');

        $this->assertStringNotContainsString(
            "strpos(php_sapi_name(), 'cgi') === false",
            $source,
            'run-cron.php ne doit plus se fier a une simple correspondance « cgi » '
            . '(cgi-fcgi est le SAPI de PHP-FPM en contexte web).'
        );
        $this->assertTrue(CliGuard::isCli('cli'));
    }
}
