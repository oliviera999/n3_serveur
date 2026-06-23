<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\OutputRepository;
use PHPUnit\Framework\TestCase;

/**
 * Cohérence mapping paramètres page contrôle ↔ GPIO (contrat firmware GET).
 */
class OutputRepositoryTest extends TestCase
{
    /** Clés utilisées dans control.twig (data-parameter / params.*) */
    private const CONTROL_TWIG_PARAMETERS = [
        'mail',
        'aqThr',
        'taThr',
        'chauff',
        'bouffeMatin',
        'bouffeMidi',
        'bouffeSoir',
        'tempsGros',
        'tempsPetits',
        'tempsRemplissageSec',
        'limFlood',
        'FreqWakeUp',
        'angleReposGros',
        'angleDistribGros',
        'angleInterGros',
        'angleReposPetits',
        'angleDistribPetits',
        'angleInterPetits',
    ];

    /** GPIO actionneurs affichés dans control.twig */
    private const CONTROL_TWIG_ACTUATOR_GPIOS = [2, 15, 16, 18, 101, 108, 109, 110, 115, 117];

    /** GPIO listés dans OutputController::getOutputsState (firmware poll) */
    private const FIRMWARE_STATE_GPIOS = [
        2, 15, 16, 18,
        100, 101, 102, 103, 104, 105, 106, 107,
        108, 109, 110,
        111, 112, 113, 114, 115, 116,
        118, 119, 120, 121, 122, 123,
        117,
    ];

    public function testParameterGpioMapContainsAllControlTwigKeys(): void
    {
        $map = OutputRepository::getParameterGpioMap();

        foreach (self::CONTROL_TWIG_PARAMETERS as $key) {
            $this->assertArrayHasKey(
                $key,
                $map,
                "Clé paramètre manquante dans PARAMETER_GPIO_MAP: {$key}"
            );
            $this->assertGreaterThan(0, $map[$key]);
        }
    }

    public function testFirmwareStateGpioListIncludesControlActuatorsAndParameters(): void
    {
        $map = OutputRepository::getParameterGpioMap();

        foreach (self::CONTROL_TWIG_ACTUATOR_GPIOS as $gpio) {
            $this->assertContains(
                $gpio,
                self::FIRMWARE_STATE_GPIOS,
                "GPIO actionneur {$gpio} absent de la liste getOutputsState"
            );
        }

        foreach ($map as $gpio) {
            $this->assertContains(
                $gpio,
                self::FIRMWARE_STATE_GPIOS,
                "GPIO paramètre {$gpio} absent de la liste getOutputsState"
            );
        }
    }

    public function testGpio117IsServerOnlyExtension(): void
    {
        $map = OutputRepository::getParameterGpioMap();
        $this->assertArrayNotHasKey('aquariumPumpForce', $map);
        $this->assertContains(117, self::FIRMWARE_STATE_GPIOS);
        $this->assertContains(117, self::CONTROL_TWIG_ACTUATOR_GPIOS);
    }
}
