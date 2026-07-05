<?php

declare(strict_types=1);

namespace Tests\Controller\Gallery;

use App\Controller\Gallery\GallerySyncController;
use App\Repository\GalleryControlRepository;
use App\Repository\GallerySyncRepository;
use App\Security\AuthService;
use App\Service\LogService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class GallerySyncControllerTest extends TestCase
{
    private string $previousApiKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApiKey = $_ENV['API_KEY'] ?? '';
        $_ENV['API_KEY'] = 'test-device-key';
        putenv('API_KEY=test-device-key');
    }

    protected function tearDown(): void
    {
        $_ENV['API_KEY'] = $this->previousApiKey;
        putenv('API_KEY=' . $this->previousApiKey);
        parent::tearDown();
    }

    /**
     * @param array{sync?: GallerySyncRepository, control?: GalleryControlRepository, auth?: AuthService, notif?: NotificationService} $deps
     */
    private function createController(array $deps = []): GallerySyncController
    {
        $sync = $deps['sync'] ?? $this->createMock(GallerySyncRepository::class);
        $control = $deps['control'] ?? $this->createMock(GalleryControlRepository::class);
        $notif = $deps['notif'] ?? $this->createMock(NotificationService::class);
        $auth = $deps['auth'] ?? $this->createMock(AuthService::class);
        $logger = $this->createMock(LogService::class);

        return new GallerySyncController($sync, $control, $notif, $auth, $logger);
    }

    private function apiKey(): string
    {
        return (string) ($_ENV['API_KEY'] ?? 'test-device-key');
    }

    public function testStartRejectsWithoutApiKey(): void
    {
        $controller = $this->createController();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/gallery/ffp3/api/sync/start');
        $result = $controller->startBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

        $this->assertSame(401, $result->getStatusCode());
    }

    public function testStartRejectsUnknownSlug(): void
    {
        $controller = $this->createController();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/gallery/x/api/sync/start');
        $result = $controller->startBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'nope']);

        $this->assertSame(404, $result->getStatusCode());
    }

    public function testStartRequiresDeviceSession(): void
    {
        $control = $this->createMock(GalleryControlRepository::class);
        $control->method('getModule')->willReturn(['board' => 5, 'table' => 'UploadPhoto1Outputs', 'label' => 'FFP3']);

        $controller = $this->createController(['control' => $control]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/api/sync/start?total=4')
            ->withHeader('X-Api-Key', $this->apiKey());
        $result = $controller->startBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

        $this->assertSame(400, $result->getStatusCode());
    }

    public function testStartOpensSessionAndReturnsId(): void
    {
        $control = $this->createMock(GalleryControlRepository::class);
        $control->method('getModule')->willReturn(['board' => 5, 'table' => 'UploadPhoto1Outputs', 'label' => 'FFP3']);

        $sync = $this->createMock(GallerySyncRepository::class);
        $sync->expects($this->once())
            ->method('startSession')
            ->with('ffp3', 5, 'boot-42', 7, '2.40')
            ->willReturn(['id' => 99, 'total' => 7]);

        $controller = $this->createController(['control' => $control, 'sync' => $sync]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/api/sync/start?device_session=boot-42&total=7&version=2.40&board=5')
            ->withHeader('X-Api-Key', $this->apiKey());
        $result = $controller->startBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(99, $payload['session']);
        $this->assertSame(7, $payload['total']);
    }

    public function testFinishRequiresSession(): void
    {
        $controller = $this->createController();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/api/sync/finish')
            ->withHeader('X-Api-Key', $this->apiKey());
        $result = $controller->finishBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

        $this->assertSame(400, $result->getStatusCode());
    }

    public function testFinishClosesSessionAndSendsReport(): void
    {
        $control = $this->createMock(GalleryControlRepository::class);
        $control->method('getModule')->willReturn(['board' => 5, 'table' => 'UploadPhoto1Outputs', 'label' => 'FFP3']);

        $sync = $this->createMock(GallerySyncRepository::class);
        $sync->expects($this->once())
            ->method('finishSession')
            ->willReturn([
                'id' => 99,
                'slug' => 'ffp3',
                'total' => 5,
                'received' => 5,
                'failed' => 0,
                'bytes_received' => 123456,
                'status' => 'completed',
                'firmware_version' => '2.40',
                'started_at' => '2026-06-26 10:00:00',
                'finished_at' => '2026-06-26 10:02:30',
            ]);

        $notif = $this->createMock(NotificationService::class);
        $notif->expects($this->once())->method('sendGalleryTransferReport')->willReturn(true);

        $controller = $this->createController(['control' => $control, 'sync' => $sync, 'notif' => $notif]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/api/sync/finish?session=99&sent=5&failed=0&bytes=123456')
            ->withHeader('X-Api-Key', $this->apiKey());
        $result = $controller->finishBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testFinishSkipsReportWhenNotFinalAndPartial(): void
    {
        // A2 : clôture non finale (backlog non vidé, received < total, pas de final=1) -> pas de mail.
        $sync = $this->createMock(GallerySyncRepository::class);
        $sync->method('finishSession')->willReturn([
            'id' => 99,
            'slug' => 'ffp3',
            'total' => 90,
            'received' => 16,
            'failed' => 0,
            'bytes_received' => 123,
            'status' => 'completed',
        ]);

        $notif = $this->createMock(NotificationService::class);
        $notif->expects($this->never())->method('sendGalleryTransferReport');

        $controller = $this->createController(['sync' => $sync, 'notif' => $notif]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/api/sync/finish?session=99&sent=16&failed=0&bytes=123')
            ->withHeader('X-Api-Key', $this->apiKey());
        $result = $controller->finishBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testFinishSendsReportWhenFinalFlagSet(): void
    {
        // A2 : final=1 (backlog vidé) déclenche le récap même si received < total (dernier lot).
        $control = $this->createMock(GalleryControlRepository::class);
        $control->method('getModule')->willReturn(['board' => 5, 'table' => 'UploadPhoto1Outputs', 'label' => 'FFP3']);

        $sync = $this->createMock(GallerySyncRepository::class);
        $sync->method('finishSession')->willReturn([
            'id' => 99,
            'slug' => 'ffp3',
            'total' => 90,
            'received' => 16,
            'failed' => 0,
            'bytes_received' => 123,
            'status' => 'completed',
        ]);

        $notif = $this->createMock(NotificationService::class);
        $notif->expects($this->once())->method('sendGalleryTransferReport')->willReturn(true);

        $controller = $this->createController(['control' => $control, 'sync' => $sync, 'notif' => $notif]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/api/sync/finish?session=99&sent=16&failed=0&bytes=123&final=1')
            ->withHeader('X-Api-Key', $this->apiKey());
        $result = $controller->finishBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testStartRejectsInvalidSignature(): void
    {
        // A4 : signature HMAC présente mais invalide -> 401 (la clé API seule ne suffit plus).
        $previousSecret = $_ENV['API_SIG_SECRET'] ?? '';
        $_ENV['API_SIG_SECRET'] = 'sig-secret-xyz';
        putenv('API_SIG_SECRET=sig-secret-xyz');

        try {
            $control = $this->createMock(GalleryControlRepository::class);
            $control->method('getModule')->willReturn(['board' => 5, 'table' => 'UploadPhoto1Outputs', 'label' => 'FFP3']);

            $controller = $this->createController(['control' => $control]);
            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', '/gallery/ffp3/api/sync/start?device_session=boot-1&total=3&board=5')
                ->withHeader('X-Api-Key', $this->apiKey())
                ->withHeader('X-Sig-Timestamp', (string) time())
                ->withHeader('X-Sig-Nonce', 'nonce-1')
                ->withHeader('X-Sig-Hmac', str_repeat('0', 64));
            $result = $controller->startBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

            $this->assertSame(401, $result->getStatusCode());
        } finally {
            $_ENV['API_SIG_SECRET'] = $previousSecret;
            putenv('API_SIG_SECRET=' . $previousSecret);
        }
    }

    public function testStartAcceptsValidSignature(): void
    {
        // A4 : signature HMAC valide sur le corps brut (vide ici) -> la requête est acceptée.
        $previousSecret = $_ENV['API_SIG_SECRET'] ?? '';
        $_ENV['API_SIG_SECRET'] = 'sig-secret-xyz';
        putenv('API_SIG_SECRET=sig-secret-xyz');

        try {
            $control = $this->createMock(GalleryControlRepository::class);
            $control->method('getModule')->willReturn(['board' => 5, 'table' => 'UploadPhoto1Outputs', 'label' => 'FFP3']);

            $sync = $this->createMock(GallerySyncRepository::class);
            $sync->method('startSession')->willReturn(['id' => 7, 'total' => 3]);

            $ts = (string) time();
            $nonce = $ts . '-1';
            $sig = \App\Security\SignatureValidator::createSignatureForBody((int) $ts, $nonce, '', 'sig-secret-xyz');

            $controller = $this->createController(['control' => $control, 'sync' => $sync]);
            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', '/gallery/ffp3/api/sync/start?device_session=boot-1&total=3&board=5')
                ->withHeader('X-Api-Key', $this->apiKey())
                ->withHeader('X-Sig-Timestamp', $ts)
                ->withHeader('X-Sig-Nonce', $nonce)
                ->withHeader('X-Sig-Hmac', $sig);
            $result = $controller->startBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

            $this->assertSame(200, $result->getStatusCode());
        } finally {
            $_ENV['API_SIG_SECRET'] = $previousSecret;
            putenv('API_SIG_SECRET=' . $previousSecret);
        }
    }

    public function testFinishRejectsSessionFromAnotherGallery(): void
    {
        $sync = $this->createMock(GallerySyncRepository::class);
        $sync->method('finishSession')->willReturn(['id' => 99, 'slug' => 'msp1', 'status' => 'completed']);

        $notif = $this->createMock(NotificationService::class);
        $notif->expects($this->never())->method('sendGalleryTransferReport');

        $controller = $this->createController(['sync' => $sync, 'notif' => $notif]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/api/sync/finish?session=99')
            ->withHeader('X-Api-Key', $this->apiKey());
        $result = $controller->finishBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

        $this->assertSame(400, $result->getStatusCode());
    }

    public function testStatusRequiresAuth(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('isAuthenticated')->willReturn(false);
        $auth->method('isAuthenticatedByToken')->willReturn(false);

        $controller = $this->createController(['auth' => $auth]);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/gallery/ffp3/api/sync/status');
        $result = $controller->statusBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

        $this->assertSame(401, $result->getStatusCode());
    }

    public function testStatusReturnsJsonWhenAuthenticated(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('isAuthenticated')->willReturn(true);

        $sync = $this->createMock(GallerySyncRepository::class);
        $sync->method('getStatus')->willReturn([
            'slug' => 'ffp3',
            'connection' => 'transferring',
            'transfer_active' => true,
            'total' => 10,
            'received' => 4,
            'failed' => 0,
            'percent' => 40,
            'session_id' => 99,
            'firmware_version' => '2.40',
            'started_at' => null,
            'updated_at' => null,
            'finished_at' => null,
            'last_activity' => null,
        ]);

        $controller = $this->createController(['auth' => $auth, 'sync' => $sync]);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/gallery/ffp3/api/sync/status');
        $result = $controller->statusBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);

        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertSame('transferring', $payload['connection']);
        $this->assertSame(40, $payload['percent']);
    }
}
