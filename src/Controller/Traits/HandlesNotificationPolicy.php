<?php

declare(strict_types=1);

namespace App\Controller\Traits;

use App\Notification\NotificationFamily;
use App\Repository\NotificationPolicyRepository;
use App\Service\NotificationPolicySaveService;
use App\Util\RequestHelper;
use App\Util\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Endpoint et données Twig partagés pour la politique de notifications (pages contrôle).
 */
trait HandlesNotificationPolicy
{
    abstract protected function notificationFamily(): NotificationFamily;

    /**
     * @return array<string, mixed>
     */
    protected function notificationPolicyTwigData(NotificationPolicyRepository $policyRepo): array
    {
        $family = $this->notificationFamily();
        $policyRepo->ensurePolicyRows($family);
        $ui = $policyRepo->getUiState($family);

        return [
            'notif_mode' => $ui['mode'],
            'notif_categories_enabled' => $ui['categories_enabled'],
            'notif_from_env' => $ui['from_env'],
            'notification_modes_meta' => NotificationPolicySaveService::modesMeta(),
            'notification_categories_meta' => NotificationPolicySaveService::categoriesMeta(),
        ];
    }

    public function updateNotificationPolicy(
        Request $request,
        Response $response,
        NotificationPolicySaveService $saveService,
        NotificationPolicyRepository $policyRepo
    ): Response {
        $authError = $this->requireAuthForNotificationPolicy($request, $response);
        if ($authError !== null) {
            return $authError;
        }

        $payload = RequestHelper::extractParams($request);
        $mode = trim((string) ($payload['mode'] ?? ''));
        $categoriesRaw = $payload['categories'] ?? [];
        if (!is_array($categoriesRaw)) {
            $categoriesRaw = [];
        }
        $categories = array_map(static fn ($v): string => trim((string) $v), $categoriesRaw);

        $family = $this->notificationFamily();
        $result = $saveService->save($family, $mode, $categories);
        if (($result['ok'] ?? false) !== true) {
            return ResponseHelper::json($response, [
                'error' => $result['error'] ?? 'Échec de la sauvegarde',
            ], 400);
        }

        $ui = $policyRepo->getUiState($family);

        return ResponseHelper::json($response, [
            'success' => true,
            'mode' => $ui['mode'],
            'categories_enabled' => $ui['categories_enabled'],
            'from_env' => $ui['from_env'],
        ]);
    }

    /**
     * @return Response|null
     */
    abstract protected function requireAuthForNotificationPolicy(Request $request, Response $response): ?Response;
}
