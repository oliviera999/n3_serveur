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
        $this->expectExceptionMessage("Environment must be 'prod', 'test', 'test3' or 's3', got: invalid");

        new EnvironmentMiddleware('invalid');
    }

    public function testProcessSetsEnvironment(): void
    {
        // Reset l'environnement à prod
        TableConfig::setEnvironment('prod');
        $this->assertEquals('prod', TableConfig::getEnvironment());

        // Créer le middleware pour TEST
        $middleware = new EnvironmentMiddleware('test');

        // Créer des mocks
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        // Exécuter le middleware
        $result = $middleware->process($request, $handler);

        // Vérifier que l'environnement a été changé
        $this->assertEquals('test', TableConfig::getEnvironment());
        $this->assertSame($response, $result);
    }
}

