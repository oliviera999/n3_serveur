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
use Slim\Psr7\Factory\ResponseFactory;
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
        );
    }

    public function testTriggerResetStepReturnsStateZero(): void
    {
        TableConfig::setEnvironment('test');

        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isManualFeedGpio')->willReturn(true);
        $outputService->expects($this->once())
            ->method('triggerManualFeedStep')
            ->with(5, 108, 'reset')
            ->willReturn(['success' => true, 'gpio' => 108, 'state' => 0, 'step' => 'reset']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs-test/trigger-feed')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['id' => 5, 'gpio' => 108, 'step' => 'reset']);

        $response = $this->makeController($outputService)->triggerManualFeed($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $body['status'] ?? null);
        $this->assertSame(0, $body['state'] ?? null);
        $this->assertSame('reset', $body['step'] ?? null);
    }

    public function testTriggerStepReturnsFeedCmdId(): void
    {
        TableConfig::setEnvironment('test');

        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isManualFeedGpio')->willReturn(true);
        $outputService->expects($this->once())
            ->method('triggerManualFeedStep')
            ->with(7, 109, 'trigger')
            ->willReturn([
                'success' => true,
                'gpio' => 109,
                'state' => 1,
                'step' => 'trigger',
                'feed_cmd_id' => 'aabbccddeeff0011',
            ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs-test/trigger-feed')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['id' => 7, 'gpio' => 109, 'step' => 'trigger']);

        $response = $this->makeController($outputService)->triggerManualFeed($request, new Response());
        $body = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('aabbccddeeff0011', $body['feed_cmd_id'] ?? null);
    }

    public function testInvalidGpioReturns400(): void
    {
        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isManualFeedGpio')->willReturn(false);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs/trigger-feed')
            ->withParsedBody(['id' => 1, 'gpio' => 16, 'step' => 'trigger']);

        $response = $this->makeController($outputService)->triggerManualFeed($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
    }
}
