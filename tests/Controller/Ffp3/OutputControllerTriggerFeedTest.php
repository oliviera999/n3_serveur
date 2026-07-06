<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Config\TableConfig;
use App\Controller\Ffp3\OutputController;
use App\Repository\NotificationPolicyRepository;
use App\Repository\SensorReadRepository;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use App\Service\OutputCacheService;
use App\Service\OutputService;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class OutputControllerTriggerFeedTest extends TestCase
{
    private function makeController(OutputService $outputService): OutputController
    {
        return new OutputController(
            $outputService,
            $this->createMock(TemplateRenderer::class),
            $this->createMock(SensorReadRepository::class),
            $this->createMock(OutputCacheService::class),
            $this->createMock(LogService::class),
            $this->createMock(ControlAuditLogger::class),
            $this->createMock(NotificationPolicyRepository::class),
            $this->createMock(\App\Service\NotificationPolicySaveService::class),
            $this->createMock(\App\Service\NotificationService::class),
        );
    }

    public function testTriggerManualFeedIncrementsAndReturnsCounter(): void
    {
        TableConfig::setEnvironment('test');

        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isManualFeedGpio')->willReturn(true);
        $outputService->expects($this->once())
            ->method('triggerManualFeed')
            ->with(7, 109)
            ->willReturn([
                'success' => true,
                'gpio' => 109,
                'counter' => 12,
                'feed_cmd_id' => 'aabbccddeeff0011',
            ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs-test/trigger-feed')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['id' => 7, 'gpio' => 109]);

        $response = $this->makeController($outputService)->triggerManualFeed($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $body['status'] ?? null);
        $this->assertSame(12, $body['counter'] ?? null);
        $this->assertSame('aabbccddeeff0011', $body['feed_cmd_id'] ?? null);
    }

    public function testLegacyResetStepIsNoOp(): void
    {
        TableConfig::setEnvironment('test');

        // Un client encore en cache peut envoyer step:"reset" : ne doit PAS incrémenter.
        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isManualFeedGpio')->willReturn(true);
        $outputService->expects($this->never())->method('triggerManualFeed');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs-test/trigger-feed')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['id' => 5, 'gpio' => 108, 'step' => 'reset']);

        $response = $this->makeController($outputService)->triggerManualFeed($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($body['noop'] ?? false);
    }

    public function testInvalidGpioReturns400(): void
    {
        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isManualFeedGpio')->willReturn(false);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs/trigger-feed')
            ->withParsedBody(['id' => 1, 'gpio' => 16]);

        $response = $this->makeController($outputService)->triggerManualFeed($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
    }
}
