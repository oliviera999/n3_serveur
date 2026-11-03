<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpContext;
use App\Mcp\ToolException;

/**
 * Contrôle du rôle administrateur pour les outils d'exploitation (fichiers, CRON).
 * Le rôle est relu en base à chaque requête (McpController) : une rétrogradation prend effet aussitôt.
 */
final class AdminGuard
{
    public static function assertAdmin(McpContext $context): void
    {
        if (!$context->isAdmin()) {
            throw new ToolException('Réservé aux comptes administrateurs (rôle admin dans /admin/users).', 'forbidden');
        }
    }
}
