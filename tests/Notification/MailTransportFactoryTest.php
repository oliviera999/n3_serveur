<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Notification\MailTransportFactory;
use App\Notification\NativeMailTransport;
use App\Notification\SymfonyMailTransport;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;

/**
 * Tests de MailTransportFactory : sélection du transport et reconstruction du DSN SMTP.
 */
final class MailTransportFactoryTest extends TestCase
{
    private LogService $logger;

    protected function setUp(): void
    {
        putenv('LOG_FILE_PATH=php://memory');
        $this->logger = $this->createMock(LogService::class);
    }

    public function testFallsBackToNativeWhenNoSmtpConfigured(): void
    {
        $transport = MailTransportFactory::fromEnv($this->logger, []);

        self::assertInstanceOf(NativeMailTransport::class, $transport);
    }

    public function testUsesSymfonyTransportWhenDsnProvided(): void
    {
        $transport = MailTransportFactory::fromEnv($this->logger, ['SMTP_DSN' => 'smtp://localhost:25']);

        self::assertInstanceOf(SymfonyMailTransport::class, $transport);
    }

    public function testUsesSymfonyTransportWhenHostProvided(): void
    {
        $transport = MailTransportFactory::fromEnv($this->logger, ['SMTP_HOST' => 'smtp.example.com']);

        self::assertInstanceOf(SymfonyMailTransport::class, $transport);
    }

    public function testExplicitDsnTakesPriority(): void
    {
        $dsn = MailTransportFactory::resolveDsn([
            'SMTP_DSN' => 'smtps://explicit@host:465',
            'SMTP_HOST' => 'ignored.example.com',
        ]);

        self::assertSame('smtps://explicit@host:465', $dsn);
    }

    public function testResolveDsnReturnsNullWhenEmpty(): void
    {
        self::assertNull(MailTransportFactory::resolveDsn([]));
        self::assertNull(MailTransportFactory::resolveDsn(['SMTP_DSN' => '   ']));
        self::assertNull(MailTransportFactory::resolveDsn(['SMTP_HOST' => '']));
    }

    public function testResolveDsnBuildsFromHostPortUserPass(): void
    {
        $dsn = MailTransportFactory::resolveDsn([
            'SMTP_HOST' => 'smtp.example.com',
            'SMTP_PORT' => '587',
            'SMTP_USER' => 'bob@example.com',
            'SMTP_PASS' => 'secret',
        ]);

        self::assertSame('smtp://bob%40example.com:secret@smtp.example.com:587', $dsn);
    }

    public function testResolveDsnUsesSmtpsSchemeForTls(): void
    {
        $dsn = MailTransportFactory::resolveDsn([
            'SMTP_HOST' => 'smtp.example.com',
            'SMTP_PORT' => '465',
            'SMTP_ENCRYPTION' => 'tls',
        ]);

        self::assertSame('smtps://smtp.example.com:465', $dsn);
    }

    public function testResolveDsnEncodesCredentialsWithSpecialChars(): void
    {
        $dsn = MailTransportFactory::resolveDsn([
            'SMTP_HOST' => 'smtp.example.com',
            'SMTP_USER' => 'a/b',
            'SMTP_PASS' => 'p@ss:word',
        ]);

        // Les caractères réservés sont percent-encodés pour rester un DSN valide.
        self::assertStringContainsString('a%2Fb', $dsn);
        self::assertStringContainsString('p%40ss%3Aword', $dsn);
    }
}
