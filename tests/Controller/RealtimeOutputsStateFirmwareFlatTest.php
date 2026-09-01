<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\AbstractRealtimeApiController;
use App\Service\FirmwareStateCompat;
use App\Service\Realtime\AbstractSensorRealtimeDataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Audit C1/C2 — la réponse `/api/outputs/state` doit exposer les clés PLATES
 * {gpio: state} (que lisent les firmwares n3pp/msp) EN PLUS du format nested, mais
 * uniquement pour une requête firmware authentifiée (X-Api-Key). Le polling UI
 * (sans clé) garde la réponse nested inchangée et ne déclenche pas d'ack.
 */
class RealtimeOutputsStateFirmwareFlatTest extends TestCase
{
    protected function setUp(): void
    {
        // La fusion des clés plates est désormais pilotée par FIRMWARE_FLAT_STATE_MODE,
        // dont le défaut (`off`) restaure le contrat d'avant v6.25.0 pour la flotte non
        // reflashable. Les cas ci-dessous testent le comportement une fois réactivée.
        $_ENV[FirmwareStateCompat::SETTING_KEY] = FirmwareStateCompat::MODE_SAFE;
    }

    protected function tearDown(): void
    {
        unset($_ENV['API_KEY'], $_ENV[FirmwareStateCompat::SETTING_KEY]);
    }

    private function controller(AbstractSensorRealtimeDataProvider $provider): AbstractRealtimeApiController
    {
        return new class ($provider) extends AbstractRealtimeApiController {};
    }

    private function makeProvider(): AbstractSensorRealtimeDataProvider
    {
        return $this->getMockBuilder(AbstractSensorRealtimeDataProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOutputsState', 'acknowledgeFirmwareOneShots'])
            ->getMockForAbstractClass();
    }

    public function testFirmwareRequestMergesFlatKeys(): void
    {
        $_ENV['API_KEY'] = 'secret';
        $provider = $this->makeProvider();
        $provider->method('getOutputsState')->willReturn([['id' => 1, 'gpio' => 110, 'name' => 'reset', 'state' => '1', 'board' => 3]]);
        $provider->expects($this->once())->method('acknowledgeFirmwareOneShots')
            ->willReturn(['110' => '1', '106' => '0', '107' => '3600']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://x/api/outputs/state')
            ->withHeader('X-Api-Key', 'secret');
        $response = $this->controller($provider)->getOutputsState($request, new Response());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('outputs', $body);   // nested conservé (UI)
        $this->assertArrayHasKey('timestamp', $body);
        $this->assertSame('1', $body['110']);          // clés plates ajoutées (firmware)
        $this->assertSame('0', $body['106']);
        $this->assertSame('3600', $body['107']);
    }

    public function testUiRequestWithoutKeyStaysNested(): void
    {
        $_ENV['API_KEY'] = 'secret';
        $provider = $this->makeProvider();
        $provider->method('getOutputsState')->willReturn([]);
        $provider->expects($this->never())->method('acknowledgeFirmwareOneShots');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://x/api/outputs/state');
        $response = $this->controller($provider)->getOutputsState($request, new Response());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('outputs', $body);
        $this->assertArrayNotHasKey('110', $body);     // pas de clés plates sans auth
    }

    public function testFlatStateOffKeepsNestedOnlyAndDoesNotAcknowledge(): void
    {
        // Rollback flotte déployée (défaut) : aucune clé plate renvoyée — un module non
        // reflashable conserve sa config locale. L'ack one-shot n'est PLUS effectué
        // ici, sinon reset (110) / arrosage manuel (13) seraient consommés sans
        // jamais être livrés au firmware (réponse nested-only).
        $_ENV[FirmwareStateCompat::SETTING_KEY] = FirmwareStateCompat::MODE_OFF;
        $_ENV['API_KEY'] = 'secret';
        $provider = $this->makeProvider();
        $provider->method('getOutputsState')->willReturn([]);
        $provider->expects($this->never())->method('acknowledgeFirmwareOneShots');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://x/api/outputs/state')
            ->withHeader('X-Api-Key', 'secret');
        $response = $this->controller($provider)->getOutputsState($request, new Response());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('outputs', $body);
        $this->assertArrayNotHasKey('110', $body);
        $this->assertArrayNotHasKey('107', $body);
    }

    public function testFlatStateSafeOmitsOutOfRangeConfigValue(): void
    {
        // Un FreqWakeUp invalide en BDD n'est pas propagé : le firmware garde sa valeur
        // locale (log `[SERVER][GET][WARN] Cle 107 absente`) au lieu d'adopter 0.
        $_ENV['API_KEY'] = 'secret';
        $provider = $this->makeProvider();
        $provider->method('getOutputsState')->willReturn([]);
        $provider->method('acknowledgeFirmwareOneShots')
            ->willReturn(['107' => '0', '106' => '1']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://x/api/outputs/state')
            ->withHeader('X-Api-Key', 'secret');
        $response = $this->controller($provider)->getOutputsState($request, new Response());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayNotHasKey('107', $body);
        $this->assertSame('1', $body['106']);
    }

    public function testWrongKeyStaysNested(): void
    {
        $_ENV['API_KEY'] = 'secret';
        $provider = $this->makeProvider();
        $provider->method('getOutputsState')->willReturn([]);
        $provider->expects($this->never())->method('acknowledgeFirmwareOneShots');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://x/api/outputs/state')
            ->withHeader('X-Api-Key', 'wrong');
        $response = $this->controller($provider)->getOutputsState($request, new Response());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayNotHasKey('110', $body);
    }
}
