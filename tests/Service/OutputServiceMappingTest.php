<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\OutputService;
use App\Service\OutputSyncService;
use PHPUnit\Framework\TestCase;

/**
 * H09 — Le mapping GPIO↔nom de paramètre d'OutputService est dérivé d'une source
 * unique (OutputSyncService::getGpioMapping()) et n'est plus dupliqué dans le service.
 *
 * Vérifie getParametersMap() (lecture) et updateMultipleParameters() (écriture déléguée).
 */
class OutputServiceMappingTest extends TestCase
{
    private function makeService(
        ?OutputRepository $outputRepo = null,
        ?BoardRepository $boardRepo = null,
        ?SensorReadRepository $sensorRepo = null
    ): OutputService {
        return new OutputService(
            $outputRepo ?? $this->createMock(OutputRepository::class),
            $boardRepo ?? $this->createMock(BoardRepository::class),
            $sensorRepo ?? $this->createMock(SensorReadRepository::class),
        );
    }

    /**
     * getParametersMap() doit interroger le repo uniquement sur les GPIO du mapping de
     * paramètres dérivé d'OutputSyncService, puis indexer les valeurs par nom de paramètre.
     */
    public function testGetParametersMapDerivesParamNamesFromGpioMapping(): void
    {
        $outputRepo = $this->createMock(OutputRepository::class);

        // Le service doit demander la liste des GPIO du mapping de paramètres (filtrage IN).
        $outputRepo->expects($this->once())
            ->method('findByGpios')
            ->with($this->callback(function (array $gpios): bool {
                // Doit contenir les GPIO de config présents dans OutputSyncService.
                foreach ([102, 103, 104, 105, 106, 107, 111, 112, 113, 114, 115, 116] as $expected) {
                    if (!in_array($expected, $gpios, true)) {
                        return false;
                    }
                }
                return true;
            }))
            ->willReturn([
                ['gpio' => 102, 'state' => 7],   // aqThreshold -> aqThr
                ['gpio' => 105, 'state' => 8],   // bouffeMatin -> bouffeMatin
                ['gpio' => 115, 'state' => 1],   // WakeUp -> WakeUp
            ]);

        $service = $this->makeService($outputRepo);
        $map = $service->getParametersMap();

        $this->assertSame(7, $map['aqThr']);
        $this->assertSame(8, $map['bouffeMatin']);
        $this->assertSame(1, $map['WakeUp']);
    }

    /**
     * Cohérence : chaque GPIO de paramètre exposé par getParametersMap() provient bien
     * du mapping unique d'OutputSyncService (pas d'une constante locale dupliquée).
     */
    public function testParameterGpiosAreSubsetOfSyncServiceMapping(): void
    {
        $syncGpios = array_keys(OutputSyncService::getGpioMapping());

        $outputRepo = $this->createMock(OutputRepository::class);
        $captured = [];
        $outputRepo->method('findByGpios')
            ->willReturnCallback(function (array $gpios) use (&$captured): array {
                $captured = $gpios;
                return [];
            });

        $service = $this->makeService($outputRepo);
        $service->getParametersMap();

        $this->assertNotEmpty($captured);
        foreach ($captured as $gpio) {
            $this->assertContains(
                $gpio,
                $syncGpios,
                "GPIO {$gpio} interrogé par getParametersMap absent du mapping OutputSyncService"
            );
        }
    }

    /**
     * updateMultipleParameters() délègue la persistance au repository et renvoie le
     * compte de lignes mises à jour (la source de mapping/SQL reste le repo).
     */
    public function testUpdateMultipleParametersDelegatesToRepository(): void
    {
        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->expects($this->once())
            ->method('updateMultipleParameters')
            ->with(['aqThr' => 5, 'WakeUp' => 1], 'web')
            ->willReturn(2);
        // Pas d'horaire de nourrissage -> pas d'appel à getParametersMap pour les warnings.
        $outputRepo->expects($this->never())->method('findByGpios');

        $service = $this->makeService($outputRepo);
        $result = $service->updateMultipleParameters(['aqThr' => 5, 'WakeUp' => 1]);

        $this->assertSame(2, $result['updated']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Un horaire de nourrissage hors de [0..23] est une contrainte dure :
     * \InvalidArgumentException, sans persistance.
     */
    public function testUpdateMultipleParametersRejectsOutOfRangeFeedingHour(): void
    {
        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->expects($this->never())->method('updateMultipleParameters');

        $service = $this->makeService($outputRepo);

        $this->expectException(\InvalidArgumentException::class);
        $service->updateMultipleParameters(['bouffeMatin' => 42]);
    }
}
