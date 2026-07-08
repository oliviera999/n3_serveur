<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Config\N3ppGpioMap;
use App\Repository\N3ppOutputRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Garde-fou : le mapping GPIO ↔ paramètre N3PP doit provenir d'UNE source unique
 * ({@see N3ppGpioMap}). Ce test gèle les valeurs canoniques et asservit la
 * dérivation effective par {@see N3ppOutputRepository} afin d'interdire toute
 * future divergence ou duplication.
 *
 * Note : les clés spécifiques module (104 = HeureArrosage, 105 = tempsArrosage)
 * diffèrent de MSP ; elles sont volontairement figées ici.
 */
class N3ppGpioMapCoherenceTest extends TestCase
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
        104 => 'HeureArrosage',
        105 => 'tempsArrosage',
        106 => 'WakeUp',
        107 => 'FreqWakeUp',
        108 => 'notifMode',
        109 => 'notifCategories',
        110 => 'resetMode',
        112 => 'veilleInfinie',
    ];

    public function testCanonicalParamGpioMapValuesAreFrozen(): void
    {
        $this->assertSame(
            self::EXPECTED_PARAM_GPIO_MAP,
            N3ppGpioMap::paramGpioMap(),
            'Le mapping canonique GPIO → nom de paramètre N3PP a changé de valeur.'
        );
    }

    public function testParameterGpioMapIsInverseOfParamGpioMap(): void
    {
        $this->assertSame(
            array_flip(self::EXPECTED_PARAM_GPIO_MAP),
            N3ppGpioMap::parameterGpioMap(),
            'parameterGpioMap() doit être l\'inverse strict de paramGpioMap().'
        );
    }

    public function testRepositoryDerivesFromCanonicalSource(): void
    {
        $method = new ReflectionMethod(N3ppOutputRepository::class, 'getParamGpioMap');
        $method->setAccessible(true);

        $repo = (new \ReflectionClass(N3ppOutputRepository::class))->newInstanceWithoutConstructor();

        $this->assertSame(
            N3ppGpioMap::paramGpioMap(),
            $method->invoke($repo),
            'N3ppOutputRepository::getParamGpioMap() doit dériver de N3ppGpioMap (source unique).'
        );
    }

    /**
     * Le mapping N3PP doit rester distinct de MSP sur les clés spécifiques module
     * (104/105), pour interdire un alignement accidentel des deux modules.
     */
    public function testModuleSpecificKeysDifferFromMsp(): void
    {
        $n3pp = N3ppGpioMap::paramGpioMap();

        $this->assertSame('HeureArrosage', $n3pp[104]);
        $this->assertSame('tempsArrosage', $n3pp[105]);
    }

    /**
     * Toute clé du mapping est un GPIO entier et tout nom est une chaîne non vide.
     */
    public function testMapIsWellFormed(): void
    {
        foreach (N3ppGpioMap::paramGpioMap() as $gpio => $name) {
            $this->assertIsInt($gpio, 'Chaque clé du mapping doit être un GPIO entier.');
            $this->assertNotSame('', $name, "Le nom de paramètre du GPIO {$gpio} ne doit pas être vide.");
        }
    }
}
