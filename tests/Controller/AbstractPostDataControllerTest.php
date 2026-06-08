<?php

namespace Tests\Controller;

use App\Controller\AbstractPostDataController;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Tests de non-regression pour la validation API_KEY dans AbstractPostDataController.
 */
class AbstractPostDataControllerTest extends TestCase
{
    private function createController(): AbstractPostDataController
    {
        $logger = $this->createMock(LogService::class);
        return new class ($logger) extends AbstractPostDataController {
            protected function componentName(): string
            {
                return 'TestModule';
            }
            protected function buildSensorData(array $params, \Closure $sanitize, \Closure $toFloat, \Closure $toInt): object
            {
                return new \stdClass();
            }
            protected function insertData(object $data): void
            {
            }
        };
    }

    private function makePostRequest(array $body): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', '/test-post-data');
        return $request->withParsedBody($body);
    }

    public function testRejectsWhenApiKeyNotConfigured(): void
    {
        $oldApiKey = $_ENV['API_KEY'] ?? null;
        $_ENV['API_KEY'] = '';

        try {
            $controller = $this->createController();
            $response = (new ResponseFactory())->createResponse();
            $request = $this->makePostRequest([
                'api_key' => 'some-key',
                'sensor' => 'test-sensor',
                'version' => '1.0',
            ]);

            $result = $controller->handle($request, $response);
            $this->assertSame(500, $result->getStatusCode());
            $body = (string) $result->getBody();
            $this->assertStringContainsString('Configuration serveur manquante', $body);
        } finally {
            if ($oldApiKey !== null) {
                $_ENV['API_KEY'] = $oldApiKey;
            } else {
                unset($_ENV['API_KEY']);
            }
        }
    }

    public function testRejectsInvalidApiKey(): void
    {
        $oldApiKey = $_ENV['API_KEY'] ?? null;
        $_ENV['API_KEY'] = 'correct-secret-key';

        try {
            $controller = $this->createController();
            $response = (new ResponseFactory())->createResponse();
            $request = $this->makePostRequest([
                'api_key' => 'wrong-key',
                'sensor' => 'test-sensor',
                'version' => '1.0',
            ]);

            $result = $controller->handle($request, $response);
            $this->assertSame(401, $result->getStatusCode());
            $body = (string) $result->getBody();
            $this->assertStringContainsString('Cle API invalide', $body);
        } finally {
            if ($oldApiKey !== null) {
                $_ENV['API_KEY'] = $oldApiKey;
            } else {
                unset($_ENV['API_KEY']);
            }
        }
    }

    public function testAcceptsValidApiKey(): void
    {
        $oldApiKey = $_ENV['API_KEY'] ?? null;
        $_ENV['API_KEY'] = 'correct-secret-key';

        try {
            $controller = $this->createController();
            $response = (new ResponseFactory())->createResponse();
            $request = $this->makePostRequest([
                'api_key' => 'correct-secret-key',
                'sensor' => 'test-sensor',
                'version' => '1.0',
            ]);

            $result = $controller->handle($request, $response);
            $this->assertSame(200, $result->getStatusCode());
        } finally {
            if ($oldApiKey !== null) {
                $_ENV['API_KEY'] = $oldApiKey;
            } else {
                unset($_ENV['API_KEY']);
            }
        }
    }
}
