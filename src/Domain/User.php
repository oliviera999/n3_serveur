<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * DTO représentant un utilisateur de l'interface d'administration.
 */
final class User
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_READER = 'reader';

    /** @var list<string> */
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_READER];

    public function __construct(
        public int $id,
        public string $username,
        public ?string $email,
        public ?string $displayName,
        public string $role,
        public bool $isActive,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $lastLoginAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            username: (string) $row['username'],
            email: isset($row['email']) && $row['email'] !== '' ? (string) $row['email'] : null,
            displayName: isset($row['display_name']) && $row['display_name'] !== '' ? (string) $row['display_name'] : null,
            role: (string) $row['role'],
            isActive: (bool) (int) ($row['is_active'] ?? 0),
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            lastLoginAt: isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
        );
    }

    public function getLabel(): string
    {
        return $this->displayName ?? $this->username;
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            self::ROLE_ADMIN => 'Administrateur',
            self::ROLE_OPERATOR => 'Opérateur',
            self::ROLE_READER => 'Lecteur',
            default => $role,
        };
    }
}
