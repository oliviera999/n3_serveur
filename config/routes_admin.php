<?php

/**
 * Routes administration utilisateurs.
 * Inclus depuis public/index.php — variables $app et $applyAuth en scope.
 */

use App\Controller\Admin\UserAdminController;

$app->group('', function ($group) {
    $group->get('/admin/users', [UserAdminController::class, 'index']);
    $group->get('/admin/users/new', [UserAdminController::class, 'showCreateForm']);
    $group->post('/admin/users', [UserAdminController::class, 'create']);
    $group->get('/admin/users/{id}/edit', [UserAdminController::class, 'showEditForm']);
    $group->post('/admin/users/{id}', [UserAdminController::class, 'update']);
    $group->post('/admin/users/{id}/delete', [UserAdminController::class, 'delete']);
})->add($applyAuth);
