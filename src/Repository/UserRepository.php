<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User;
use PDOException;

/**
 * Accès PDO à la table n3_users.
 */
class UserRepository extends AbstractRepository
{
    private const TABLE = 'n3_users';

    public function isEmpty(): bool
    {
        try {
            $count = $this->fetchScalar('SELECT COUNT(*) FROM `' . self::TABLE . '`');
            return (int) $count === 0;
        } catch (PDOException) {
            return true;
        }
    }

    public function tableExists(): bool
    {
        try {
            $this->fetchScalar('SELECT COUNT(*) FROM `' . self::TABLE . '`');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public function findByUsername(string $username): ?User
    {
        $row = $this->fetchOne(
            'SELECT * FROM `' . self::TABLE . '` WHERE username = :username LIMIT 1',
            ['username' => $username]
        );
        return $row !== null ? User::fromRow($row) : null;
    }

    /**
     * Vérifie le mot de passe et retourne l'utilisateur si valide et actif.
     */
    public function verifyPassword(string $username, string $password): ?User
    {
        $row = $this->fetchOne(
            'SELECT * FROM `' . self::TABLE . '` WHERE username = :username AND is_active = 1 LIMIT 1',
            ['username' => $username]
        );
        if ($row === null) {
            return null;
        }
        $hash = (string) ($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }
        return User::fromRow($row);
    }

    public function findById(int $id): ?User
    {
        $row = $this->fetchOne(
            'SELECT * FROM `' . self::TABLE . '` WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
        return $row !== null ? User::fromRow($row) : null;
    }

    /**
     * @return list<User>
     */
    public function findAll(): array
    {
        $rows = $this->fetchAll(
            'SELECT * FROM `' . self::TABLE . '` ORDER BY username ASC'
        );
        return array_map(static fn (array $row): User => User::fromRow($row), $rows);
    }

    public function countActiveByRole(string $role): int
    {
        return (int) $this->fetchScalar(
            'SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE role = :role AND is_active = 1',
            ['role' => $role]
        );
    }

    public function create(
        string $username,
        string $passwordHash,
        string $role,
        ?string $email = null,
        ?string $displayName = null,
        bool $isActive = true,
    ): int {
        $this->execute(
            'INSERT INTO `' . self::TABLE . '`
                (username, email, display_name, password_hash, role, is_active)
             VALUES
                (:username, :email, :display_name, :password_hash, :role, :is_active)',
            [
                'username' => $username,
                'email' => $email,
                'display_name' => $displayName,
                'password_hash' => $passwordHash,
                'role' => $role,
                'is_active' => $isActive ? 1 : 0,
            ]
        );
        return (int) $this->lastInsertId();
    }

    public function update(
        int $id,
        ?string $email,
        ?string $displayName,
        string $role,
        bool $isActive,
    ): bool {
        return $this->execute(
            'UPDATE `' . self::TABLE . '`
             SET email = :email, display_name = :display_name, role = :role, is_active = :is_active
             WHERE id = :id',
            [
                'id' => $id,
                'email' => $email,
                'display_name' => $displayName,
                'role' => $role,
                'is_active' => $isActive ? 1 : 0,
            ]
        );
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        return $this->execute(
            'UPDATE `' . self::TABLE . '` SET password_hash = :password_hash WHERE id = :id',
            ['id' => $id, 'password_hash' => $passwordHash]
        );
    }

    public function updateLastLogin(int $id): bool
    {
        return $this->execute(
            'UPDATE `' . self::TABLE . '` SET last_login_at = NOW() WHERE id = :id',
            ['id' => $id]
        );
    }

    public function delete(int $id): bool
    {
        return $this->executeWithRowCount(
            'DELETE FROM `' . self::TABLE . '` WHERE id = :id',
            ['id' => $id]
        ) > 0;
    }
}
