<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Middleware\RawPostBodyMiddleware;
use App\Util\SignedBodyResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Resolution du corps signe + tracabilite de sa provenance (audit 2026-07, S1).
 *
 * L'enjeu n'est pas la valeur retournee — elle etait deja correcte — mais la
 * SOURCE : c'est elle qui dira, depuis les logs de production, si `php://input`
 * est reellement vide sur l'hebergement (hypothese du correctif 5.1.12, jamais
 * verifiee) ou si la reconstitution canonique est devenue inutile.
 */
final class SignedBodyResolverTest extends TestCase
{
    private function request(?string $attribute, string $stream): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/post-data')
            ->withBody((new StreamFactory())->createStream($stream));

        if ($attribute !== null) {
            $request = $request->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $attribute);
        }

        return $request;
    }

    public function testAttributeWins(): void
    {
        $resolved = SignedBodyResolver::resolve($this->request('api_key=x&sensor=ffp3', 'ignore'));

        $this->assertSame('api_key=x&sensor=ffp3', $resolved['body']);
        $this->assertSame(SignedBodyResolver::SOURCE_MIDDLEWARE, $resolved['source']);
    }

    public function testFallsBackToStreamWhenAttributeIsAbsent(): void
    {
        $resolved = SignedBodyResolver::resolve($this->request(null, 'api_key=x'));

        $this->assertSame('api_key=x', $resolved['body']);
        $this->assertSame(SignedBodyResolver::SOURCE_STREAM, $resolved['source']);
    }

    /**
     * Regression : un attribut PRESENT mais VIDE partait directement en
     * reconstitution canonique cote FFP3, sans relire le flux — alors que le
     * flux, lui, pouvait contenir le corps reellement signe.
     */
    public function testFallsBackToStreamWhenAttributeIsPresentButEmpty(): void
    {
        $resolved = SignedBodyResolver::resolve($this->request('', 'api_key=x'));

        $this->assertSame('api_key=x', $resolved['body']);
        $this->assertSame(SignedBodyResolver::SOURCE_STREAM, $resolved['source']);
    }

    /**
     * Le cas suppose par le correctif 5.1.12 : ni attribut, ni flux.
     */
    public function testReportsEmptyWhenNothingIsAvailable(): void
    {
        $resolved = SignedBodyResolver::resolve($this->request('', ''));

        $this->assertSame('', $resolved['body']);
        $this->assertSame(SignedBodyResolver::SOURCE_EMPTY, $resolved['source']);
    }

    public function testSourcesAreDistinctAndStable(): void
    {
        // Les valeurs partent en base (`hmacAudit`) et dans les logs : les figer
        // evite de casser une requete d'analyse ecrite par l'exploitant.
        $this->assertSame('raw_middleware', SignedBodyResolver::SOURCE_MIDDLEWARE);
        $this->assertSame('raw_stream', SignedBodyResolver::SOURCE_STREAM);
        $this->assertSame('empty', SignedBodyResolver::SOURCE_EMPTY);
    }
}
