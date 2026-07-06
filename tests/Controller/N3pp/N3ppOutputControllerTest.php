<?php

declare(strict_types=1);

namespace Tests\Controller\N3pp;

use App\Controller\N3pp\N3ppOutputController;
use App\Repository\N3ppOutputRepository;
use App\Repository\N3ppSensorRepository;
use App\Repository\NotificationPolicyRepository;
use App\Security\AuthService;
use App\Service\LogService;
use App\Service\TemplateRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Couverture des factorisations H03/H04/H06 portees par AbstractOutputController,
 * vues a travers la sous-classe N3PP (variante setOutput gpio seul, sans branche `name`).
 */
final class N3ppOutputControllerTest extends TestCase
{
    private function createController(
        ?N3ppOutputRepository $outputRepo = null,
        ?AuthService $authService = null
    ): N3ppOutputController {
        $logger = $this->createMock(LogService::class);
        $renderer = $this->createMock(TemplateRenderer::class);
        $authService ??= $this->authenticatedAuthService();
        $outputRepo ??= $this->createMock(N3ppOutputRepository::class);
        $sensorRepo = $this->createMock(N3ppSensorRepository::class);

        return new N3ppOutputController($logger, $renderer, $authService, $outputRepo, $sensorRepo, $this->createMock(NotificationPolicyRepository::class), $this->createMock(\App\Service\NotificationPolicySaveService::class), $this->createMock(\App\Service\NotificationService::class));
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
            ->createServerRequest('POST', '/n3pp/n3ppcontrol/n3pp-outputs-action.php')
            ->withParsedBody($body);
    }

    private function response()
    {
        return (new ResponseFactory())->createResponse();
    }

    private function invokeGetDefaultParams(N3ppOutputController $controller): array
    {
        $ref = new \ReflectionMethod($controller, 'getDefaultParams');
        $ref->setAccessible(true);

        return $ref->invoke($controller);
    }

    // ---------------------------------------------------------------
    // H03 : getDefaultParams() par module
    // ---------------------------------------------------------------

    public function testGetDefaultParamsContainsN3ppSpecificKeys(): void
    {
        $controller = $this->createController();
        $params = $this->invokeGetDefaultParams($controller);

        $this->assertArrayHasKey('HeureArrosage', $params);
        $this->assertArrayHasKey('tempsArrosage', $params);
        $this->assertArrayNotHasKey('ServoHB', $params);
        $this->assertArrayNotHasKey('ServoGD', $params);
        $this->assertArrayNotHasKey('ServoModeAuto', $params);
    }

    public function testGetDefaultParamsFallbackValues(): void
    {
        $controller = $this->createController();
        $params = $this->invokeGetDefaultParams($controller);

        $this->assertSame('', $params['mail']);
        $this->assertSame('false', $params['mailNotif']);
        $this->assertSame('0', $params['HeureArrosage']);
        $this->assertSame('0', $params['tempsArrosage']);
    }

    // ---------------------------------------------------------------
    // H06 : setOutput() — variante gpio seul (pas de branche name)
    // ---------------------------------------------------------------

    public function testSetOutputByGpio(): void
    {
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->once())
            ->method('updateByGpio')
            ->with(105, '1', 3);

        $controller = $this->createController($repo);
        $request = $this->postRequest(['action' => 'set', 'gpio' => '105', 'state' => '1']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(105, $payload['gpio']);
        $this->assertSame('1', $payload['state']);
    }

    public function testSetOutputMissingGpioReturns400(): void
    {
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->never())->method('updateByGpio');

