<?php

namespace Tests\Controller;

use App\Controller\AuthController;
use PHPUnit\Framework\TestCase;

/**
 * Tests de non-regression pour la validation des redirections login (open redirect).
 */
class AuthControllerRedirectTest extends TestCase
{
    private \ReflectionMethod $sanitizeRedirect;

    protected function setUp(): void
    {
        $this->sanitizeRedirect = new \ReflectionMethod(AuthController::class, 'sanitizeRedirect');
        $this->sanitizeRedirect->setAccessible(true);
    }

    private function callSanitize(string $redirect, string $basePath = ''): string
    {
        $authService = $this->createMock(\App\Security\AuthService::class);
        $renderer = $this->createMock(\App\Service\TemplateRenderer::class);
        $csrfService = $this->createMock(\App\Security\CsrfService::class);

        $controller = new AuthController($authService, $renderer, $csrfService);
        return $this->sanitizeRedirect->invoke($controller, $redirect, $basePath);
    }

    public function testAcceptsInternalPath(): void
    {
        $this->assertSame('/dashboard', $this->callSanitize('/dashboard'));
    }

    public function testAcceptsInternalPathWithQuery(): void
    {
        $this->assertSame('/aquaponie?env=test', $this->callSanitize('/aquaponie?env=test'));
    }

    public function testRejectsAbsoluteHttpUrl(): void
    {
        $result = $this->callSanitize('http://evil.com/steal');
        $this->assertSame('/', $result);
    }

    public function testRejectsAbsoluteHttpsUrl(): void
    {
        $result = $this->callSanitize('https://evil.com/steal');
        $this->assertSame('/', $result);
    }

    public function testRejectsProtocolRelativeUrl(): void
    {
        $result = $this->callSanitize('//evil.com/steal');
        $this->assertSame('/', $result);
    }

    public function testRejectsJavascriptScheme(): void
    {
        $result = $this->callSanitize('javascript:alert(1)');
        $this->assertSame('/', $result);
    }

    public function testRejectsDataScheme(): void
    {
        $result = $this->callSanitize('data:text/html,<h1>XSS</h1>');
        $this->assertSame('/', $result);
    }

    public function testRejectsRelativePathWithoutSlash(): void
    {
        $result = $this->callSanitize('evil.com');
        $this->assertSame('/', $result);
    }

    public function testRejectsNullBytes(): void
    {
        $result = $this->callSanitize("/dashboard\0evil");
        $this->assertSame('/', $result);
    }

    public function testEmptyStringReturnsDefault(): void
    {
        $this->assertSame('/', $this->callSanitize(''));
    }

    public function testEmptyStringWithBasePathReturnsDefault(): void
    {
        $this->assertSame('/ffp3/', $this->callSanitize('', '/ffp3'));
    }

    public function testAcceptsRootPath(): void
    {
        $this->assertSame('/', $this->callSanitize('/'));
    }
}
