<?php

declare(strict_types=1);

namespace Tests\Controller\Gallery;

use App\Controller\Gallery\GalleryUploadController;
use App\Repository\GallerySyncRepository;
use App\Service\GalleryTrashService;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

final class GalleryUploadControllerTest extends TestCase
{
    private string $previousApiKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApiKey = $_ENV['API_KEY'] ?? '';
        $_ENV['API_KEY'] = 'test-device-key';
        putenv('API_KEY=test-device-key');
        $_ENV['GALLERY_FFP3_DIR'] = 'uploads/test-ffp3';
    }

    protected function tearDown(): void
    {
        $_ENV['API_KEY'] = $this->previousApiKey;
        putenv('API_KEY=' . $this->previousApiKey);
        unset($_ENV['GALLERY_FFP3_DIR']);
        parent::tearDown();
    }

    public function testRejectsUploadWithoutApiKey(): void
    {
        $logger = $this->createMock(LogService::class);
        $trash = $this->createMock(GalleryTrashService::class);
        $controller = new GalleryUploadController($logger, $trash, $this->createMock(GallerySyncRepository::class));

        $response = (new ResponseFactory())->createResponse();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/upload');

        $result = $controller->handleBySlug($request, $response, ['slug' => 'ffp3']);
        $this->assertSame(401, $result->getStatusCode());
    }

    public function testRejectsUploadWithInvalidSignature(): void
    {
        // A4 : signature HMAC présente mais invalide -> 401 même avec une clé API valide.
        $previousSecret = $_ENV['API_SIG_SECRET'] ?? '';
        $_ENV['API_SIG_SECRET'] = 'sig-secret-xyz';
        putenv('API_SIG_SECRET=sig-secret-xyz');

        try {
            $logger = $this->createMock(LogService::class);
            $trash = $this->createMock(GalleryTrashService::class);
            $controller = new GalleryUploadController($logger, $trash, $this->createMock(GallerySyncRepository::class));

            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', '/gallery/ffp3/upload')
                ->withHeader('X-Api-Key', 'test-device-key')
                ->withHeader('X-Sig-Timestamp', (string) time())
                ->withHeader('X-Sig-Nonce', 'nonce-1')
                ->withHeader('X-Sig-Hmac', str_repeat('0', 64));

            $result = $controller->handleBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);
            $this->assertSame(401, $result->getStatusCode());
        } finally {
            $_ENV['API_SIG_SECRET'] = $previousSecret;
            putenv('API_SIG_SECRET=' . $previousSecret);
        }
    }

    public function testValidSignaturePassesAuth(): void
    {
        // A4 : signature valide sur le condensé (= clé API) -> l'auth passe (échec ensuite faute de
        // fichier => 400, et non 401). Le corps signé côté firmware est la clé API.
        $previousSecret = $_ENV['API_SIG_SECRET'] ?? '';
        $_ENV['API_SIG_SECRET'] = 'sig-secret-xyz';
        putenv('API_SIG_SECRET=sig-secret-xyz');

        try {
            $logger = $this->createMock(LogService::class);
            $trash = $this->createMock(GalleryTrashService::class);
            $controller = new GalleryUploadController($logger, $trash, $this->createMock(GallerySyncRepository::class));

            $ts = (string) time();
            $nonce = $ts . '-1';
            $sig = \App\Security\SignatureValidator::createSignatureForBody((int) $ts, $nonce, 'test-device-key', 'sig-secret-xyz');

            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', '/gallery/ffp3/upload')
                ->withHeader('X-Api-Key', 'test-device-key')
                ->withHeader('X-Sig-Timestamp', $ts)
                ->withHeader('X-Sig-Nonce', $nonce)
                ->withHeader('X-Sig-Hmac', $sig);

            $result = $controller->handleBySlug($request, (new ResponseFactory())->createResponse(), ['slug' => 'ffp3']);
            $this->assertSame(400, $result->getStatusCode());
        } finally {
            $_ENV['API_SIG_SECRET'] = $previousSecret;
            putenv('API_SIG_SECRET=' . $previousSecret);
        }
    }

    public function testReturns202WhenPhotoMovedToTrash(): void
    {
        $logger = $this->createMock(LogService::class);
        $trash = $this->createMock(GalleryTrashService::class);
        $trash->expects($this->once())
            ->method('analyzeImage')
            ->willReturn(['quality' => 'dark', 'reason' => 'trop_sombre']);
        $trash->expects($this->once())
            ->method('moveToTrash');

        $controller = new GalleryUploadController($logger, $trash, $this->createMock(GallerySyncRepository::class));
        $response = (new ResponseFactory())->createResponse();

        $jpeg = tempnam(sys_get_temp_dir(), 'cam') . '.jpg';
        file_put_contents(
            $jpeg,
            base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAT/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAwT/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdAAf/2Q==')
        );

        $streamFactory = new StreamFactory();
        $uploaded = new UploadedFile(
            $streamFactory->createStreamFromFile($jpeg, 'r'),
            'test.jpg',
            'image/jpeg',
            filesize($jpeg),
            UPLOAD_ERR_OK
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/upload')
            ->withHeader('X-Api-Key', (string) ($_ENV['API_KEY'] ?? 'test-device-key'))
            ->withUploadedFiles(['imageFile' => $uploaded]);

        $result = $controller->handleBySlug($request, $response, ['slug' => 'ffp3']);
        $this->assertSame(202, $result->getStatusCode());
        $this->assertStringContainsString('corbeille auto', (string) $result->getBody());
        @unlink($jpeg);
    }

    public function testUsesCaptureHeadersForFilename(): void
    {
        $logger = $this->createMock(LogService::class);
        $trash = $this->createMock(GalleryTrashService::class);
        $trash->method('analyzeImage')->willReturn(['quality' => 'ok', 'reason' => '']);

        $controller = new GalleryUploadController($logger, $trash, $this->createMock(GallerySyncRepository::class));
        $response = (new ResponseFactory())->createResponse();

        $jpeg = tempnam(sys_get_temp_dir(), 'cam') . '.jpg';
        file_put_contents(
            $jpeg,
            base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAT/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAwT/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdAAf/2Q==')
        );
        $uploaded = new UploadedFile(
            (new StreamFactory())->createStreamFromFile($jpeg, 'r'),
            'test.jpg',
            'image/jpeg',
            filesize($jpeg),
            UPLOAD_ERR_OK
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/gallery/ffp3/upload')
            ->withHeader('X-Api-Key', (string) ($_ENV['API_KEY'] ?? 'test-device-key'))
            ->withHeader('X-Capture-Seq', '42')
            ->withHeader('X-Captured-At', '2026-06-26_14-35-22')
            ->withUploadedFiles(['imageFile' => $uploaded]);

        $result = $controller->handleBySlug($request, $response, ['slug' => 'ffp3']);
        $this->assertSame(200, $result->getStatusCode());

        // Le nom doit être N-first (compteur zéro-paddé) + heure de capture fournie.
        $body = (string) $result->getBody();
        $this->assertMatchesRegularExpression('/0000000042_2026-06-26_14-35-22_[0-9a-f]{8}\.jpg/', $body);

        // Nettoyage du fichier réellement écrit.
        if (preg_match('/(0000000042_2026-06-26_14-35-22_[0-9a-f]{8}\.jpg)/', $body, $m) === 1) {
            @unlink(\App\Config\Paths::getProjectRoot() . '/uploads/test-ffp3/' . $m[1]);
        }
        @unlink($jpeg);
    }
}