        $controller = $this->createController($repo);
        $request = $this->postRequest(['action' => 'set', 'state' => '1']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(400, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertSame('Paramètre gpio invalide', $payload['error']);
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
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->once())
            ->method('batchUpdateParameters')
            ->with(3, $this->callback(function (array $params): bool {
                return array_key_exists('HeureArrosage', $params)
                    && array_key_exists('tempsArrosage', $params)
                    && array_key_exists('mailNotif', $params);
            }));

        $controller = $this->createController($repo);
        $request = $this->postRequest(['action' => 'output_create', 'HeureArrosage' => '8']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
    }

    /**
     * H02 : requireAuth() utilise l'AuthService injecte (auth par token seul).
     */
    public function testSetOutputAuthorizedByTokenOnly(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('isAuthenticated')->willReturn(false);
        $auth->method('isAuthenticatedByToken')->willReturn(true);

        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->once())->method('updateByGpio')->with(105, '1', 3);

        $controller = $this->createController($repo, $auth);
        $request = $this->postRequest(['action' => 'set', 'gpio' => '105', 'state' => '1']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testSetOutputRequiresAuth(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('isAuthenticated')->willReturn(false);
        $auth->method('isAuthenticatedByToken')->willReturn(false);

        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->never())->method('updateByGpio');

        $controller = $this->createController($repo, $auth);
        $request = $this->postRequest(['action' => 'set', 'gpio' => '105', 'state' => '1']);

        $result = $controller->setOutput($request, $this->response());
        $this->assertSame(401, $result->getStatusCode());
    }

    // ---------------------------------------------------------------
    // H04 : updateParameters()
    // ---------------------------------------------------------------

    public function testUpdateParametersNormalizesMailNotif(): void
    {
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->once())
            ->method('updateParameterByName')
            ->with(3, 'mailNotif', 'checked')
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
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->once())
            ->method('updateParameterByName')
            ->with(3, 'WakeUp', '1')
            ->willReturn(true);

        $controller = $this->createController($repo);
        $request = $this->postRequest(['param' => 'WakeUp', 'value' => 'true']);

        $result = $controller->updateParameters($request, $this->response());
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testUpdateParametersUnknownParamReturns400(): void
    {
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->method('updateParameterByName')->willReturn(false);

        $controller = $this->createController($repo);
        $request = $this->postRequest(['param' => 'inconnu', 'value' => '5']);

        $result = $controller->updateParameters($request, $this->response());
        $this->assertSame(400, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertSame('Paramètre inconnu', $payload['error']);
    }

    public function testToggleOutputByGpio12(): void
    {
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->once())
            ->method('updateByGpio')
            ->with(12, '1', 3);

        $controller = $this->createController($repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/n3pp/api/outputs/toggle')
            ->withParsedBody(['gpio' => '12', 'state' => '1']);

        $result = $controller->toggleOutput($request, $this->response());
        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(12, $payload['gpio']);
    }

    public function testToggleOutputRejectsUnauthorizedGpio(): void
    {
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->never())->method('updateByGpio');

        $controller = $this->createController($repo);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/n3pp/api/outputs/toggle')
            ->withParsedBody(['gpio' => '99', 'state' => '1']);

        $result = $controller->toggleOutput($request, $this->response());
        $this->assertSame(400, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertSame('GPIO non autorisé', $payload['error']);
    }

    public function testUpdateParametersRejectsInvalidSeuilSec(): void
    {
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->never())->method('updateParameterByName');

        $controller = $this->createController($repo);
        $request = $this->postRequest(['param' => 'SeuilSec', 'value' => '5000']);

        $result = $controller->updateParameters($request, $this->response());
        $this->assertSame(400, $result->getStatusCode());
    }

    public function testUpdateParametersRejectsInvalidTempsArrosage(): void
    {
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->never())->method('updateParameterByName');

        $controller = $this->createController($repo);
        $request = $this->postRequest(['param' => 'tempsArrosage', 'value' => '60']);

        $result = $controller->updateParameters($request, $this->response());
        $this->assertSame(400, $result->getStatusCode());
    }

    public function testUpdateParametersRejectsInvalidFreqWakeUp(): void
    {
        $repo = $this->createMock(N3ppOutputRepository::class);
        $repo->expects($this->never())->method('updateParameterByName');

        $controller = $this->createController($repo);
        $request = $this->postRequest(['param' => 'FreqWakeUp', 'value' => '0']);

        $result = $controller->updateParameters($request, $this->response());
        $this->assertSame(400, $result->getStatusCode());
    }
}
