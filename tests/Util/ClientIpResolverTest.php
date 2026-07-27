<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\ClientIpResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * `X-Forwarded-For` est fourni par le client : ne le croire que derrière un proxy
 * déclaré dans `TRUSTED_PROXIES`. Sans ce garde-fou, un client faisant varier
 * l'en-tête obtient un compteur de rate-limit neuf à chaque requête.
 *
 * Cette logique vient de `RateLimitMiddleware::clientIp()` (durcissement « S1 » du
 * limiteur de login), extraite en 6.34.0 pour les limiteurs firmware qui en avaient
 * une copie naïve. `RateLimitMiddlewareTest` couvre le middleware de bout en bout ;
 * ici on verrouille le résolveur lui-même, IPv6 et CIDR compris.
 */
final class ClientIpResolverTest extends TestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        $raw = $_ENV['TRUSTED_PROXIES'] ?? null;
        $this->previous = is_string($raw) ? $raw : null;
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            unset($_ENV['TRUSTED_PROXIES']);
        } else {
            $_ENV['TRUSTED_PROXIES'] = $this->previous;
        }
    }

    private function request(string $remoteAddr, ?string $forwardedFor = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/post-data', ['REMOTE_ADDR' => $remoteAddr]);

        return $forwardedFor === null ? $request : $request->withHeader('X-Forwarded-For', $forwardedFor);
    }

    public function testForwardedForIgnoredWithoutTrustedProxies(): void
    {
        unset($_ENV['TRUSTED_PROXIES']);

        $this->assertSame(
            '203.0.113.9',
            ClientIpResolver::resolve($this->request('203.0.113.9', '1.2.3.4'))
        );
    }

    public function testForwardedForIgnoredWhenRemoteAddrIsNotTrusted(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1';

        $this->assertSame(
            '203.0.113.9',
            ClientIpResolver::resolve($this->request('203.0.113.9', '1.2.3.4'))
        );
    }

    public function testForwardedForHonouredBehindExactTrustedProxy(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1, 192.168.1.5';

        $this->assertSame(
            '198.51.100.7',
            ClientIpResolver::resolve($this->request('10.0.0.1', '198.51.100.7, 10.0.0.1'))
        );
    }

    public function testForwardedForHonouredBehindCidrTrustedProxy(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';

        $this->assertSame(
            '198.51.100.7',
            ClientIpResolver::resolve($this->request('10.42.7.3', '198.51.100.7'))
        );
    }

    public function testCidrDoesNotMatchOutsideBlock(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';

        $this->assertSame(
            '11.0.0.1',
            ClientIpResolver::resolve($this->request('11.0.0.1', '198.51.100.7'))
        );
    }

    public function testTrustedProxyWithoutForwardedForFallsBackToRemoteAddr(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.1';

        $this->assertSame('10.0.0.1', ClientIpResolver::resolve($this->request('10.0.0.1')));
    }

    /**
     * Un masque malformé ne doit JAMAIS valoir « /0 » (= confiance à tout le monde).
     * C'est le comportement qu'avait le code d'origine : `(int) 'abc'` = 0, donc une
     * simple coquille dans `TRUSTED_PROXIES` rouvrait le contournement que cette liste
     * est censée fermer. Corrigé en 6.34.0.
     *
     * @dataProvider malformedCidrs
     */
    public function testMalformedCidrIsNotTrusted(string $entry): void
    {
        $_ENV['TRUSTED_PROXIES'] = $entry;

        $this->assertSame(
            '10.0.0.1',
            ClientIpResolver::resolve($this->request('10.0.0.1', '198.51.100.7')),
            "L'entrée « {$entry} » ne doit accorder aucune confiance"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCidrs(): array
    {
        return [
            'masque non numérique' => ['10.0.0.0/abc'],
            'masque vide' => ['10.0.0.0/'],
            'masque négatif' => ['10.0.0.0/-1'],
            'masque hors plage' => ['10.0.0.0/33'],
            'sous-réseau invalide' => ['pas-une-ip/8'],
        ];
    }

    /**
     * En revanche, un `/0` écrit EXPLICITEMENT reste honoré : c'est un choix assumé
     * de l'exploitant (« tout le réseau est de confiance »), pas une coquille.
     */
    public function testExplicitZeroPrefixIsHonoured(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '0.0.0.0/0';

        $this->assertSame(
            '198.51.100.7',
            ClientIpResolver::resolve($this->request('10.0.0.1', '198.51.100.7'))
        );
    }

    /**
     * IPv6 : la comparaison passe par inet_pton, donc les formes équivalentes d'une
     * même adresse (`::1` / `0:0:0:0:0:0:0:1`) se reconnaissent.
     */
    public function testIpv6ProxyMatchesRegardlessOfNotation(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '0:0:0:0:0:0:0:1';

        $this->assertSame(
            '198.51.100.7',
            ClientIpResolver::resolve($this->request('::1', '198.51.100.7'))
        );
    }

    public function testIpv6CidrIsSupported(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '2001:db8::/32';

        $this->assertSame(
            '198.51.100.7',
            ClientIpResolver::resolve($this->request('2001:db8:1234::5', '198.51.100.7'))
        );
        $this->assertSame(
            '2001:dba::1',
            ClientIpResolver::resolve($this->request('2001:dba::1', '198.51.100.7'))
        );
    }

    /**
     * Un CIDR IPv4 ne doit pas « matcher » une IPv6 (familles distinctes).
     */
    public function testIpv4CidrDoesNotMatchIpv6(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';

        $this->assertSame(
            '2001:db8::1',
            ClientIpResolver::resolve($this->request('2001:db8::1', '198.51.100.7'))
        );
    }

    public function testMissingRemoteAddrYieldsUnknown(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/post-data', []);

        $this->assertSame(ClientIpResolver::UNKNOWN, ClientIpResolver::resolve($request));
    }
}
