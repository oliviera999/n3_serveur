<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Config\TableConfig;
use App\Controller\Ffp3\OutputController;
use App\Repository\SensorReadRepository;
use App\Service\ControlAuditLogger;
use App\Service\LogService;
use App\Service\OutputCacheService;
use App\Service\OutputService;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * H12 — Les 5 méthodes toggle (prod/test/test3/s3/s3test) ont été fusionnées en
 * une seule `toggleOutput()`. L'environnement est fixé en amont par
 * EnvironmentMiddleware (ici simulé via TableConfig::setEnvironment), donc le même
 * handler doit produire le même contrat JSON quel que soit l'environnement.
 */
class OutputControllerToggleTest extends TestCase
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
        );
    }

    /** Une seule méthode publique `toggleOutput` doit exister (les 4 alias ont été supprimés). */
    public function testOnlyOneToggleHandlerExists(): void
    {
        $this->assertTrue(method_exists(OutputController::class, 'toggleOutput'));

        foreach (['toggleOutputTest', 'toggleOutputTest3', 'toggleOutputS3', 'toggleOutputS3Test'] as $removed) {
            $this->assertFalse(
                method_exists(OutputController::class, $removed),
                "La méthode toggle dupliquée {$removed} aurait dû être supprimée (H12)"
            );
        }
    }

    private function postToggle(int $id, int $state, int $gpio = 0): \Slim\Psr7\Request
    {
        $body = ['id' => $id, 'state' => $state, 'gpio' => $gpio];
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/ffp3/api/outputs/toggle')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($body);
    }

    /**
     * Le même handler `toggleOutput` renvoie le contrat de succès attendu quel que
     * soit l'environnement courant (prod/test/test3/s3/s3test).
     *
     * @dataProvider environmentProvider
     */
    public function testToggleOutputSameContractAcrossEnvironments(string $env): void
    {
        TableConfig::setEnvironment($env);

        $outputService = $this->createMock(OutputService::class);
        $outputService->expects($this->once())
            ->method('updateStateById')
            ->with(12, 1, 'web')
            ->willReturn(true);
        // gpio quelconque (15, lumière) : pas de logique de forçage pompe aquarium.
        $outputService->method('isAquariumPumpForceEnabled')->willReturn(false);
        $outputService->method('isGpioAllowed')->willReturn(true);

        $controller = $this->makeController($outputService);
        $response = $controller->toggleOutput($this->postToggle(12, 1, 15), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(12, $payload['id']);
        $this->assertSame(1, $payload['state']);
    }

    /** Paramètres invalides -> 400, même handler, quel que soit l'environnement. */
    public function testToggleOutputInvalidParamsReturns400(): void
    {
        TableConfig::setEnvironment('test');

        $outputService = $this->createMock(OutputService::class);
        $outputService->expects($this->never())->method('updateStateById');

        $controller = $this->makeController($outputService);
        // state=2 invalide
        $response = $controller->toggleOutput($this->postToggle(12, 2, 5), new Response());

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('error', $payload['status']);
    }

    /** Échec de persistance -> 500. */
    public function testToggleOutputPersistenceFailureReturns500(): void
    {
        TableConfig::setEnvironment('s3');

        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isAquariumPumpForceEnabled')->willReturn(false);
        $outputService->method('isGpioAllowed')->willReturn(true);
        $outputService->method('updateStateById')->willReturn(false);

        $controller = $this->makeController($outputService);
        $response = $controller->toggleOutput($this->postToggle(12, 1, 15), new Response());

        $this->assertSame(500, $response->getStatusCode());
    }

    /**
     * Validation stricte (Phase 2) : un GPIO hors de l'ensemble canonique autorisé
     * est rejeté en 400 AVANT toute persistance ; updateStateById n'est pas appelé.
     */
    public function testToggleOutputUnauthorizedGpioReturns400(): void
    {
        TableConfig::setEnvironment('test');

        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isGpioAllowed')->willReturn(false);
        $outputService->expects($this->never())->method('updateStateById');

        $controller = $this->makeController($outputService);
        // gpio 9999 inconnu -> 400
        $response = $controller->toggleOutput($this->postToggle(12, 1, 9999), new Response());

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('error', $payload['status']);
    }

    /**
     * Validation stricte (Phase 2) : un GPIO appartenant à l'ensemble canonique autorisé
     * (ici 16, pompe aquarium) est accepté et persisté (contrat de succès inchangé).
     */
    public function testToggleOutputAuthorizedGpioAccepted(): void
    {
        TableConfig::setEnvironment('test');

        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isGpioAllowed')->willReturn(true);
        $outputService->method('isAquariumPumpForceEnabled')->willReturn(false);
        $outputService->expects($this->once())
            ->method('updateStateById')
            ->with(12, 1, 'web')
            ->willReturn(true);

        $controller = $this->makeController($outputService);
        $response = $controller->toggleOutput($this->postToggle(12, 1, 16), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(12, $payload['id']);
    }

    /** GPIO 117 (forçage pompe) : persistance par GPIO, pas par id (évite faux succès id obsolète). */
    public function testToggleAquariumPumpForceUsesGpioUpdate(): void
    {
        TableConfig::setEnvironment('test');

        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isGpioAllowed')->willReturn(true);
        $outputService->method('isAquariumPumpForceEnabled')->willReturn(false);
        $outputService->expects($this->never())->method('updateStateById');
        $outputService->expects($this->once())
            ->method('updateStateByGpio')
            ->with(117, 0)
            ->willReturn(true);

        $controller = $this->makeController($outputService);
        $response = $controller->toggleOutput($this->postToggle(999, 0, 117), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(0, $payload['state']);
    }

    /** GPIO 117 activé : miroir GPIO 16 à 1 via updateStateByGpio. */
    public function testToggleAquariumPumpForceOnMirrorsPumpToOne(): void
    {
        TableConfig::setEnvironment('test');

        $outputService = $this->createMock(OutputService::class);
        $outputService->method('isGpioAllowed')->willReturn(true);
        $outputService->method('isAquariumPumpForceEnabled')->willReturn(false);
        $outputService->expects($this->never())->method('updateStateById');
        $outputService->expects($this->exactly(2))
            ->method('updateStateByGpio')
            ->willReturnCallback(function (int $gpio, int $state): bool {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    $this->assertSame(117, $gpio);
                    $this->assertSame(1, $state);
                } else {
                    $this->assertSame(16, $gpio);
                    $this->assertSame(1, $state);
                }

                return true;
            });

        $controller = $this->makeController($outputService);
        $response = $controller->toggleOutput($this->postToggle(22, 1, 117), new Response());

        $this->assertSame(200, $response->getStatusCode());
    }

    /** @return array<string, array{0:string}> */
    public static function environmentProvider(): array
    {
        return [
            'prod' => ['prod'],
            'test' => ['test'],
            'test3' => ['test3'],
            's3' => ['s3'],
            's3test' => ['s3test'],
        ];
    }

    protected function tearDown(): void
    {
        TableConfig::setEnvironment('prod');
    }
}
