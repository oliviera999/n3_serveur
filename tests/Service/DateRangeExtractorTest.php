<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Security\CsrfService;
use App\Service\DateRangeExtractor;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Couvre la navigation par fenêtre temporelle côté serveur
 * (DateRangeExtractor::resolveDataWindow) et la rétro-compatibilité stricte.
 */
class DateRangeExtractorTest extends TestCase
{
    private DateRangeExtractor $extractor;

    protected function setUp(): void
    {
        $csrf = $this->createMock(CsrfService::class);
        $this->extractor = new DateRangeExtractor($csrf);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function getRequest(array $query, string $method = 'GET'): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getParsedBody')->willReturn(null);

        return $request;
    }

    // --- Mode 3 : aucun paramètre => comportement historique inchangé ---------

    public function testNoParamsReturnsDefaultsUnchanged(): void
    {
        $request = $this->getRequest([]);
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-01 00:00:00',
            '2025-01-01 06:00:00'
        );

        $this->assertSame('2025-01-01 00:00:00', $result['start']);
        $this->assertSame('2025-01-01 06:00:00', $result['end']);
        $this->assertSame('default', $result['mode']);
        $this->assertSame(0, $result['offset']);
        $this->assertNull($result['window']);
    }

    public function testUnknownWindowTokenDegradesToDefault(): void
    {
        $request = $this->getRequest(['window' => '99y']);
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-01 00:00:00',
            '2025-01-01 06:00:00'
        );

        $this->assertSame('default', $result['mode']);
        $this->assertSame('2025-01-01 00:00:00', $result['start']);
        $this->assertSame('2025-01-01 06:00:00', $result['end']);
    }

    // --- Mode 1 : fenêtre explicite from/to -----------------------------------

    public function testExplicitFromTo(): void
    {
        $request = $this->getRequest([
            'from' => '2024-12-01 00:00:00',
            'to' => '2024-12-08 00:00:00',
        ]);
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-01 00:00:00',
            '2025-01-01 06:00:00'
        );

        $this->assertSame('explicit', $result['mode']);
        $this->assertSame('2024-12-01 00:00:00', $result['start']);
        $this->assertSame('2024-12-08 00:00:00', $result['end']);
        $this->assertTrue($result['has_prev']);
    }

    public function testExplicitStartEndAliasesSupported(): void
    {
        $request = $this->getRequest([
            'start' => '2024-12-01 00:00:00',
            'end' => '2024-12-02 00:00:00',
        ]);
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-01 00:00:00',
            '2025-01-01 06:00:00'
        );

        $this->assertSame('explicit', $result['mode']);
        $this->assertSame('2024-12-01 00:00:00', $result['start']);
        $this->assertSame('2024-12-02 00:00:00', $result['end']);
    }

    public function testExplicitInvalidDateDegradesToDefault(): void
    {
        $request = $this->getRequest(['from' => 'not-a-date', 'to' => 'nope']);
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-01 00:00:00',
            '2025-01-01 06:00:00'
        );

        $this->assertSame('default', $result['mode']);
        $this->assertSame('2025-01-01 00:00:00', $result['start']);
    }

    // --- Mode 2 : fenêtre glissante window + offset ---------------------------

    public function testSlidingWindowOffsetZeroEndsAtAnchor(): void
    {
        $request = $this->getRequest(['window' => '7d', 'offset' => 0]);
        $anchorEnd = '2025-01-08 00:00:00';
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-07 18:00:00',
            $anchorEnd
        );

        $this->assertSame('window', $result['mode']);
        $this->assertSame('7d', $result['window']);
        $this->assertSame(0, $result['offset']);
        // offset 0 : la fenêtre se termine à l'ancre et dure 7 jours.
        $this->assertSame($anchorEnd, $result['end']);
        $this->assertSame('2025-01-01 00:00:00', $result['start']);
        // À l'offset 0, pas de « suivant » (déjà la plus récente), « précédent » dispo.
        $this->assertFalse($result['has_next']);
        $this->assertTrue($result['has_prev']);
        $this->assertSame(1, $result['prev_offset']);
        $this->assertNull($result['next_offset']);
    }

    public function testSlidingWindowOffsetOneStepsBackOneFullWindow(): void
    {
        $request = $this->getRequest(['window' => '7d', 'offset' => 1]);
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-07 18:00:00',
            '2025-01-08 00:00:00'
        );

        // offset 1 : recule d'une largeur complète (7 jours).
        $this->assertSame('2025-01-01 00:00:00', $result['end']);
        $this->assertSame('2024-12-25 00:00:00', $result['start']);
        $this->assertTrue($result['has_next']);
        $this->assertSame(0, $result['next_offset']);
        $this->assertSame(2, $result['prev_offset']);
    }

    public function testNegativeOffsetClampedToZero(): void
    {
        $request = $this->getRequest(['window' => '1d', 'offset' => -5]);
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-07 00:00:00',
            '2025-01-08 00:00:00'
        );

        $this->assertSame(0, $result['offset']);
        $this->assertFalse($result['has_next']);
    }

    public function testNonNumericOffsetTreatedAsZero(): void
    {
        $request = $this->getRequest(['window' => '1d', 'offset' => 'abc']);
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-07 00:00:00',
            '2025-01-08 00:00:00'
        );

        $this->assertSame(0, $result['offset']);
        $this->assertSame('2025-01-08 00:00:00', $result['end']);
        $this->assertSame('2025-01-07 00:00:00', $result['start']);
    }

    public function testHugeOffsetClampedToMax(): void
    {
        $request = $this->getRequest(['window' => '7d', 'offset' => 999999]);
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-07 18:00:00',
            '2025-01-08 00:00:00'
        );

        // Plafonné : has_prev devient false au plafond.
        $this->assertSame(520, $result['offset']);
        $this->assertFalse($result['has_prev']);
        $this->assertNull($result['prev_offset']);
    }

    public function testAllWindowTokensProduceWindowMode(): void
    {
        foreach (['1h', '3h', '6h', '12h', '1d', '7d', '30d'] as $token) {
            $request = $this->getRequest(['window' => $token]);
            $result = $this->extractor->resolveDataWindow(
                $request,
                '2025-01-07 00:00:00',
                '2025-01-08 00:00:00'
            );
            $this->assertSame('window', $result['mode'], "token {$token}");
            $this->assertSame($token, $result['window']);
            // start strictement antérieur à end.
            $this->assertLessThan(strtotime($result['end']), strtotime($result['start']));
        }
    }

    public function testPostMethodWithoutQueryStillReturnsDefault(): void
    {
        // resolveDataWindow ne lit que la query : un POST sans param GET reste neutre.
        $request = $this->getRequest([], 'POST');
        $result = $this->extractor->resolveDataWindow(
            $request,
            '2025-01-01 00:00:00',
            '2025-01-01 06:00:00'
        );

        $this->assertSame('default', $result['mode']);
        $this->assertSame('2025-01-01 00:00:00', $result['start']);
    }
}
