<?php

declare(strict_types=1);

namespace Tests\Controller\Ffp3;

use App\Controller\Ffp3\PostDataController;
use App\Middleware\RawPostBodyMiddleware;
use App\Repository\BoardRepository;
use App\Repository\OutputRepository;
use App\Repository\SensorRepository;
use App\Security\Ffp3HmacPostBody;
use App\Security\SignatureValidator;
use App\Service\ErrorAlertService;
use App\Service\LogService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

class PostDataHmacAuthTest extends TestCase
{
    private const SIG_SECRET = 'unit-test-api-sig-secret';

    protected function setUp(): void
    {
        $_ENV['API_SIG_SECRET'] = self::SIG_SECRET;
        $_ENV['API_KEY'] = 'unit-test-api-key';
        $_ENV['HMAC_STRICT_MODE'] = 'false';
        $_ENV['HMAC_NONCE_REQUIRED'] = 'false';
    }

    public function testPostDataAcceptsXSigWithEmptyBodyStreamAndCanonicalRebuild(): void
    {
        $params = [
            'api_key' => 'unit-test-api-key',
            'sensor' => 'esp32-wroom',
            'version' => '13.89',
            'TempAir' => '25.0',
            'Humidite' => '60.0',
            'Pression' => '1013.0',
            'TempEau' => '22.0',
            'EauPotager' => '100',
            'EauAquarium' => '200',
            'EauReserve' => '150',
            'diffMaree' => '-1',
            'Luminosite' => '300',
            'etatPompeAqua' => '1',
            'etatPompeTank' => '0',
            'etatHeat' => '0',
            'etatUV' => '1',
            'bouffeMatin' => '8',
            'bouffeMidi' => '12',
            'bouffeSoir' => '20',
            'tempsGros' => '6',
            'tempsPetits' => '6',
            'aqThreshold' => '1',
            'tankThreshold' => '2005',
            'chauffageThreshold' => '18.0',
            'tempsRemplissageSec' => '6',
            'limFlood' => '6',
            'WakeUp' => '0',
            'FreqWakeUp' => '600',
            'bouffePetits' => '0',
            'bouffeGros' => '0',
            'mail' => 'test@test.com',
            'mailNotif' => 'checked',
            'resetMode' => '0',
            'tideEvent' => 'none',
            'tideTrend' => '0',
            'tideNoiseMm' => '5',
            'tideWindowMs' => '60000',
            'tideExtremeMm' => '10',
            'configSynced' => '1',
            'post_id' => 'esp32-wroom-999-1',
        ];

        $body = Ffp3HmacPostBody::buildFromParams($params);
        $timestamp = (string) time();
        $nonce = 'deadbeefcafebabe';
        $hmac = SignatureValidator::createSignatureForBody((int) $timestamp, $nonce, $body, self::SIG_SECRET);

        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/post-data-test')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('X-Sig-Timestamp', $timestamp)
            ->withHeader('X-Sig-Nonce', $nonce)
            ->withHeader('X-Sig-Hmac', $hmac)
            ->withParsedBody($params)
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, '')
            ->withBody((new StreamFactory())->createStream(''));

        $sensorRepo = $this->createMock(SensorRepository::class);
        $sensorRepo->method('existsByPostId')->willReturn(false);
        $sensorRepo->method('insertAtomically')->willReturnCallback(
            static function ($data, callable $afterInsert): void {
                $afterInsert($data);
            }
        );

        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->method('ensureAquariumPumpForceRowExists');
        $outputRepo->method('syncStatesFromSensorData');

        $boardRepo = $this->createMock(BoardRepository::class);
        $boardRepo->method('updateLastRequest');

        $logger = $this->createMock(LogService::class);
        $errorAlert = $this->createMock(ErrorAlertService::class);

        $controller = new PostDataController(
            $logger,
            $errorAlert,
            $sensorRepo,
            $outputRepo,
            $boardRepo
        );

