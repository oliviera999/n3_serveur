<?php

declare(strict_types=1);

namespace Tests\Controller\Msp;

use App\Controller\Msp\MspOutputController;
use App\Repository\MspOutputRepository;
use App\Repository\MspSensorRepository;
use App\Repository\NotificationPolicyRepository;
use App\Security\AuthService;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Couverture des factorisations H03/H04/H06 portees par AbstractOutputController,
 * vues a travers la sous-classe MSP (variante setOutput avec branche `name`).
 */
final class MspOutputControllerTest extends TestCase
{
    private function createController(
        ?MspOutputRepository $outputRepo = null,
        ?AuthService $authService = null
    ): MspOutputController {
        $logger = $this->createMock(LogService::class);
        $renderer = $this->createMock(TemplateRenderer::class);
        $authService ??= $this->authenticatedAuthService();
        $outputRepo ??= $this->createMock(MspOutputRepository::class);
        $sensorRepo = $this->createMock(MspSensorRepository::class);

        return new MspOutputController($logger, $renderer, $authService, $outputRepo, $sensorRepo, $this->createMock(NotificationPolicyRepository::class));
    }

    private function authenticatedAuthService(): AuthService
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('isAuthenticated')->willReturn(true);
        $auth->method('isAuthenticatedByToken')->willReturn(true);

        return $auth;
    }

    private function postRequest(array $body)
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/msp1/msp1control/msp1-outputs-action.php')
            ->withParsedBody($body);
    }

    private function response()
    {
        return (new ResponseFactory())->createResponse();
    }

    /**
     * Acces a la methode protegee getDefaultParams() pour valider H03.
     */
    private function invokeGetDefaultParams(MspOutputController $controller): array
    {
        $ref = new \ReflectionMethod($controller, 'getDefaultParams');
        $ref->setAccessible(true);

        return $ref->invoke($controller);
    }

    // ---------------------------------------------------------------
    // H03 : getDefaultParams() par module
    // ---------------------------------------------------------------

    public function testGetDefaultParamsContainsMspSpecificKeys(): void
    {
        $controller = $this->createController();
        $params = $this->invokeGetDefaultParams($controller);

        $this->assertArrayHasKey('ServoHB', $params);
        $this->assertArrayHasKey('ServoGD', $params);
        $this->assertArrayNotHasKey('HeureArrosage', $params);
        $this->assertArrayNotHasKey('tempsArrosage', $params);
    }

    public function testGetDefaultParamsFallbackValues(): void
    {
        $controller = $this->createController();
        $params = $this->invokeGetDefaultParams($controller);

        // mail => '', mailNotif => 'false', autres => '0', ServoModeAuto override => '1'
        $this->assertSame('', $params['mail']);
        $this->assertSame('false', $params['mailNotif']);
        $this->assertSame('0', $params['ServoHB']);
        $this->assertSame('1', $params['ServoModeAuto']);
    }

    // ---------------------------------------------------------------
    // H06 : setOutput()
    // ---------------------------------------------------------------

    public function testSetOutputByGpio(): void
    {
        $repo = $this->createMock(MspOutputRepository::class);
        $repo->expects($this->once())
            ->method('updateByGpio')
            ->with(104, '1', 2);
        $repo->expects($this->never())->method('updateByName');

        $controller = $this->createController($repo);
        $request = $this->postRequest(['action' => 'set', 'gpio' => '104', 'state' => '1']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(104, $payload['gpio']);
        $this->assertSame('1', $payload['state']);
    }

    public function testSetOutputByName(): void
    {
        $repo = $this->createMock(MspOutputRepository::class);
        $repo->expects($this->once())
            ->method('updateByName')
            ->with('pompe', '1', 2);
        $repo->expects($this->never())->method('updateByGpio');

        $controller = $this->createController($repo);
        $request = $this->postRequest(['action' => 'set', 'name' => 'pompe', 'state' => '1']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('pompe', $payload['name']);
        $this->assertSame('1', $payload['state']);
    }

    public function testSetOutputMissingGpioAndNameReturns400(): void
    {
        $repo = $this->createMock(MspOutputRepository::class);
        $repo->expects($this->never())->method('updateByGpio');
        $repo->expects($this->never())->method('updateByName');

        $controller = $this->createController($repo);
        $request = $this->postRequest(['action' => 'set', 'state' => '1']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(400, $result->getStatusCode());
    }

    public function testSetOutputUnknownActionReturns400(): void
    {
        $controller = $this->createController();
        $request = $this->postRequest(['action' => 'nope']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(400, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertSame('Action inconnue', $payload['error']);
    }

    public function testSetOutputOutputCreateBatchUpdates(): void
    {
        $repo = $this->createMock(MspOutputRepository::class);
        $repo->expects($this->once())
            ->method('batchUpdateParameters')
            ->with(2, $this->callback(function (array $params): bool {
                // Le batch couvre toutes les cles par defaut, ServoGD valide ecrasee.
                return ($params['ServoGD'] ?? null) === '90'
                    && array_key_exists('mailNotif', $params)
                    && array_key_exists('ServoHB', $params);
            }));

        $controller = $this->createController($repo);
        $request = $this->postRequest(['action' => 'output_create', 'ServoGD' => '90']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
    }

    public function testSetOutputRequiresAuth(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('isAuthenticated')->willReturn(false);
        $auth->method('isAuthenticatedByToken')->willReturn(false);

        $repo = $this->createMock(MspOutputRepository::class);
        $repo->expects($this->never())->method('updateByGpio');

        $controller = $this->createController($repo, $auth);
        $request = $this->postRequest(['action' => 'set', 'gpio' => '104', 'state' => '1']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(401, $result->getStatusCode());
    }

    // ---------------------------------------------------------------
    // H04 : updateParameters()
    // ---------------------------------------------------------------

    public function testUpdateParametersNormalizesMailNotif(): void
    {
        $repo = $this->createMock(MspOutputRepository::class);
        $repo->expects($this->once())
            ->method('updateParameterByName')
            ->with(2, 'mailNotif', 'checked')
            ->willReturn(true);

        $controller = $this->createController($repo);
        $request = $this->postRequest(['param' => 'mailNotif', 'value' => '1']);

        $result = $controller->updateParameters($request, $this->response());
        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('mailNotif', $payload['param']);
    }

    public function testUpdateParametersNormalizesWakeUp(): void
    {
        $repo = $this->createMock(MspOutputRepository::class);
        $repo->expects($this->once())
            ->method('updateParameterByName')
            ->with(2, 'WakeUp', '1')
            ->willReturn(true);

        $controller = $this->createController($repo);
        $request = $this->postRequest(['param' => 'WakeUp', 'value' => 'true']);

        $result = $controller->updateParameters($request, $this->response());
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testUpdateParametersUnknownParamReturns400(): void
    {
        $repo = $this->createMock(MspOutputRepository::class);
        $repo->method('updateParameterByName')->willReturn(false);

        $controller = $this->createController($repo);
        $request = $this->postRequest(['param' => 'inconnu', 'value' => '5']);

        $result = $controller->updateParameters($request, $this->response());
        $this->assertSame(400, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertSame('Paramètre inconnu', $payload['error']);
    }

    public function testUpdateParametersRejectsInvalidServoGd(): void
    {
        $repo = $this->createMock(MspOutputRepository::class);
        $repo->expects($this->never())->method('updateParameterByName');

        $controller = $this->createController($repo);
        $request = $this->postRequest(['param' => 'ServoGD', 'value' => '500']);

        $result = $controller->updateParameters($request, $this->response());
        $this->assertSame(400, $result->getStatusCode());
    }
}
