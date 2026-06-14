<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Config\Database;
use App\Controller\Ffp3\OutputController;
use App\Repository\SensorReadRepository;
use App\Service\LogService;
use App\Service\OutputCacheService;
use App\Service\OutputService;
use App\Service\TemplateRenderer;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Tests d'intégration HTTP IN-PROCESS du contrat des endpoints de sortie FFP3
 * (backlog audit « Tests d'intégration HTTP pour toggle/parameters »).
 *
 * Couvre, sans MySQL réel (services mockés via PHPUnit, mêmes URLs/contrats que la prod) :
 *  - POST /api/outputs/parameters : updateParameters() — contrat JSON de succès (status/updated/
 *    warnings), normalisation {param,value}, payload vide -> 400, validation horaire -> 400,
 *    panne de persistance -> 500.
 *  - GET  /api/outputs/state : getOutputsState() — structure JSON (gpio/state/dataStates),
 *    en-têtes (Content-Type application/json, Content-Length), endpoint public.
 *  - POST /api/outputs/trigger-ota-check : triggerOtaCheck() — contrat JSON {ok,message}.
 *
 * NB contrat : un paramètre INCONNU n'est PAS rejeté en 400. Le contrôleur délègue à
 * OutputService::updateMultipleParameters() qui ne persiste que les paramètres figurant dans
 * la whitelist PARAMETER_GPIO_MAP ; les clés inconnues sont silencieusement ignorées (updated
 * reflète uniquement les paramètres connus). Le 400 provient soit d'un payload vide, soit d'une
 * \InvalidArgumentException de validation (ex. horaire de nourrissage hors de [0..23]). Ces
 * tests verrouillent ce comportement ACTUEL.
 */
class OutputControllerParametersStateTest extends TestCase
{
    /** @var OutputService&\PHPUnit\Framework\MockObject\MockObject */
    private $outputService;
    /** @var OutputCacheService&\PHPUnit\Framework\MockObject\MockObject */
    private $outputCache;

    protected function setUp(): void
    {
        putenv('ENV=prod');
        $_ENV['ENV'] = 'prod';

        $this->outputService = $this->createMock(OutputService::class);
        $this->outputCache = $this->createMock(OutputCacheService::class);
    }

    protected function tearDown(): void
    {
        // Restaure le singleton Database à null pour ne pas polluer d'autres tests.
        $prop = new ReflectionProperty(Database::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeController(): OutputController
    {
        return new OutputController(
            $this->outputService,
            $this->createMock(TemplateRenderer::class),
            $this->createMock(SensorReadRepository::class),
            $this->outputCache,
            $this->createMock(LogService::class),
        );
    }

    /**
     * Injecte une connexion SQLite en mémoire dans le singleton Database afin que
     * getOutputsState() (qui appelle Database::getConnection()) n'établisse aucune
     * connexion MySQL réelle. Le PDO est passé au OutputCacheService mocké, qui l'ignore.
     */
    private function injectInMemoryDatabase(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $prop = new ReflectionProperty(Database::class, 'instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $pdo);
    }

    /** @param array<string,mixed> $params */
    private function postParameters(array $params): Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs/parameters')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($params);

        return $this->makeController()->updateParameters($request, new Response());
    }

    /** @return array<string,mixed> */
    private static function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    // ------------------------------------------------------------------
    // POST /api/outputs/parameters
    // ------------------------------------------------------------------

    /** Mise à jour de paramètres connus -> 200 + contrat JSON {status:ok, updated, warnings}. */
    public function testUpdateParametersSuccessContract(): void
    {
        $this->outputService->expects($this->once())
            ->method('updateMultipleParameters')
            ->with(['aqThr' => '18', 'chauff' => '21'])
            ->willReturn(['updated' => 2, 'warnings' => []]);

        $response = $this->postParameters(['aqThr' => '18', 'chauff' => '21']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = self::decode($response);
        $this->assertSame('ok', $body['status']);
        $this->assertSame(2, $body['updated']);
        $this->assertSame([], $body['warnings']);
    }

    /** Avertissement consultatif (horaires non croissants) remonté tel quel dans le contrat. */
    public function testUpdateParametersSuccessPropagatesWarnings(): void
    {
        $this->outputService->method('updateMultipleParameters')
            ->willReturn(['updated' => 1, 'warnings' => ['Horaires de nourrissage non strictement croissants']]);

        $response = $this->postParameters(['bouffeMidi' => '5']);
        $body = self::decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $body['status']);
        $this->assertSame(1, $body['updated']);
        $this->assertCount(1, $body['warnings']);
    }

    /** Format {param, value} normalisé en {param => value} avant délégation au service. */
    public function testUpdateParametersNormalizesParamValueShape(): void
    {
        $this->outputService->expects($this->once())
            ->method('updateMultipleParameters')
            ->with(['aqThr' => '20'])
            ->willReturn(['updated' => 1, 'warnings' => []]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs/parameters')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody(['param' => 'aqThr', 'value' => '20']);

        $response = $this->makeController()->updateParameters($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', self::decode($response)['status']);
    }

    /** Format {param} sans value -> value null transmise au service. */
    public function testUpdateParametersParamWithoutValueDefaultsToNull(): void
    {
        $this->outputService->expects($this->once())
            ->method('updateMultipleParameters')
            ->with(['mail' => null])
            ->willReturn(['updated' => 1, 'warnings' => []]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs/parameters')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody(['param' => 'mail']);

        $response = $this->makeController()->updateParameters($request, new Response());
        $this->assertSame(200, $response->getStatusCode());
    }

    /** Payload vide -> 400 {status:error, message}. Le service n'est pas appelé. */
    public function testUpdateParametersEmptyPayloadReturns400(): void
    {
        $this->outputService->expects($this->never())->method('updateMultipleParameters');

        $response = $this->postParameters([]);

        $this->assertSame(400, $response->getStatusCode());
        $body = self::decode($response);
        $this->assertSame('error', $body['status']);
        $this->assertSame('No parameters provided', $body['message']);
    }

    /**
     * Paramètre INCONNU : non rejeté (contrat actuel). Le contrôleur délègue ; seules les clés
     * connues sont persistées par le service. Ici aucune clé connue -> updated = 0, mais 200.
     */
    public function testUpdateParametersUnknownParameterIsIgnoredNotRejected(): void
    {
        $this->outputService->expects($this->once())
            ->method('updateMultipleParameters')
            ->with(['parametreInexistant' => 'x'])
            ->willReturn(['updated' => 0, 'warnings' => []]);

        $response = $this->postParameters(['parametreInexistant' => 'x']);

        $this->assertSame(200, $response->getStatusCode());
        $body = self::decode($response);
        $this->assertSame('ok', $body['status']);
        $this->assertSame(0, $body['updated']);
    }

    /** Validation métier (\InvalidArgumentException) -> 400 avec le message de validation. */
    public function testUpdateParametersInvalidHourReturns400(): void
    {
        $this->outputService->method('updateMultipleParameters')
            ->willThrowException(new \InvalidArgumentException('Horaire bouffeMatin hors plage : un entier entre 0 et 23 est attendu (reçu « 42 »).'));

        $response = $this->postParameters(['bouffeMatin' => '42']);

        $this->assertSame(400, $response->getStatusCode());
        $body = self::decode($response);
        $this->assertSame('error', $body['status']);
        $this->assertStringContainsString('hors plage', $body['message']);
    }

    /** Panne de persistance (\Throwable non-InvalidArgument) -> 500 message générique. */
    public function testUpdateParametersPersistFailureReturns500(): void
    {
        $this->outputService->method('updateMultipleParameters')
            ->willThrowException(new \RuntimeException('DB down'));

        $response = $this->postParameters(['aqThr' => '18']);

        $this->assertSame(500, $response->getStatusCode());
        $body = self::decode($response);
        $this->assertSame('error', $body['status']);
        $this->assertSame('Failed to persist parameters', $body['message']);
    }

    // ------------------------------------------------------------------
    // GET /api/outputs/state
    // ------------------------------------------------------------------

    /**
     * GET state -> 200 JSON public : structure {gpio => state}, fusion des dataStates
     * (témoins page contrôle), en-têtes Content-Type/Content-Length cohérents.
     */
    public function testGetOutputsStateContract(): void
    {
        $this->injectInMemoryDatabase();

        $this->outputService->expects($this->once())->method('ensureAquariumPumpForceOutputRow');
        $this->outputService->method('getLastDataStates')
            ->willReturn(['states' => [2 => 1, 16 => 0], 'readingTime' => '2026-06-14 10:00:00']);

        $this->outputCache->expects($this->once())
            ->method('getOutputsState')
            ->willReturn(['2' => 0, '16' => 1, '117' => 0]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/api/outputs/state');

        $response = $this->makeController()->getOutputsState($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $json = (string) $response->getBody();
        $this->assertSame((string) strlen($json), $response->getHeaderLine('Content-Length'));

        $body = self::decode($response);
        // États GPIO renvoyés par le service de cache.
        $this->assertSame(0, $body['2']);
        $this->assertSame(1, $body['16']);
        $this->assertSame(0, $body['117']);
        // Témoins "dernier état Data" fusionnés par le contrôleur.
        $this->assertArrayHasKey('dataStates', $body);
        $this->assertSame(1, $body['dataStates']['2']);
        $this->assertSame(0, $body['dataStates']['16']);
        $this->assertSame('2026-06-14 10:00:00', $body['dataStatesReadingTime']);
    }

    /** ?fresh=1 : la page de contrôle ignore le cache et NE consomme PAS le one-shot OTA. */
    public function testGetOutputsStateFreshSkipsCacheAndDoesNotConsumeOta(): void
    {
        $this->injectInMemoryDatabase();

        $this->outputService->method('getLastDataStates')
            ->willReturn(['states' => [], 'readingTime' => null]);

        $this->outputCache->expects($this->once())
            ->method('getOutputsState')
            ->with($this->anything(), $this->anything(), true, false)
            ->willReturn(['2' => 0]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/api/outputs/state')
            ->withQueryParams(['fresh' => '1']);

        $response = $this->makeController()->getOutputsState($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $body = self::decode($response);
        $this->assertSame([], $body['dataStates']);
        $this->assertNull($body['dataStatesReadingTime']);
    }

    /** Poll firmware (sans fresh) : cache utilisé ET consommation du one-shot OTA. */
    public function testGetOutputsStateFirmwarePollConsumesOta(): void
    {
        $this->injectInMemoryDatabase();

        $this->outputService->method('getLastDataStates')
            ->willReturn(['states' => [], 'readingTime' => null]);

        $this->outputCache->expects($this->once())
            ->method('getOutputsState')
            ->with($this->anything(), $this->anything(), false, true)
            ->willReturn(['2' => 0, 'triggerOtaCheck' => true]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/api/outputs/state');

        $response = $this->makeController()->getOutputsState($request, new Response());

        $body = self::decode($response);
        $this->assertTrue($body['triggerOtaCheck']);
    }

    // ------------------------------------------------------------------
    // POST /api/outputs/trigger-ota-check
    // ------------------------------------------------------------------

    /** Demande OTA -> 200 {ok:true, message} et délégation au cache service. */
    public function testTriggerOtaCheckContract(): void
    {
        $this->outputCache->expects($this->once())->method('setTriggerOtaCheckRequested');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/outputs/trigger-ota-check');

        $response = $this->makeController()->triggerOtaCheck($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = self::decode($response);
        $this->assertTrue($body['ok']);
        $this->assertArrayHasKey('message', $body);
        $this->assertNotSame('', $body['message']);
    }
}
