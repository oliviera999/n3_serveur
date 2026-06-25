<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\StateNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests de StateNormalizer — normalisation des valeurs d'état GPIO.
 *
 * CONTRAT (firmware↔serveur) : les GPIO booléens (actionneurs physiques < 100 et
 * certains flags de config) doivent normaliser les chaînes ('checked'/'true'/'on'/'1'…)
 * en 0/1, tandis que les GPIO numériques/textuels (email, seuils…) sont préservés.
 * Utilisé par OutputRepository / OutputCacheService → impacte directement les dbvars
 * renvoyés au firmware.
 */
class StateNormalizerTest extends TestCase
{
    public function testIsBooleanGpioPhysicalActuators(): void
    {
        $this->assertTrue(StateNormalizer::isBooleanGpio(2));   // chauffage
        $this->assertTrue(StateNormalizer::isBooleanGpio(15));  // UV
        $this->assertTrue(StateNormalizer::isBooleanGpio(16));  // pompe aqua
        $this->assertTrue(StateNormalizer::isBooleanGpio(18));  // pompe tank
    }

    public function testIsBooleanGpioConfigBooleans(): void
    {
        foreach ([101, 110, 115] as $gpio) {
            $this->assertTrue(StateNormalizer::isBooleanGpio($gpio), "GPIO {$gpio} doit être booléen");
        }
    }

    /**
     * GPIO 108/109 (nourrissage petits/gros) ne sont PLUS booléens : contrat
     * « compteur monotone » (serveur 6.0.0 / firmware 15.0). Leur `state` est un
     * entier croissant qui doit être préservé tel quel, jamais réduit à 0/1.
     */
    public function testFeedCounterGpiosAreNotBoolean(): void
    {
        $this->assertFalse(StateNormalizer::isBooleanGpio(108));
        $this->assertFalse(StateNormalizer::isBooleanGpio(109));
        $this->assertSame('42', StateNormalizer::normalize(108, '42'));
        $this->assertSame(7, StateNormalizer::normalize(109, 7));
    }

    /** GPIO 117 (forçage pompe aquarium) est un tri-état, pas un booléen. */
    public function testAquariumForceGpioIsNotBoolean(): void
    {
        $this->assertFalse(StateNormalizer::isBooleanGpio(117));
    }

    /** GPIO 117 : 0=auto, 1=forcer ON, 2=forcer OFF ; toute autre valeur retombe sur 0 (auto). */
    public function testNormalizeAquariumForceTriState(): void
    {
        $this->assertSame(0, StateNormalizer::normalize(117, '0'));
        $this->assertSame(1, StateNormalizer::normalize(117, '1'));
        $this->assertSame(2, StateNormalizer::normalize(117, '2'));
        $this->assertSame(0, StateNormalizer::normalize(117, 3));
        $this->assertSame(0, StateNormalizer::normalize(117, 'on'));
        $this->assertSame(0, StateNormalizer::normalize(117, null));
    }

    public function testIsBooleanGpioRejectsNumericConfig(): void
    {
        foreach ([100, 102, 103, 104, 105, 106, 107, 111, 112, 113, 114, 116] as $gpio) {
            $this->assertFalse(StateNormalizer::isBooleanGpio($gpio), "GPIO {$gpio} ne doit pas être booléen");
        }
    }

    public function testIsBooleanGpioUnknownIsFalse(): void
    {
        $this->assertFalse(StateNormalizer::isBooleanGpio(999));
    }

    public function testNormalizeBooleanTrueStrings(): void
    {
        foreach (['checked', 'true', 'on', '1', 'yes'] as $v) {
            $this->assertSame(1, StateNormalizer::normalize(101, $v), "valeur '{$v}' -> 1");
        }
    }

    public function testNormalizeBooleanFalseStrings(): void
    {
        foreach (['unchecked', 'false', 'off', '0', 'no', 'unknown', ''] as $v) {
            $this->assertSame(0, StateNormalizer::normalize(101, $v), "valeur '{$v}' -> 0");
        }
    }

    public function testNormalizeBooleanCaseInsensitiveAndTrimmed(): void
    {
        $this->assertSame(1, StateNormalizer::normalize(101, 'CHECKED'));
        $this->assertSame(1, StateNormalizer::normalize(101, '  true  '));
        $this->assertSame(0, StateNormalizer::normalize(101, "\n false \t"));
    }

    public function testNormalizeBooleanNullIsFalse(): void
    {
        $this->assertSame(0, StateNormalizer::normalize(115, null));
    }

    public function testNormalizePhysicalActuators(): void
    {
        $this->assertSame(1, StateNormalizer::normalize(2, 'true'));
        $this->assertSame(0, StateNormalizer::normalize(2, 'false'));
        $this->assertSame(1, StateNormalizer::normalize(16, 'on'));
        $this->assertSame(0, StateNormalizer::normalize(16, 'off'));
    }

    public function testNormalizeNonBooleanPreservesValue(): void
    {
        $this->assertSame('user@example.com', StateNormalizer::normalize(100, 'user@example.com'));
        $this->assertSame('18', StateNormalizer::normalize(102, '18'));
        $this->assertSame('22.5', StateNormalizer::normalize(104, '22.5'));
    }

    public function testNormalizeUnknownGpioPreservesValue(): void
    {
        $this->assertSame('value', StateNormalizer::normalize(999, 'value'));
    }
}
