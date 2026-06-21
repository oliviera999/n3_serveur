<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User;

/**
 * Détermine le rôle minimum requis pour accéder à un chemin protégé.
 */
class RoleAccessService
{
    /** @var array<string, int> */
    private const ROLE_LEVELS = [
        User::ROLE_READER => 1,
        User::ROLE_OPERATOR => 2,
        User::ROLE_ADMIN => 3,
    ];

    /** @var list<string> */
    private array $adminPrefixes;

    /** @var list<string> */
    private array $readerPrefixes;

    /** @var list<string> */
    private array $protectedPrefixes;

    /**
     * @param array<string, mixed> $routesConfig
     */
    public function __construct(array $routesConfig)
    {
        $roleRequirements = $routesConfig['role_requirements'] ?? [];
        $this->adminPrefixes = $roleRequirements['admin'] ?? ['/admin/users'];
        $this->readerPrefixes = $roleRequirements['reader'] ?? [];
        $this->protectedPrefixes = $routesConfig['protected_paths'] ?? [];
    }

    public function getMinimumRoleForPath(string $path): ?string
    {
        if (!$this->isProtectedPath($path)) {
            return null;
        }

        foreach ($this->adminPrefixes as $prefix) {
            if ($this->pathStartsWith($path, (string) $prefix)) {
                return User::ROLE_ADMIN;
            }
        }

        foreach ($this->readerPrefixes as $prefix) {
            if ($this->pathStartsWith($path, (string) $prefix)) {
                return User::ROLE_READER;
            }
        }

        return User::ROLE_OPERATOR;
    }

    public function hasAccess(string $path, ?string $userRole): bool
    {
        $requiredRole = $this->getMinimumRoleForPath($path);
        if ($requiredRole === null) {
            return true;
        }

        if ($userRole === null) {
            return false;
        }

        $userLevel = self::ROLE_LEVELS[$userRole] ?? 0;
        $requiredLevel = self::ROLE_LEVELS[$requiredRole] ?? 99;

        return $userLevel >= $requiredLevel;
    }

    private function isProtectedPath(string $path): bool
    {
        foreach ($this->protectedPrefixes as $protectedPath) {
            if (strpos($path, (string) $protectedPath) === 0) {
                return true;
            }
        }
        return false;
    }

    private function pathStartsWith(string $path, string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }
        if ($path === $prefix) {
            return true;
        }
        if (str_starts_with($path, $prefix)) {
            $next = $path[strlen($prefix)] ?? '';
            return $next === '' || $next === '/';
        }
        return false;
    }
}
