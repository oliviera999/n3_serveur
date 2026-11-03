<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Domain\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Identité et droits effectifs de l'appelant MCP pour la requête en cours.
 *
 * Construit par {@see \App\Controller\McpController} à partir du jeton d'accès validé :
 * le rôle est RELU en base à chaque requête (un utilisateur rétrogradé ou désactivé perd
 * immédiatement ses droits), et les scopes effectifs sont l'intersection des scopes
 * accordés et de ce que le rôle autorise.
 */
final class McpContext
{
    /**
     * @param list<string> $scopes Scopes effectifs
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $role,
        public readonly array $scopes,
        public readonly string $clientId,
        public readonly ?ServerRequestInterface $request = null,
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === User::ROLE_ADMIN;
    }

    public function isOperator(): bool
    {
        return in_array($this->role, [User::ROLE_OPERATOR, User::ROLE_ADMIN], true);
    }
}
