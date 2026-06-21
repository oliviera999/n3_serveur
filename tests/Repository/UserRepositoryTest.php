<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Domain\User;
use App\Repository\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de UserRepository (table n3_users) sur SQLite en memoire.
 *
 * Couvre :
 *  - isEmpty / tableExists (et leur comportement quand la table est absente) ;
 *  - findByUsername / findById / findAll (mapping vers App\Domain\User) ;
 *  - verifyPassword (hash valide, mauvais mot de passe, compte inactif) ;
 *  - countActiveByRole ;
 *  - create / update / updatePassword / delete.
 *
 * Piege connu :
 *  - updateLastLogin() utilise NOW() (MySQL), absent de SQLite : on l'override via un
 *    double de test (CURRENT_TIMESTAMP) pour exercer la logique.
 */
class UserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createUsersTable();

        $this->repo = new UserRepository($this->pdo);
    }

    private function createUsersTable(): void
    {
        $this->pdo->exec('CREATE TABLE n3_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT, email TEXT, display_name TEXT, password_hash TEXT,
            role TEXT, is_active INTEGER,
            created_at TEXT, updated_at TEXT, last_login_at TEXT
        )');
    }

    public function testIsEmptyTrueOnFreshTable(): void
    {
        $this->assertTrue($this->repo->isEmpty());
    }

    public function testIsEmptyFalseAfterInsert(): void
    {
        $this->repo->create('alice', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_ADMIN);
        $this->assertFalse($this->repo->isEmpty());
    }

    public function testTableExists(): void
    {
        $this->assertTrue($this->repo->tableExists());

        $this->pdo->exec('DROP TABLE n3_users');
        $this->assertFalse($this->repo->tableExists());
    }

    public function testIsEmptyReturnsTrueWhenTableMissing(): void
    {
        $this->pdo->exec('DROP TABLE n3_users');
        $this->assertTrue($this->repo->isEmpty());
    }

    public function testCreateReturnsInsertedIdAndFindById(): void
    {
        $id = $this->repo->create(
            'bob',
            password_hash('secret', PASSWORD_DEFAULT),
            User::ROLE_OPERATOR,
            'bob@x.y',
            'Bob',
            true
        );

        $this->assertGreaterThan(0, $id);

        $user = $this->repo->findById($id);
        $this->assertNotNull($user);
        $this->assertSame('bob', $user->username);
        $this->assertSame('bob@x.y', $user->email);
        $this->assertSame('Bob', $user->displayName);
        $this->assertSame(User::ROLE_OPERATOR, $user->role);
        $this->assertTrue($user->isActive);
    }

    public function testFindByUsername(): void
    {
        $this->repo->create('carol', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_READER);

        $user = $this->repo->findByUsername('carol');
        $this->assertNotNull($user);
        $this->assertSame(User::ROLE_READER, $user->role);

        $this->assertNull($this->repo->findByUsername('absent'));
    }

    public function testFindByIdReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    public function testFindAllOrdersByUsername(): void
    {
        $this->repo->create('zoe', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_READER);
        $this->repo->create('amy', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_READER);

        $all = $this->repo->findAll();
        $this->assertCount(2, $all);
        $this->assertSame('amy', $all[0]->username);
        $this->assertSame('zoe', $all[1]->username);
    }

    public function testVerifyPasswordSuccess(): void
    {
        $this->repo->create('dan', password_hash('topsecret', PASSWORD_DEFAULT), User::ROLE_ADMIN);

        $user = $this->repo->verifyPassword('dan', 'topsecret');
        $this->assertNotNull($user);
        $this->assertSame('dan', $user->username);
    }

    public function testVerifyPasswordWrongPassword(): void
    {
        $this->repo->create('eve', password_hash('topsecret', PASSWORD_DEFAULT), User::ROLE_ADMIN);

        $this->assertNull($this->repo->verifyPassword('eve', 'wrong'));
    }

    public function testVerifyPasswordRejectsInactiveUser(): void
    {
        $this->repo->create('frank', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_ADMIN, null, null, false);

        $this->assertNull($this->repo->verifyPassword('frank', 'pw'));
    }

    public function testVerifyPasswordUnknownUser(): void
    {
        $this->assertNull($this->repo->verifyPassword('ghost', 'pw'));
    }

    public function testCountActiveByRole(): void
    {
        $this->repo->create('a1', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_ADMIN);
        $this->repo->create('a2', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_ADMIN);
        $this->repo->create('a3', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_ADMIN, null, null, false);
        $this->repo->create('r1', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_READER);

        $this->assertSame(2, $this->repo->countActiveByRole(User::ROLE_ADMIN));
        $this->assertSame(1, $this->repo->countActiveByRole(User::ROLE_READER));
        $this->assertSame(0, $this->repo->countActiveByRole(User::ROLE_OPERATOR));
    }

    public function testUpdateModifiesEditableFields(): void
    {
        $id = $this->repo->create('ivan', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_READER);

        $ok = $this->repo->update($id, 'ivan@x.y', 'Ivan', User::ROLE_OPERATOR, false);
        $this->assertTrue($ok);

        $user = $this->repo->findById($id);
        $this->assertNotNull($user);
        $this->assertSame('ivan@x.y', $user->email);
        $this->assertSame('Ivan', $user->displayName);
        $this->assertSame(User::ROLE_OPERATOR, $user->role);
        $this->assertFalse($user->isActive);
    }

    public function testUpdatePasswordChangesHash(): void
    {
        $id = $this->repo->create('jane', password_hash('old', PASSWORD_DEFAULT), User::ROLE_ADMIN);

        $this->assertTrue($this->repo->updatePassword($id, password_hash('new', PASSWORD_DEFAULT)));

        $this->assertNull($this->repo->verifyPassword('jane', 'old'));
        $this->assertNotNull($this->repo->verifyPassword('jane', 'new'));
    }

    public function testDeleteRemovesUser(): void
    {
        $id = $this->repo->create('kim', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_READER);

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->findById($id));

        // Suppression d'un id inexistant -> aucune ligne affectee -> false.
        $this->assertFalse($this->repo->delete($id));
    }

    public function testUpdateLastLoginViaTestDouble(): void
    {
        // updateLastLogin() utilise NOW() (MySQL) : on l'override avec CURRENT_TIMESTAMP (SQLite).
        $repo = new class ($this->pdo) extends UserRepository {
            public function updateLastLogin(int $id): bool
            {
                $stmt = $this->pdo->prepare(
                    'UPDATE n3_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                return $stmt->execute(['id' => $id]);
            }
        };

        $id = $repo->create('leo', password_hash('pw', PASSWORD_DEFAULT), User::ROLE_ADMIN);
        $this->assertTrue($repo->updateLastLogin($id));

        $user = $repo->findById($id);
        $this->assertNotNull($user);
        $this->assertNotNull($user->lastLoginAt);
    }
}
