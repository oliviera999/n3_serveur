<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\AbstractDataController;
use App\Display\Catalog\Ffp3Catalog;
use App\Display\LayoutResolver;
use PHPUnit\Framework\TestCase;

/**
 * Indications des cartes (6.50.0) : dernière valeur valide quand la mesure courante
 * est vide (ex. niveau aquarium NULL depuis le 31/10) et « capteur figé » quand toutes
 * les mesures de la période sont identiques (ex. aquarium bloqué à 8,1 cm).
 */
final class MonitoringCardHintsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function layout(): array
    {
        $catalog = new Ffp3Catalog();

        return (new LayoutResolver())->resolve($catalog, ['blocks' => []]);
    }

    public function testLastValidValueIsLookedUpOnlyForEmptyCards(): void
    {
        $asked = [];
        $lookup = function (string $column) use (&$asked): ?array {
            $asked[] = $column;

            return $column === 'EauAquarium' ? ['value' => 8.1, 'reading_time' => '2026-10-31 21:07:41'] : null;
        };
        $latest = ['EauAquarium' => null, 'EauReserve' => 41.0, 'TempEau' => '', 'etatUV' => 0];

        $lastValid = AbstractDataController::lastValidValues($this->layout(), $latest, $lookup);

        $this->assertSame(['EauAquarium' => ['value' => 8.1, 'reading_time' => '2026-10-31 21:07:41']], $lastValid);
        $this->assertContains('EauAquarium', $asked);
        $this->assertContains('TempEau', $asked, 'chaîne vide = mesure absente');
        $this->assertNotContains('EauReserve', $asked);
        $this->assertNotContains('etatUV', $asked, '0 est une mesure valide');
        $this->assertSame([], AbstractDataController::lastValidValues($this->layout(), null, $lookup));
    }

    public function testConstantValueOverTheWholePeriodIsFlaggedAsStuck(): void
    {
        $rows = [];
        $t = strtotime('2026-10-30 13:12:00');
        for ($i = 0; $i < 40; $i++) {
            $rows[] = [
                'reading_time' => date('Y-m-d H:i:s', $t + $i * 300),
                'EauAquarium' => 8.1,                  // bloqué pendant 3 h 15
                'TempEau' => 20.0 + ($i % 3) * 0.1,     // varie
                'EauReserve' => 41.0,                   // constant mais non surveillé
            ];
        }

        $stuck = AbstractDataController::stuckSensors($this->layout(), $rows);

        $this->assertSame(['EauAquarium' => 8.1], $stuck);
    }

    public function testShortOrSparsePeriodsAreNotFlagged(): void
    {
        $rows = [];
        $t = strtotime('2026-10-30 13:12:00');
        for ($i = 0; $i < 40; $i++) {
            // 40 mesures identiques mais sur 20 min seulement (< 2 h).
            $rows[] = ['reading_time' => date('Y-m-d H:i:s', $t + $i * 30), 'EauAquarium' => 8.1];
        }
        $this->assertSame([], AbstractDataController::stuckSensors($this->layout(), $rows));

        // Moins de 20 mesures non nulles.
        $few = array_map(static fn (int $i): array => ['reading_time' => date('Y-m-d H:i:s', $t + $i * 1800), 'EauAquarium' => 8.1], range(0, 9));
        $this->assertSame([], AbstractDataController::stuckSensors($this->layout(), $few));
    }

    public function testLastValidLookbackIsBounded(): void
    {
        $this->assertSame('2026-10-03 14:20:35', AbstractDataController::lastValidSince('2026-11-02 14:20:35'));
    }
}
