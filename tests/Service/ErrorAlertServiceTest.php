<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Config\Database;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use App\Service\NotificationService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Tests unitaires de ErrorAlertService.
 *
 * Le service détecte les erreurs répétées et déclenche des alertes au-delà d'un seuil dans
 * une fenêtre de temps, avec cooldown anti-spam. Sa persistance repose sur Database::
 * getConnection() (singleton statique MySQL) et sur du SQL spécifique MySQL
 * (NOW(), DATE_SUB(... INTERVAL ... SECOND), SHOW TABLES LIKE), NON exécutable sous SQLite.
 *
 * Sont donc testés ici les éléments traitables sans MySQL réel :
 *  - la NORMALISATION des messages (regroupement des erreurs similaires), cœur de la clé
 *    d'agrégation utilisée pour le comptage et le seuil d'alerte, via réflexion sur les
 *    méthodes privées normalizeErrorMessage()/extractErrorType() (déterministes, sans I/O) ;
 *  - le CONTRAT DE ROBUSTESSE de recordError() : en cas d'indisponibilité de la base
 *    (aucune connexion configurée, ou SQL incompatible), la méthode ne doit JAMAIS lever
 *    d'exception et doit journaliser l'erreur via LogService, sans déclencher d'alerte.
 *
 * NON couvert (nécessite un MySQL réel) — signalé : l'enregistrement effectif des erreurs,
 * le franchissement du seuil ERROR_THRESHOLD dans TIME_WINDOW_SECONDS, l'envoi d'alerte via
 * NotificationService et la fenêtre de cooldown ALERT_COOLDOWN_SECONDS. Ces branches
 * s'appuient sur du SQL MySQL (NOW()/DATE_SUB/SHOW TABLES) que SQLite::memory ne supporte
 * pas et que ces tests ne doivent pas modifier côté production.
 */
final class ErrorAlertServiceTest extends TestCase
{
    /** @var LogService&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;
    /** @var NotificationService&\PHPUnit\Framework\MockObject\MockObject */
    private $notifier;
    private ErrorAlertService $service;

    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');

        $this->logger = $this->createMock(LogService::class);
        $this->notifier = $this->createMock(NotificationService::class);
        $this->service = new ErrorAlertService($this->logger, $this->notifier);
    }

    protected function tearDown(): void
    {
        // Restaure le singleton Database à null pour ne pas polluer les autres tests.
        $prop = new ReflectionProperty(Database::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * Invoque une méthode privée de ErrorAlertService via réflexion.
     */
    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionClass(ErrorAlertService::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($this->service, ...$args);
    }

    /**
     * Force une connexion SQLite en mémoire dans le singleton Database afin que
     * recordError() s'exécute sans tenter de joindre un MySQL réel. Le SQL MySQL du
     * service échouera alors sous SQLite : c'est précisément la branche de robustesse
     * (try/catch -> log) que l'on veut vérifier.
     */
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

    // ------------------------------------------------------------------
    // Normalisation des messages (clé d'agrégation pour le seuil)
    // ------------------------------------------------------------------

    public function testNormalizeUsesBaseBeforeColonWithWildcard(): void
    {
        $result = $this->invokePrivate('normalizeErrorMessage', 'Erreur insertion données: boom détaillé', []);

        self::assertSame('Erreur insertion données: *', $result);
    }

    public function testNormalizeWithoutColonKeepsMessageIntact(): void
    {
        $result = $this->invokePrivate('normalizeErrorMessage', 'Message sans deux-points', []);

        self::assertSame('Message sans deux-points', $result);
    }

    public function testNormalizeUsesErrorTypeFromContextWhenPresent(): void
    {
        $result = $this->invokePrivate(
            'normalizeErrorMessage',
            'Erreur insertion: échec',
            ['error' => 'SQLSTATE[HY000] Connection refused']
        );

        // Le type d'erreur extrait du contexte remplace le wildcard générique.
        self::assertSame('Erreur insertion: Connection refused', $result);
    }

    public function testNormalizeGroupsSimilarMessagesUnderSameKey(): void
    {
        // Deux messages au détail différent mais de même préfixe doivent partager la clé,
        // condition pour que leur comptage cumulé atteigne le seuil d'alerte.
        $a = $this->invokePrivate('normalizeErrorMessage', 'Timeout capteur: lecture 1 échouée', []);
        $b = $this->invokePrivate('normalizeErrorMessage', 'Timeout capteur: lecture 2 échouée', []);

        self::assertSame($a, $b);
    }

    /**
     * @dataProvider errorTypeProvider
     */
    public function testExtractErrorTypeRecognizesCommonPatterns(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->invokePrivate('extractErrorType', $raw));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function errorTypeProvider(): array
    {
        return [
            'connection refused' => ['Connection refused by peer', 'Connection refused'],
            'connection timeout' => ['Connection timed out after 5s', 'Connection timeout'],
            // Le motif SQLSTATE n'accepte que des codes numériques (\d+) ; un code
            // alphanumérique comme 42S02 retombe sur le wildcard (cf. cas dédié ci-dessous).
            'sql error numeric' => ['SQLSTATE[1146] base error', 'SQL Error'],
            'sql error alphanumeric falls back' => ['SQLSTATE[42S02] base error', '*'],
            'unknown column' => ['Unknown column foo in field list', 'Unknown column'],
            'duplicate entry' => ["Duplicate entry '1' for key", 'Duplicate entry'],
            'access denied' => ['Access denied for user root', 'Access denied'],
            'unmatched -> wildcard' => ['quelque chose de totalement inconnu', '*'],
        ];
    }

    // ------------------------------------------------------------------
    // Contrat de robustesse de recordError()
    // ------------------------------------------------------------------

    public function testRecordErrorDoesNotThrowWhenDatabaseUnavailable(): void
    {
        // Aucune connexion configurée : Database::getConnection() lèvera une exception
        // (variables DB manquantes ou connexion impossible). recordError() doit l'absorber.
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $var) {
            unset($_ENV[$var]);
            putenv($var);
        }

        // Le service doit journaliser l'indisponibilité, jamais propager.
        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('ErrorAlertService indisponible'));

        // Aucune alerte ne doit partir si la persistance échoue.
        $this->notifier->expects($this->never())->method('sendCustomAlert');

        $this->service->recordError('Erreur test: indisponibilité base');

        // Pas d'exception => le test atteint cette assertion.
        $this->addToAssertionCount(1);
    }

    public function testRecordErrorIsResilientToIncompatibleSqlBackend(): void
    {
        // Connexion SQLite injectée : le SQL MySQL du service (SHOW TABLES / NOW() / DATE_SUB)
        // échoue, mais recordError() doit rester silencieux côté appelant et journaliser.
        $this->injectSqliteConnection();

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('ErrorAlertService indisponible'));
        $this->notifier->expects($this->never())->method('sendCustomAlert');

        $this->service->recordError('Erreur test: backend incompatible', ['error' => 'boom']);

        $this->addToAssertionCount(1);
    }
}
