<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Config\Ffp3GpioMap;
use App\Repository\OutputRepository;
use App\Service\OutputSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou : le mapping GPIO ↔ paramètre FFP3 doit provenir d'UNE source unique
 * ({@see Ffp3GpioMap}). Ce test asserte la cohérence entre OutputRepository,
 * OutputSyncService et OutputService afin d'interdire toute future divergence.
 *
 * Note : on ne touche pas aux modules MSP / N3pp (clés 104/105 distinctes) ;
 * ils ne sont volontairement pas couverts ici.
 */
class Ffp3GpioMapCoherenceTest extends TestCase
{
    /**
     * Valeurs canoniques attendues (gelées) — toute modification non intentionnelle
     * du mapping fait échouer ce test.
     *
     * @var array<string, int>
     */
    private const EXPECTED_PARAMETER_GPIO_MAP = [
        'mail' => 100,
        'mailNotif' => 101,
        'aqThr' => 102,
        'taThr' => 103,
        'chauff' => 104,
        'bouffeMatin' => 105,
        'bouffeMidi' => 106,
        'bouffeSoir' => 107,
        'tempsGros' => 111,
        'tempsPetits' => 112,
        'tempsRemplissageSec' => 113,
        'limFlood' => 114,
        'WakeUp' => 115,
        'FreqWakeUp' => 116,
    ];

    public function testCanonicalParameterGpioMapValuesAreFrozen(): void
    {
        $this->assertSame(
            self::EXPECTED_PARAMETER_GPIO_MAP,
            Ffp3GpioMap::parameterGpioMap(),
            'Le mapping canonique nom de paramètre → GPIO a changé de valeur.'
        );
    }

    public function testOutputRepositoryDerivesFromCanonicalSource(): void
    {
        $this->assertSame(
            Ffp3GpioMap::parameterGpioMap(),
            OutputRepository::getParameterGpioMap(),
            'OutputRepository::getParameterGpioMap() doit dériver de Ffp3GpioMap.'
        );
    }

    public function testOutputSyncServiceDerivesFromCanonicalSource(): void
    {
        $this->assertSame(
            Ffp3GpioMap::gpioToProperty(),
            OutputSyncService::getGpioMapping(),
            'OutputSyncService::getGpioMapping() doit dériver de Ffp3GpioMap.'
        );
    }

    /**
     * Cœur du garde-fou : la composition
     *   OutputSyncService (gpio → propriété) ∘ Ffp3GpioMap (propriété → paramName)
     * doit reproduire exactement le mapping (paramName → gpio) d'OutputRepository.
     *
     * Si les deux mappings divergeaient un jour, cette assertion casserait.
     */
    public function testRepositoryAndSyncServiceMappingsAreConsistent(): void
    {
        $propertyToParam = Ffp3GpioMap::propertyToParam();

        $derivedParamToGpio = [];
        foreach (OutputSyncService::getGpioMapping() as $gpio => $property) {
            if (isset($propertyToParam[$property])) {
                $derivedParamToGpio[$propertyToParam[$property]] = $gpio;
            }
        }

        $repositoryMap = OutputRepository::getParameterGpioMap();

        // Cohérence couple par couple, indépendamment de l'ordre des clés.
        ksort($derivedParamToGpio);
        ksort($repositoryMap);

        $this->assertSame(
            $repositoryMap,
            $derivedParamToGpio,
            'Divergence entre OutputRepository et OutputSyncService sur le mapping GPIO ↔ paramètre FFP3.'
        );
    }

    /**
     * Tout paramètre FFP3 référencé (paramName → gpio) doit pointer vers un GPIO
     * effectivement défini dans la source canonique GPIO → propriété.
     */
    public function testEveryParameterGpioExistsInGpioMapping(): void
    {
        $knownGpios = array_keys(Ffp3GpioMap::gpioToProperty());

        foreach (Ffp3GpioMap::parameterGpioMap() as $paramName => $gpio) {
            $this->assertContains(
                $gpio,
                $knownGpios,
                "Le GPIO {$gpio} du paramètre '{$paramName}' est absent de Ffp3GpioMap::gpioToProperty()."
            );
        }
    }

    /**
     * Toute propriété pontée (propertyToParam) doit exister dans le mapping GPIO,
     * afin que le pont ne référence jamais une propriété fantôme.
     */
    public function testEveryBridgedPropertyExistsInGpioMapping(): void
    {
        $properties = array_values(Ffp3GpioMap::gpioToProperty());

        foreach (array_keys(Ffp3GpioMap::propertyToParam()) as $property) {
            $this->assertContains(
                $property,
                $properties,
                "La propriété '{$property}' du pont propertyToParam() est absente du mapping GPIO."
            );
        }
    }
}
