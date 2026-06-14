<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorReadRepository;
use App\Service\OutputService;
use PHPUnit\Framework\TestCase;

/**
 * H10 — updateStateById()/updateStateByGpio() délèguent la persistance au
 * OutputRepository injecté (plus de Database::getConnection()/TableConfig direct
 * dans le service). On vérifie la délégation et la validation d'état (0/1).
 */
class OutputServiceUpdateStateTest extends TestCase
{
    private function makeService(OutputRepository $outputRepo): OutputService
    {
        return new OutputService(
            $outputRepo,
            $this->createMock(BoardRepository::class),
            $this->createMock(SensorReadRepository::class),
        );
    }

    /** Un état valide (0 ou 1) est délégué tel quel au repository. */
    public function testUpdateStateByIdDelegatesToRepositoryForValidState(): void
    {
        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->expects($this->once())
            ->method('updateStateById')
            ->with(42, 1, 'web')
            ->willReturn(true);

        $service = $this->makeService($outputRepo);

        $this->assertTrue($service->updateStateById(42, 1, 'web'));
    }

    /** L'état 0 est aussi délégué (valeur booléenne valide). */
    public function testUpdateStateByIdDelegatesStateZero(): void
    {
        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->expects($this->once())
            ->method('updateStateById')
            ->with(7, 0, 'web')
            ->willReturn(true);

        $service = $this->makeService($outputRepo);

        $this->assertTrue($service->updateStateById(7, 0));
    }

    /** Un état invalide (hors {0,1}) est rejeté AVANT toute écriture en base. */
    public function testUpdateStateByIdRejectsInvalidStateWithoutRepositoryCall(): void
    {
        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->expects($this->never())->method('updateStateById');

        $service = $this->makeService($outputRepo);

        $this->assertFalse($service->updateStateById(1, 5));
        $this->assertFalse($service->updateStateById(1, -1));
    }

    /** Propage l'échec du repository (return false) sans le masquer. */
    public function testUpdateStateByIdPropagatesRepositoryFailure(): void
    {
        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->expects($this->once())
            ->method('updateStateById')
            ->with(9, 1, 'web')
            ->willReturn(false);

        $service = $this->makeService($outputRepo);

        $this->assertFalse($service->updateStateById(9, 1));
    }

    /** updateStateByGpio() délègue aussi à OutputRepository::updateState(). */
    public function testUpdateStateByGpioDelegatesToRepository(): void
    {
        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->expects($this->once())
            ->method('updateState')
            ->with(16, 1)
            ->willReturn(true);

        $service = $this->makeService($outputRepo);

        $this->assertTrue($service->updateStateByGpio(16, 1));
    }

    /** updateStateByGpio() valide l'état avant délégation. */
    public function testUpdateStateByGpioRejectsInvalidState(): void
    {
        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->expects($this->never())->method('updateState');

        $service = $this->makeService($outputRepo);

        $this->assertFalse($service->updateStateByGpio(16, 2));
    }
}
