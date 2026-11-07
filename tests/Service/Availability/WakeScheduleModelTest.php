<?php

declare(strict_types=1);

namespace Tests\Service\Availability;

use App\Service\Availability\WakeScheduleModel;
use PHPUnit\Framework\TestCase;

/**
 * Modèle de réveil attendu : regroupement en rafales, période observée, réveils manqués.
 * Jeu de données calqué sur le 06/11/2026 (FreqWakeUp = 2000 s, rafales de ~10 POST / 30 s).
 */
final class WakeScheduleModelTest extends TestCase
{
    private const T0 = 1762400000; // repère arbitraire

    /** @return list<int> Rafale de $count contacts espacés de 30 s à partir de $start */
    private static function burst(int $start, int $count = 10): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $start + 30 * $i;
        }

        return $out;
    }

    /** @return list<int> $n rafales séparées de $period secondes, la dernière commençant à $lastStart */
    private static function schedule(int $lastStart, int $period, int $n = 5): array
    {
        $contacts = [];
        for ($k = $n - 1; $k >= 0; $k--) {
            $contacts = array_merge($contacts, self::burst($lastStart - $k * $period));
        }
        shuffle($contacts); // l'ordre d'entrée est indifférent

        return $contacts;
    }

    public function testGroupsContactsIntoBurstsAndMeasuresPeriod(): void
    {
        $model = new WakeScheduleModel();
        $contacts = self::schedule(self::T0, 2000, 5);

        $bursts = $model->bursts($contacts);
        self::assertCount(5, $bursts);
        self::assertSame(self::T0, $bursts[4]['start']);
        self::assertSame(10, $bursts[4]['count']);
        self::assertSame(2000, $model->observedPeriod($bursts));
    }

    public function testDeviceJustAfterBurstIsOnline(): void
    {
        $a = (new WakeScheduleModel())->assess(self::schedule(self::T0, 2000), 2000, self::T0 + 600);

        self::assertSame(0, $a->missedWakes);
        self::assertFalse($a->isOffline());
        self::assertSame(self::T0 + 2000, $a->nextExpectedAt);
        self::assertSame(self::T0 + 270, $a->lastContact);
    }

    public function testOneMissedWakeIsToleratedTwoAreNot(): void
    {
        $model = new WakeScheduleModel();
        $contacts = self::schedule(self::T0, 2000);
        // Réveil attendu à T0+2000 (+ marge 180 + 10 %) : un seul manqué à T0+2500.
        $one = $model->assess($contacts, 2000, self::T0 + 2500);
        self::assertSame(1, $one->missedWakes);
        self::assertFalse($one->isOffline());
        self::assertSame(self::T0 + 2000, $one->firstMissedAt);

        // Deux réveils manqués à T0+4500 → hors ligne, avec l'heure du premier réveil manqué.
        $two = $model->assess($contacts, 2000, self::T0 + 4500);
        self::assertSame(2, $two->missedWakes);
        self::assertTrue($two->isOffline());
        self::assertSame(self::T0 + 2000, $two->firstMissedAt);
        self::assertSame(self::T0 + 6000, $two->nextExpectedAt);
        self::assertStringContainsString('2 réveil(s) manqué(s)', $two->describe());
    }

    public function testNightPeriodFromConfigurationPreventsFalseAlarm(): void
    {
        // Rafales de jour toutes les 2000 s ; à la tombée de la nuit FreqWakeUp × 3 = 6000 s.
        $contacts = self::schedule(self::T0, 2000);
        $a = (new WakeScheduleModel())->assess($contacts, 6000, self::T0 + 4500);

        self::assertSame(6000, $a->period);
        self::assertSame(0, $a->missedWakes, 'la consigne nuit allonge la période retenue');
        self::assertFalse($a->isOffline());
    }

    public function testObservedPeriodLongerThanConfiguredIsKept(): void
    {
        // Sortie de nuit : les rafales observées sont espacées de 6000 s, la consigne jour dit 2000 s.
        $contacts = self::schedule(self::T0, 6000);
        $a = (new WakeScheduleModel())->assess($contacts, 2000, self::T0 + 4500);

        self::assertSame(6000, $a->period, 'grâce matinale : on garde la période observée');
        self::assertFalse($a->isOffline());
    }

    public function testAbsurdObservedGapIsIgnored(): void
    {
        // Deux rafales séparées par une panne de 10 h ne définissent pas une cadence.
        $contacts = array_merge(self::burst(self::T0 - 36000), self::burst(self::T0));
        $a = (new WakeScheduleModel())->assess($contacts, 2000, self::T0 + 4500);

        self::assertSame(2000, $a->period);
        self::assertTrue($a->isOffline());
    }

    public function testNoContactsGivesNeutralAssessment(): void
    {
        $a = (new WakeScheduleModel())->assess([], 2000, self::T0);

        self::assertNull($a->lastContact);
        self::assertFalse($a->isOffline());
        self::assertSame(0, $a->missedWakes);
        self::assertSame('Aucun contact connu.', $a->describe());
    }

    public function testIncident20261106Reproduction(): void
    {
        // Dernière rafale 16:37:52 → 16:40:27 (Paris), FreqWakeUp 2000 s mais rafales observées toutes
        // les ~2 374 s (veille + ~6 min d'éveil) : période retenue 2 374 s. À 18:35 (CRON horaire) :
        // réveils attendus vers 17:17 et 17:57 manqués, celui de 18:36 pas encore dû → 2 manqués → hors ligne.
        $t = static fn (string $hm): int => (int) strtotime('2026-11-06 ' . $hm . ' Europe/Paris');
        $contacts = [];
        foreach (['14:40:19', '15:19:53', '15:59:30'] as $start) {
            $contacts = array_merge($contacts, self::burst($t($start), 8));
        }
        $contacts = array_merge($contacts, self::burst($t('16:37:52'), 4)); // 16:37:52 → 16:39:22
        $contacts[] = $t('16:40:27'); // heartbeat = dernier contact

        $a = (new WakeScheduleModel())->assess($contacts, 2000, $t('18:35:02'));
        self::assertSame(2374, $a->period, 'cadence observée plus longue que FreqWakeUp : retenue');
        self::assertTrue($a->isOffline());
        self::assertSame(2, $a->missedWakes);
        self::assertSame($t('16:37:52') + 2374, $a->firstMissedAt);
        self::assertSame($t('16:40:27'), $a->lastContact);

        // À 19:40 la consigne nuit (6000 s) ne rend PAS l'appareil « rétabli » : 1 réveil nuit manqué
        // seulement, mais surtout aucun contact nouveau — c'est à l'AvailabilityNotifier de l'exiger.
        $night = (new WakeScheduleModel())->assess($contacts, 6000, $t('19:40:02'));
        self::assertSame($t('16:40:27'), $night->lastContact);
        self::assertSame(1, $night->missedWakes);
    }

    public function testToArrayIsJsonFriendly(): void
    {
        $a = (new WakeScheduleModel())->assess(self::schedule(self::T0, 2000), 2000, self::T0 + 100);
        $arr = $a->toArray();

        self::assertSame(5, $arr['bursts_observed']);
        self::assertSame(2000, $arr['period_seconds']);
        self::assertFalse($arr['offline']);
        self::assertIsString($arr['summary']);
        self::assertNotFalse(json_encode($arr));
    }
}
