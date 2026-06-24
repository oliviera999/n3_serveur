<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\Ffp3\PostDataController;
use App\Domain\SensorData;
use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorRepository;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class Ffp3PostDataControllerTest extends TestCase
{
    public function testPostDataEffectsAreExecutedInsideAtomicInsert(): void
    {
        $oldApiKey = $_ENV['API_KEY'] ?? null;
        $_ENV['API_KEY'] = 'unit-test-key';

        try {
            /** @var SensorRepository&MockObject $sensorRepo */
            $sensorRepo = $this->getMockBuilder(SensorRepository::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['existsByPostId', 'insertAtomically'])
                ->getMock();

            /** @var OutputRepository&MockObject $outputRepo */
            $outputRepo = $this->getMockBuilder(OutputRepository::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['ensureAquariumPumpForceRowExists', 'ensureServoAngleRowsExist', 'syncStatesFromSensorData'])
                ->getMock();

            /** @var BoardRepository&MockObject $boardRepo */
            $boardRepo = $this->getMockBuilder(BoardRepository::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['updateLastRequest'])
                ->getMock();

            $sensorRepo->expects($this->once())
                ->method('existsByPostId')
                ->with('retry-123')
                ->willReturn(false);

            $sensorRepo->expects($this->once())
                ->method('insertAtomically')
                ->with(
                    $this->callback(fn (SensorData $data): bool => $data->postId === 'retry-123'),
                    $this->isType('callable')
                )
                ->willReturnCallback(function (SensorData $data, callable $afterInsert): void {
                    $afterInsert($data);
                });

            $outputRepo->expects($this->once())
                ->method('ensureAquariumPumpForceRowExists');
            $outputRepo->expects($this->once())
                ->method('ensureServoAngleRowsExist');
            $outputRepo->expects($this->once())
                ->method('syncStatesFromSensorData')
                ->with($this->callback(fn (SensorData $data): bool => $data->postId === 'retry-123'));
            $boardRepo->expects($this->once())
                ->method('updateLastRequest')
                ->with('1')
                ->willReturn(true);

            $controller = new PostDataController(
                $this->createMock(LogService::class),
                $this->createMock(ErrorAlertService::class),
                $sensorRepo,
                $outputRepo,
                $boardRepo
            );

            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', '/post-data')
                ->withParsedBody([
                    'api_key' => 'unit-test-key',
                    'sensor' => 'n3-ffp-serre',
                    'version' => '13.51',
                    'post_id' => 'retry-123',
                    'TempAir' => '24.2',
                    'etatPompeAqua' => '1',
                    'etatPompeTank' => '0',
                    'etatHeat' => '0',
                    'etatUV' => '1',
                    'resetMode' => '0',
                    'configSynced' => '1',
                ]);

            $response = $controller->handle($request, (new ResponseFactory())->createResponse());

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('Donnees enregistrees', (string) $response->getBody());
        } finally {
            if ($oldApiKey !== null) {
                $_ENV['API_KEY'] = $oldApiKey;
            } else {
                unset($_ENV['API_KEY']);
            }
        }
    }
}
