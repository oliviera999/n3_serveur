<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Config\TableConfig;
use App\Middleware\EnvironmentMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class EnvironmentMiddlewareTest extends TestCase
{
    public function testConstructorWithValidEnvironment(): void
    {
        $middleware = new EnvironmentMiddleware('prod');
        $this->assertInstanceOf(EnvironmentMiddleware::class, $middleware);

        $middleware = new EnvironmentMiddleware('test');
        $this->assertInstanceOf(EnvironmentMiddleware::class, $middleware);

        $middleware = new EnvironmentMiddleware('test3');
        $this->assertInstanceOf(EnvironmentMiddleware::class, $middleware);

        $middleware = new EnvironmentMiddleware('s3');
        $this->assertInstanceOf(EnvironmentMiddleware::class, $middleware);
    }

    public function testConstructorWithInvalidEnvironment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Environment must be one of: prod, test, test3, s3, s3test, n3pp_test, msp_test, got: invalid");

        new EnvironmentMiddleware('invalid');
    }

    protected function tearDown(): void
    {
        TableConfig::resetRequestEnvironment();
        parent::tearDown();
    }

    public function testProcessSetsEnvironment(): void
    {
        TableConfig::resetRequestEnvironment();
        $default = TableConfig::getDefaultEnvironment();

        $middleware = new EnvironmentMiddleware('test');

        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);

        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        $result = $middleware->process($request, $handler);

        $this->assertEquals('test', TableConfig::getEnvironment());
        $this->assertSame($response, $result);

        TableConfig::resetRequestEnvironment();
        $this->assertEquals($default, TableConfig::getEnvironment());
    }
}

