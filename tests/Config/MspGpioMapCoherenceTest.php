<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Config\MspGpioMap;
use App\Repository\MspOutputRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Garde-fou : le mapping GPIO ↔ paramètre MSP1 doit provenir d'UNE source unique
 * ({@see MspGpioMap}). Ce test gèle les valeurs canoniques et asservit la
 * dérivation effective par {@see MspOutputRepository} afin d'interdire toute
 * future divergence ou duplication.
 *
 * Note : les clés spécifiques module (104 = ServoHB, 105 = ServoGD) diffèrent de
 * N3PP ; elles sont volontairement figées ici.
 */
class MspGpioMapCoherenceTest extends TestCase
{
    /**
     * Valeurs canoniques attendues (gelées) — toute modification non intentionnelle
     * du mapping fait échouer ce test.
     *
     * @var array<int, string>
     */
    private const EXPECTED_PARAM_GPIO_MAP = [
        100 => 'mail',
        101 => 'mailNotif',
        102 => 'SeuilSec',
        103 => 'SeuilPontDiv',
        104 => 'ServoHB',
        105 => 'ServoGD',
        106 => 'WakeUp',
        107 => 'FreqWakeUp',
        108 => 'notifMode',
        109 => 'notifCategories',
        111 => 'ServoModeAuto',
    ];

    public function testCanonicalParamGpioMapValuesAreFrozen(): void
    {
        $this->assertSame(
            self::EXPECTED_PARAM_GPIO_MAP,
            MspGpioMap::paramGpioMap(),
            'Le mapping canonique GPIO → nom de paramètre MSP a changé de valeur.'
        );
    }

    public function testParameterGpioMapIsInverseOfParamGpioMap(): void
    {
        $this->assertSame(
            array_flip(self::EXPECTED_PARAM_GPIO_MAP),
            MspGpioMap::parameterGpioMap(),
            'parameterGpioMap() doit être l\'inverse strict de paramGpioMap().'
        );
    }

    public function testRepositoryDerivesFromCanonicalSource(): void
    {
        $method = new ReflectionMethod(MspOutputRepository::class, 'getParamGpioMap');
        $method->setAccessible(true);

        $repo = (new \ReflectionClass(MspOutputRepository::class))->newInstanceWithoutConstructor();

        $this->assertSame(
            MspGpioMap::paramGpioMap(),
            $method->invoke($repo),
            'MspOutputRepository::getParamGpioMap() doit dériver de MspGpioMap (source unique).'
        );
    }

    /**
     * Toute clé du mapping est un GPIO entier et tout nom est une chaîne non vide.
     */
    public function testMapIsWellFormed(): void
    {
        foreach (MspGpioMap::paramGpioMap() as $gpio => $name) {
            $this->assertIsInt($gpio, 'Chaque clé du mapping doit être un GPIO entier.');
            $this->assertNotSame('', $name, "Le nom de paramètre du GPIO {$gpio} ne doit pas être vide.");
        }
    }
}
