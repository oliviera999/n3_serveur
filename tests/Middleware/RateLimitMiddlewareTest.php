<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\RateLimitMiddleware;
use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Couvre le middleware de limitation de débit par IP (anti-brute-force login).
 */
final class RateLimitMiddlewareTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n3_rlmw_test_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    private function makeRequest(string $remoteAddr, string $forwardedFor = ''): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $remoteAddr]);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => strtolower($name) === 'x-forwarded-for' ? $forwardedFor : ''
        );

        return $request;
    }

    private function passHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    public function testAllowsRequestsUnderLimit(): void
    {
        $middleware = new RateLimitMiddleware(new RateLimiter($this->dir), 'login', 2, 60);
        $passed = $this->createMock(ResponseInterface::class);

        $r1 = $middleware->process($this->makeRequest('1.2.3.4'), $this->passHandler($passed));
        $r2 = $middleware->process($this->makeRequest('1.2.3.4'), $this->passHandler($passed));

        $this->assertSame($passed, $r1);
        $this->assertSame($passed, $r2);
    }

    public function testBlocksRequestsOverLimitWith429(): void
    {
        $middleware = new RateLimitMiddleware(new RateLimiter($this->dir), 'login', 2, 60);
        $passed = $this->createMock(ResponseInterface::class);

        $middleware->process($this->makeRequest('1.2.3.4'), $this->passHandler($passed));
        $middleware->process($this->makeRequest('1.2.3.4'), $this->passHandler($passed));

        // 3e requête : au-delà du seuil
        $blockedHandler = $this->createMock(RequestHandlerInterface::class);
        $blockedHandler->expects($this->never())->method('handle');

        $result = $middleware->process($this->makeRequest('1.2.3.4'), $blockedHandler);

        $this->assertSame(429, $result->getStatusCode());
        $this->assertNotSame('', $result->getHeaderLine('Retry-After'));
    }

    public function testDifferentIpsHaveSeparateBuckets(): void
    {
        $middleware = new RateLimitMiddleware(new RateLimiter($this->dir), 'login', 2, 60);
        $passed = $this->createMock(ResponseInterface::class);

        $middleware->process($this->makeRequest('1.2.3.4'), $this->passHandler($passed));
        $middleware->process($this->makeRequest('1.2.3.4'), $this->passHandler($passed));

        // Une autre IP n'est pas affectée par le quota de la première
        $result = $middleware->process($this->makeRequest('5.6.7.8'), $this->passHandler($passed));
        $this->assertSame($passed, $result);
    }

    public function testForwardedForTakesPrecedenceForKeying(): void
    {
        $middleware = new RateLimitMiddleware(new RateLimiter($this->dir), 'login', 1, 60);
        $passed = $this->createMock(ResponseInterface::class);

        // Même REMOTE_ADDR (proxy) mais clients distincts via XFF → buckets séparés
        $r1 = $middleware->process($this->makeRequest('10.0.0.1', '203.0.113.1'), $this->passHandler($passed));
        $r2 = $middleware->process($this->makeRequest('10.0.0.1', '203.0.113.2'), $this->passHandler($passed));

        $this->assertSame($passed, $r1);
        $this->assertSame($passed, $r2);

        // Re-frapper le premier client XFF dépasse son quota de 1
        $blockedHandler = $this->createMock(RequestHandlerInterface::class);
        $blockedHandler->expects($this->never())->method('handle');
        $result = $middleware->process($this->makeRequest('10.0.0.1', '203.0.113.1'), $blockedHandler);
        $this->assertSame(429, $result->getStatusCode());
    }
}
