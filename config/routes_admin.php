<?php

/**
 * Routes administration utilisateurs.
 * Inclus depuis public/index.php — variables $app et $applyAuth en scope.
 */

use App\Controller\Admin\ContractAlignmentController;
use App\Controller\Admin\DeviceAdminController;
use App\Controller\Admin\HmacAuditController;
use App\Controller\Admin\OperationalSettingsController;
use App\Controller\Admin\UserAdminController;

$app->group('', function ($group) {
    $group->get('/admin/users', [UserAdminController::class, 'index']);
    $group->get('/admin/users/new', [UserAdminController::class, 'showCreateForm']);
    $group->post('/admin/users', [UserAdminController::class, 'create']);
    $group->get('/admin/users/{id}/edit', [UserAdminController::class, 'showEditForm']);
    $group->post('/admin/users/{id}', [UserAdminController::class, 'update']);
    $group->post('/admin/users/{id}/delete', [UserAdminController::class, 'delete']);

    $group->get('/admin/hmac-audit', [HmacAuditController::class, 'showPage']);
    $group->get('/admin/api/hmac-audit/status', [HmacAuditController::class, 'status']);
    $group->get('/admin/api/hmac-audit/entries', [HmacAuditController::class, 'listEntries']);
    $group->post('/admin/api/hmac-audit/toggle', [HmacAuditController::class, 'toggle']);
    $group->post('/admin/api/hmac-audit/toggle-policy', [HmacAuditController::class, 'togglePolicy']);
    $group->post('/admin/api/hmac-audit/reset-policy', [HmacAuditController::class, 'resetPolicy']);
    $group->get('/admin/api/hmac-audit/policy', [HmacAuditController::class, 'policyStatus']);

    $group->get('/admin/api/operational-settings', [OperationalSettingsController::class, 'list']);
    $group->post('/admin/api/operational-settings', [OperationalSettingsController::class, 'update']);
    $group->post('/admin/api/operational-settings/reset', [OperationalSettingsController::class, 'reset']);

    // Cohérence contrats firmware ↔ serveur (Phase 1 D)
    $group->get('/admin/api/contract-alignment', [ContractAlignmentController::class, 'list']);
    $group->get('/admin/api/contract-alignment/{id}', [ContractAlignmentController::class, 'show']);

    // Catalogue d'appareils / instances (Phase 1 A)
    $group->get('/admin/api/devices', [DeviceAdminController::class, 'list']);
    $group->post('/admin/api/devices', [DeviceAdminController::class, 'create']);
    $group->post('/admin/api/devices/{id}', [DeviceAdminController::class, 'update']);
    $group->post('/admin/api/devices/{id}/delete', [DeviceAdminController::class, 'delete']);
})->add($applyAuth);
