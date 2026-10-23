<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\DisplayPresetController;
use App\Display\LayoutResolver;
use App\Display\PageCatalogRegistry;
use App\Repository\DisplayPresetRepository;
use App\Security\AuthService;
use App\Service\DisplayPresetService;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

/**
 * API presets d'affichage : auth opérateur, validation et cycle save/delete.
 */
final class DisplayPresetControllerTest extends TestCase
{
    private DisplayPresetController $controller;
    private AuthService $auth;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $service = new DisplayPresetService(
            new DisplayPresetRepository($pdo),
            new PageCatalogRegistry(),
            new LayoutResolver(),
            $this->createMock(LogService::class)
        );
        $this->auth = $this->createMock(AuthService::class);
        $this->controller = new DisplayPresetController(
            $service,
            $this->auth,
            $this->createMock(TemplateRenderer::class),
            $this->createMock(LogService::class)
        );
    }

    public function testListRequiresOperator(): void
    {
        $this->auth->method('canAccessControl')->willReturn(false);
        $response = $this->controller->list($this->request('GET'), new Response(), ['page' => 'msp1']);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testListUnknownPage(): void
    {
        $this->auth->method('canAccessControl')->willReturn(true);
        $response = $this->controller->list($this->request('GET'), new Response(), ['page' => 'inconnu']);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSaveAndDeleteCycle(): void
    {
        $this->auth->method('canAccessControl')->willReturn(true);
        $this->auth->method('getCurrentUser')->willReturn('operateur');

        $body = json_encode([
            'slug' => 'eleves',
            'label' => 'Élèves',
            'description' => 'Vue pédagogique',
            'is_default' => true,
            'show_on_page' => true,
            'sort_order' => 5,
            'layout' => ['blocks' => [['id' => 'luminosite', 'visible' => true]]],
        ], JSON_THROW_ON_ERROR);

        $save = $this->controller->save(
            $this->request('POST', $body, 'application/json'),
            new Response(),
            ['page' => 'msp1']
        );
        $this->assertSame(200, $save->getStatusCode());
        $saveJson = json_decode((string) $save->getBody(), true);
        $this->assertTrue($saveJson['success']);
        $this->assertSame('eleves', $saveJson['data']['presets'][0]['slug']);

        $delete = $this->controller->delete(
            $this->request('DELETE'),
            new Response(),
            ['page' => 'msp1', 'slug' => 'eleves']
        );
        $this->assertSame(200, $delete->getStatusCode());
        $deleteJson = json_decode((string) $delete->getBody(), true);
        $this->assertTrue($deleteJson['success']);
        $this->assertSame(['simple', 'etendu'], array_column($deleteJson['data']['presets'], 'slug'));
    }

    public function testSaveRejectsInvalidLayoutStrictly(): void
    {
        $this->auth->method('canAccessControl')->willReturn(true);
        $body = json_encode([
            'slug' => 'bad',
            'label' => 'Bad',
            'layout' => ['blocks' => [['id' => 'n-existe-pas']]],
        ], JSON_THROW_ON_ERROR);

        $response = $this->controller->save(
            $this->request('POST', $body, 'application/json'),
            new Response(),
            ['page' => 'msp1']
        );
        $this->assertSame(400, $response->getStatusCode());
    }

    private function request(string $method, string $body = '', string $contentType = ''): ServerRequestInterface
    {
        $stream = (new StreamFactory())->createStream($body);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getBody')->willReturn($stream);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => strtolower($name) === 'content-type' ? $contentType : ''
        );
        $request->method('getParsedBody')->willReturn(null);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }
}
