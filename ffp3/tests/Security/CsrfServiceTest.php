<?php

namespace Tests\Security;

use App\Security\CsrfService;
use PHPUnit\Framework\TestCase;

class CsrfServiceTest extends TestCase
{
    private CsrfService $csrfService;

    protected function setUp(): void
    {
        // Démarrer une session pour les tests
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = []; // Reset session
        
        $this->csrfService = new CsrfService();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testGenerateTokenReturnsNonEmptyString(): void
    {
        $token = $this->csrfService->generateToken();
        
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testGetTokenReturnsSameTokenOnMultipleCalls(): void
    {
        $token1 = $this->csrfService->getToken();
        $token2 = $this->csrfService->getToken();
        
        $this->assertSame($token1, $token2);
    }

    public function testValidateTokenReturnsTrueForValidToken(): void
    {
        $token = $this->csrfService->getToken();
        
        $this->assertTrue($this->csrfService->validateToken($token));
    }

    public function testValidateTokenReturnsFalseForInvalidToken(): void
    {
        $this->csrfService->getToken();
        
        $this->assertFalse($this->csrfService->validateToken('invalid_token'));
    }

    public function testValidateTokenReturnsFalseForNullToken(): void
    {
        $this->csrfService->getToken();
        
        $this->assertFalse($this->csrfService->validateToken(null));
    }

    public function testValidateAndRegenerateRegeneratesToken(): void
    {
        $originalToken = $this->csrfService->getToken();
        
        $isValid = $this->csrfService->validateAndRegenerate($originalToken);
        $newToken = $this->csrfService->getToken();
        
        $this->assertTrue($isValid);
        $this->assertNotSame($originalToken, $newToken);
    }

    public function testGetHiddenFieldReturnsValidHtml(): void
    {
        $hiddenField = $this->csrfService->getHiddenField();
        
        $this->assertStringContainsString('<input type="hidden"', $hiddenField);
        $this->assertStringContainsString('name="_csrf_token"', $hiddenField);
        $this->assertStringContainsString('value="', $hiddenField);
    }

    public function testGetFieldNameReturnsCorrectName(): void
    {
        $this->assertSame('_csrf_token', CsrfService::getFieldName());
    }
}