        $response = $controller->handle($request, new Response());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('succes', (string) $response->getBody());
    }

    public function testPostDataAcceptsPayloadWithoutEauAquarium(): void
    {
        $params = [
            'api_key' => 'unit-test-api-key',
            'sensor' => 'esp32-wroom',
            'version' => '13.90',
            'TempAir' => '25.0',
            'Humidite' => '60.0',
            'Pression' => '1013.0',
            'TempEau' => '22.0',
            'EauPotager' => '100',
            'EauReserve' => '150',
            'diffMaree' => '0',
            'Luminosite' => '300',
            'etatPompeAqua' => '1',
            'etatPompeTank' => '0',
            'etatHeat' => '0',
            'etatUV' => '1',
            'bouffeMatin' => '8',
            'bouffeMidi' => '12',
            'bouffeSoir' => '20',
            'tempsGros' => '6',
            'tempsPetits' => '6',
            'aqThreshold' => '1',
            'tankThreshold' => '2005',
            'chauffageThreshold' => '18.0',
            'tempsRemplissageSec' => '6',
            'limFlood' => '6',
            'WakeUp' => '0',
            'FreqWakeUp' => '600',
            'bouffePetits' => '0',
            'bouffeGros' => '0',
            'mail' => 'test@test.com',
            'mailNotif' => 'checked',
            'resetMode' => '0',
            'tideEvent' => 'none',
            'tideTrend' => '0',
            'tideNoiseMm' => '5',
            'tideWindowMs' => '60000',
            'tideExtremeMm' => '10',
            'configSynced' => '1',
            'post_id' => 'esp32-wroom-no-aqua-2',
        ];

        $body = Ffp3HmacPostBody::buildFromParams($params);
        $timestamp = (string) time();
        $nonce = 'cafebabedeadbeef';
        $hmac = SignatureValidator::createSignatureForBody((int) $timestamp, $nonce, $body, self::SIG_SECRET);

        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/post-data-test')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('X-Sig-Timestamp', $timestamp)
            ->withHeader('X-Sig-Nonce', $nonce)
            ->withHeader('X-Sig-Hmac', $hmac)
            ->withParsedBody($params)
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, '')
            ->withBody((new StreamFactory())->createStream(''));

        $insertedAquarium = null;
        $sensorRepo = $this->createMock(SensorRepository::class);
        $sensorRepo->method('existsByPostId')->willReturn(false);
        $sensorRepo->method('insertAtomically')->willReturnCallback(
            static function ($data, callable $afterInsert) use (&$insertedAquarium): void {
                $insertedAquarium = $data->eauAquarium;
                $afterInsert($data);
            }
        );

        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->method('ensureAquariumPumpForceRowExists');
        $outputRepo->method('syncStatesFromSensorData');

        $boardRepo = $this->createMock(BoardRepository::class);
        $boardRepo->method('updateLastRequest');

        $controller = new PostDataController(
            $this->createMock(LogService::class),
            $this->createMock(ErrorAlertService::class),
            $sensorRepo,
            $outputRepo,
            $boardRepo
        );

        $response = $controller->handle($request, new Response());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($insertedAquarium);
    }

    public function testPostDataAcceptsXSigWithRawBodyMatchingFirmwareOmitWaterLevels(): void
    {
        $params = [
            'api_key' => 'unit-test-api-key',
            'sensor' => 'esp32-wroom',
            'version' => '14.01',
            'TempAir' => '25.0',
            'Humidite' => '60.0',
            'Pression' => '1013.0',
            'TempEau' => '20.0',
            'EauReserve' => '98',
            'diffMaree' => '0',
            'Luminosite' => '300',
            'etatPompeAqua' => '1',
            'etatPompeTank' => '0',
            'etatHeat' => '0',
            'etatUV' => '0',
            'bouffeMatin' => '8',
            'bouffeMidi' => '12',
            'bouffeSoir' => '20',
            'tempsGros' => '6',
            'tempsPetits' => '6',
            'aqThreshold' => '1',
            'tankThreshold' => '2005',
            'chauffageThreshold' => '18.0',
            'tempsRemplissageSec' => '6',
            'limFlood' => '6',
            'WakeUp' => '0',
            'FreqWakeUp' => '600',
            'bouffePetits' => '0',
            'bouffeGros' => '0',
            'mail' => 'test@test.com',
            'mailNotif' => 'checked',
            'resetMode' => '0',
            'tideEvent' => 'none',
            'tideTrend' => '0',
            'tideNoiseMm' => '5',
            'tideWindowMs' => '60000',
            'tideExtremeMm' => '10',
            'configSynced' => '1',
            'post_id' => 'esp32-wroom-raw-body-3',
        ];

        $body = Ffp3HmacPostBody::buildFromParams($params);
        $timestamp = (string) time();
        $nonce = '0123456789abcdef';
        $hmac = SignatureValidator::createSignatureForBody((int) $timestamp, $nonce, $body, self::SIG_SECRET);

        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/post-data-test')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('X-Sig-Timestamp', $timestamp)
            ->withHeader('X-Sig-Nonce', $nonce)
            ->withHeader('X-Sig-Hmac', $hmac)
            ->withParsedBody($params)
            ->withAttribute(RawPostBodyMiddleware::ATTRIBUTE, $body)
            ->withBody((new StreamFactory())->createStream($body));

        $sensorRepo = $this->createMock(SensorRepository::class);
        $sensorRepo->method('existsByPostId')->willReturn(false);
        $sensorRepo->method('insertAtomically')->willReturnCallback(
            static function ($data, callable $afterInsert): void {
                $afterInsert($data);
            }
        );

        $outputRepo = $this->createMock(OutputRepository::class);
        $outputRepo->method('ensureAquariumPumpForceRowExists');
        $outputRepo->method('syncStatesFromSensorData');

        $boardRepo = $this->createMock(BoardRepository::class);
        $boardRepo->method('updateLastRequest');

        $controller = new PostDataController(
            $this->createMock(LogService::class),
            $this->createMock(ErrorAlertService::class),
            $sensorRepo,
            $outputRepo,
            $boardRepo
        );

        $response = $controller->handle($request, new Response());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Cle API invalide', (string) $response->getBody());
    }
}
