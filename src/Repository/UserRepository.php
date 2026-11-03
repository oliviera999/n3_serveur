<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User;
use App\Security\McpCredentialEpoch;
use PDOException;

/**
 * Accès PDO à la table n3_users.
 */
class UserRepository extends AbstractRepository
{
    private const TABLE = 'n3_users';

    /**
     * Hash bcrypt bidon (mot de passe aléatoire inconnu) pour un password_verify
     * factice quand l'utilisateur est absent, afin d'égaliser le temps de réponse
     * et d'éviter l'énumération d'utilisateurs par analyse de timing (B2).
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$4emarLin6MEkat6qo8fXHOgMKk9yxjaa.kJcBNOD1Ti4Jbh6tSrGS';

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
            // Anti-énumération (B2) : exécuter un password_verify factice pour
            // égaliser le temps de réponse avec le cas « utilisateur existant,
            // mauvais mot de passe » (le hash bidon ne matche jamais).
            password_verify($password, self::DUMMY_PASSWORD_HASH);
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
        $before = $this->fetchOne(
            'SELECT role, is_active FROM `' . self::TABLE . '` WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
        $ok = $this->execute(
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
        // Désactivation ou changement de rôle : les connexions MCP existantes sont coupées
        // (un nouveau consentement recalculera les scopes du nouveau rôle).
        if ($before !== null && ((string) $before['role'] !== $role || ((int) $before['is_active'] === 1) !== $isActive)) {
            $this->revokeMcpTokens($id);
        }

        return $ok;
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $ok = $this->execute(
            'UPDATE `' . self::TABLE . '` SET password_hash = :password_hash WHERE id = :id',
            ['id' => $id, 'password_hash' => $passwordHash]
        );
        // Changement de mot de passe : tous les jetons MCP (accès + refresh) de l'utilisateur
        // sont révoqués — un jeton ne survit pas au secret qui l'a obtenu.
        $this->revokeMcpTokens($id);

        return $ok;
    }

    /**
     * Empreinte courte du hachage de mot de passe (« époque d'identifiants ») : liée aux
     * jetons MCP à leur émission, elle change dès que le mot de passe change — y compris
     * par une requête SQL directe — et invalide alors tous les jetons émis avant.
     * Jamais renvoyée au client ; dérivée par HMAC, avec une clé tirée d'un secret serveur
     * ({@see McpCredentialEpoch}), pour ne rien révéler du hachage.
     */
    public function credentialEpoch(int $id): ?string
    {
        $hash = $this->fetchScalar('SELECT password_hash FROM `' . self::TABLE . '` WHERE id = :id', ['id' => $id]);
        if (!is_string($hash) || $hash === '') {
            return null;
        }

        return McpCredentialEpoch::compute($hash);
    }

    /**
     * Identifiant du compte correspondant à ce nom, actif ou non, ou null. La comparaison suit
     * la collation de la colonne (`utf8mb4_unicode_ci` en production : casse, accents et
     * espaces finaux ignorés), exactement comme {@see verifyPassword()}.
     */
    public function findIdByUsername(string $username): ?int
    {
        $id = $this->fetchScalar('SELECT id FROM `' . self::TABLE . '` WHERE username = :username LIMIT 1', ['username' => $username]);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Révoque tous les jetons OAuth du connecteur MCP d'un utilisateur. Sans effet (et
     * sans erreur) si la migration MCP n'a pas été jouée.
     *
     * @return int nombre de jetons révoqués
     */
    public function revokeMcpTokens(int $id): int
    {
        try {
            return $this->executeWithRowCount(
                'UPDATE mcp_oauth_tokens SET revoked_at = :now WHERE user_id = :id AND revoked_at IS NULL',
                ['now' => date('Y-m-d H:i:s'), 'id' => $id]
            );
        } catch (PDOException) {
            return 0;
        }
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
        $this->revokeMcpTokens($id);

        return $this->executeWithRowCount(
            'DELETE FROM `' . self::TABLE . '` WHERE id = :id',
            ['id' => $id]
        ) > 0;
    }
}
