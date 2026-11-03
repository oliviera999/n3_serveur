<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Levée quand un outil exige un scope que le jeton ne porte pas alors que l'utilisateur
 * pourrait l'obtenir : le contrôleur répond `403` + `WWW-Authenticate: Bearer
 * error="insufficient_scope", scope="…"` (step-up d'autorisation MCP).
 */
final class InsufficientScopeException extends \RuntimeException
{
    public function __construct(public readonly string $scope)
    {
        parent::__construct('Scope requis : ' . $scope);
    }
}
