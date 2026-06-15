<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Security\AuthService;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Couvre le formatage des entrées d'audit, la résolution de l'identité (session/token/anonyme)
 * et l'extraction de l'IP cliente depuis les server params PSR-7.
 */
class ControlAuditLoggerTest extends TestCase
{
    /** @var array<int, array{level:string, message:string, context:array<string,mixed>}> */
    private array $captured = [];

    /** @var LogService&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->captured = [];

        $this->logger = $this->createMock(LogService::class);
        $capture = function (string $level): callable {
            return function (string $message, array $context = []) use ($level): void {
                $this->captured[] = ['level' => $level, 'message' => $message, 'context' => $context];
            };
        };
        $this->logger->method('info')->willReturnCallback($capture('info'));
        $this->logger->method('warning')->willReturnCallback($capture('warning'));
    }

    private function makeRequest(string $remoteAddr = '203.0.113.7'): \Slim\Psr7\Request
    {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                'http://localhost/api/outputs/toggle',
                ['REMOTE_ADDR' => $remoteAddr]
            );
    }

    public function testSuccessfulActionLoggedAsInfoWithAuditPrefix(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn('admin');

        $audit = new ControlAuditLogger($this->logger, $auth);
        $audit->logAction($this->makeRequest(), 'ffp3', 'toggle', ['gpio' => 16, 'state' => 1], true);

        $this->assertCount(1, $this->captured);
        $entry = $this->captured[0];
        $this->assertSame('info', $entry['level']);
        $this->assertStringContainsString('[control-audit]', $entry['message']);
        $this->assertSame('admin', $entry['context']['who']);
        $this->assertSame('ffp3', $entry['context']['module']);
        $this->assertSame('toggle', $entry['context']['action']);
        $this->assertSame('success', $entry['context']['result']);
        $this->assertSame(16, $entry['context']['gpio']);
        $this->assertSame(1, $entry['context']['state']);
        $this->assertSame('203.0.113.7', $entry['context']['ip']);
    }

    public function testFailedActionLoggedAsWarningWithReason(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn('admin');

        $audit = new ControlAuditLogger($this->logger, $auth);
        $audit->logAction($this->makeRequest(), 'msp', 'set', ['gpio' => 9999], false, 'GPIO non autorisé');

        $this->assertCount(1, $this->captured);
        $entry = $this->captured[0];
        $this->assertSame('warning', $entry['level']);
        $this->assertSame('failure', $entry['context']['result']);
        $this->assertSame('GPIO non autorisé', $entry['context']['reason']);
    }

    public function testIdentityFallsBackToTokenWhenNoSessionUser(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(null);
        $auth->method('isAuthenticatedByToken')->willReturn(true);

        $audit = new ControlAuditLogger($this->logger, $auth);
        $audit->logAction($this->makeRequest(), 'n3pp', 'toggle', ['gpio' => 16], true);

        $this->assertSame('token', $this->captured[0]['context']['who']);
    }

    public function testIdentityFallsBackToAnonymous(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(null);
        $auth->method('isAuthenticatedByToken')->willReturn(false);

        $audit = new ControlAuditLogger($this->logger, $auth);
        $audit->logAction($this->makeRequest(), 'ffp3', 'trigger_ota_check', [], true);

        $this->assertSame('anonymous', $this->captured[0]['context']['who']);
    }

    public function testClientIpPrefersXForwardedForHeader(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn('admin');

        $request = $this->makeRequest('10.0.0.1')
            ->withHeader('X-Forwarded-For', '198.51.100.42, 10.0.0.1');

        $audit = new ControlAuditLogger($this->logger, $auth);
        $audit->logAction($request, 'ffp3', 'toggle', ['gpio' => 16], true);

        $this->assertSame('198.51.100.42', $this->captured[0]['context']['ip']);
    }

    public function testClientIpFallsBackToNaWhenAbsent(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn('admin');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs/toggle', []);

        $audit = new ControlAuditLogger($this->logger, $auth);
        $audit->logAction($request, 'ffp3', 'toggle', ['gpio' => 16], true);

        $this->assertSame('n/a', $this->captured[0]['context']['ip']);
    }
}
