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
}
