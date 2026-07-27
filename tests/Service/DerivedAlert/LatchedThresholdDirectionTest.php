<?php

declare(strict_types=1);

namespace Tests\Service\DerivedAlert;

use App\Repository\AbstractSensorRepository;
use App\Service\DerivedAlert\AbstractVitalsDerivedAlertService;
use App\Service\DerivedAlert\DerivedAlertStateStore;
use App\Service\LogService;
use App\Service\NotificationService;
use PHPUnit\Framework\TestCase;

/**
 * Couvre le squelette latch + hystérésis d'{@see AbstractVitalsDerivedAlertService},
 * et en particulier la variante SEUIL HAUT (`DIRECTION_HIGH`), implémentée en
 * réutilisant l'évaluateur « valeur basse » sur des valeurs niées.
 *
 * Les tests métier (gel, canicule, batterie, sol sec) vivent dans
 * VitalsDerivedAlertServiceTest ; ici on cible le helper lui-même, notamment le
 * chemin `$clearThreshold = null` qu'aucun appelant n'emprunte aujourd'hui — c'est
 * précisément là que se cachait l'hystérésis inversée corrigée en 6.33.0.
 */
final class LatchedThresholdDirectionTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/n3_latch_direction_' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    private function service(): LatchDirectionProbe
    {
        return new LatchDirectionProbe(
            $this->createMock(AbstractSensorRepository::class),
            $this->createMock(NotificationService::class),
            $this->createMock(LogService::class),
            new DerivedAlertStateStore($this->stateFile),
        );
    }

    /**
     * RÉGRESSION 6.33.0 — hystérésis inversée pour `DIRECTION_HIGH` sans seuil explicite.
     *
     * Le défaut était calculé APRÈS la négation (`t + t/20` appliqué à `-threshold`),
     * soit `-1,05 × threshold` : le ré-armement se produisait à `value < 1,05 × seuil`,
     * donc AU-DESSUS du déclenchement (`value > seuil`). Toute valeur de la bande
     * `]seuil ; 1,05 × seuil[` déclenchait puis ré-armait à chaque évaluation.
     *
     * Ici : seuil 30, bande piégeuse `]30 ; 31,5[`. À 30,5 l'alerte doit se lever
     * UNE fois et RESTER latchée.
     */
    public function testHighDirectionDefaultHysteresisDoesNotFlap(): void
    {
        $service = $this->service();
        $state = [];

        $this->assertSame(1, $service->probeHigh($state, 30.5, 30.0, null), 'levée initiale');
        $this->assertTrue($state['probe']);

        // Mêmes valeurs, ticks suivants : aucun ré-armement, aucune nouvelle levée.
        $this->assertSame(0, $service->probeHigh($state, 30.5, 30.0, null));
        $this->assertSame(0, $service->probeHigh($state, 31.0, 30.0, null));
        $this->assertTrue($state['probe'], 'le latch doit tenir dans la bande d\'hystérésis');
    }

    /**
     * Le ré-armement par défaut se produit bien SOUS le seuil (30 - 5 % = 28,5),
     * et une nouvelle levée redevient alors possible.
     */
    public function testHighDirectionDefaultClearsBelowThreshold(): void
    {
        $service = $this->service();
        $state = [];

        $service->probeHigh($state, 35.0, 30.0, null);
        $this->assertTrue($state['probe']);

        // 29 : sous le seuil mais AU-DESSUS du ré-armement (28,5) -> toujours latché.
        $this->assertSame(0, $service->probeHigh($state, 29.0, 30.0, null));
        $this->assertTrue($state['probe']);

        // 28 : sous 28,5 -> ré-armement.
        $this->assertSame(-1, $service->probeHigh($state, 28.0, 30.0, null));
        $this->assertFalse($state['probe']);

        // Ré-armé : une nouvelle canicule relève l'alerte.
        $this->assertSame(1, $service->probeHigh($state, 35.0, 30.0, null));
    }

    /**
     * Un seuil de ré-armement EXPLICITE reste prioritaire (chemin emprunté par
     * MspDerivedAlertService::checkHeat, qui passe `seuil - 2 °C`).
     */
    public function testHighDirectionHonoursExplicitClearThreshold(): void
    {
        $service = $this->service();
        $state = [];

        $service->probeHigh($state, 35.0, 30.0, 28.0);
        $this->assertTrue($state['probe']);

        $this->assertSame(0, $service->probeHigh($state, 28.5, 30.0, 28.0), 'au-dessus du seuil explicite');
        $this->assertSame(-1, $service->probeHigh($state, 27.5, 30.0, 28.0), 'sous le seuil explicite');
    }

    /**
     * Le sens BAS (batterie, sol sec, gel) est inchangé : levée sous le seuil,
     * ré-armement au-dessus de seuil + 5 %.
     */
    public function testLowDirectionKeepsDefaultHysteresisAbove(): void
    {
        $service = $this->service();
        $state = [];

        $this->assertSame(1, $service->probeLow($state, 90.0, 100.0, null));
        // 102 : au-dessus du seuil mais sous le ré-armement (105) -> toujours latché.
        $this->assertSame(0, $service->probeLow($state, 102.0, 100.0, null));
        $this->assertSame(-1, $service->probeLow($state, 106.0, 100.0, null));
    }
}

/**
 * Sous-classe concrète minimale exposant le helper protégé.
 *
 * @internal
 */
final class LatchDirectionProbe extends AbstractVitalsDerivedAlertService
{
    protected function family(): string
    {
        return 'PROBE';
    }

    protected function throttleKeyPrefix(): string
    {
        return 'probe';
    }

    /**
     * @param array<string, mixed> $state
     * @return int 1 = levée, -1 = ré-armement, 0 = rien
     */
    public function probeHigh(array &$state, float $value, float $threshold, ?float $clear): int
    {
        return $this->probe($state, $value, $threshold, $clear, self::DIRECTION_HIGH);
    }

    /**
     * @param array<string, mixed> $state
     * @return int 1 = levée, -1 = ré-armement, 0 = rien
     */
    public function probeLow(array &$state, float $value, float $threshold, ?float $clear): int
    {
        return $this->probe($state, $value, $threshold, $clear, self::DIRECTION_LOW);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function probe(array &$state, float $value, float $threshold, ?float $clear, string $direction): int
    {
        $outcome = 0;
        $this->evaluateLatchedLowValue(
            $state,
            'probe',
            $value,
            $threshold,
            $clear,
            $direction,
            function () use (&$outcome): void {
                $outcome = 1;
            },
            function () use (&$outcome): void {
                $outcome = -1;
            },
        );

        return $outcome;
    }
}
