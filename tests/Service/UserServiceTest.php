<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Config\Env;
use App\Domain\User;
use App\Repository\UserRepository;
use App\Service\UserService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class UserServiceTest extends TestCase
{
    private string $previousUsername;
    private string $previousHash;
    private bool $previousEnvLoaded;

    protected function setUp(): void
    {
        $this->previousUsername = $_ENV['ADMIN_USERNAME'] ?? '';
        $this->previousHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? '';

        // Empêche Env::load() de recharger le .env réel et d'écraser nos $_ENV.
        $ref = new ReflectionClass(Env::class);
        $prop = $ref->getProperty('loaded');
        $prop->setAccessible(true);
        $this->previousEnvLoaded = (bool) $prop->getValue();
        $prop->setValue(null, true);
    }

    protected function tearDown(): void
    {
        $_ENV['ADMIN_USERNAME'] = $this->previousUsername;
        $_ENV['ADMIN_PASSWORD_HASH'] = $this->previousHash;

        $ref = new ReflectionClass(Env::class);
        $prop = $ref->getProperty('loaded');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->previousEnvLoaded);
    }

    public function testCreateUserValidatesUsername(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $service = new UserService($repo);

        $result = $service->createUser([
            'username' => 'ab',
            'password' => 'password1',
            'password_confirm' => 'password1',
            'role' => User::ROLE_READER,
        ]);

        $this->assertArrayHasKey('errors', $result);
        $this->assertNotEmpty($result['errors']);
    }

    public function testCreateUserRejectsDuplicateUsername(): void
    {
        $existing = new User(1, 'dup', null, null, User::ROLE_READER, true, null, null, null);
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->with('dup')->willReturn($existing);
        $service = new UserService($repo);

        $result = $service->createUser([
            'username' => 'dup',
            'password' => 'password1',
            'password_confirm' => 'password1',
            'role' => User::ROLE_READER,
        ]);

        $this->assertArrayHasKey('errors', $result);
    }

    public function testDeleteLastAdminIsBlocked(): void
    {
        $admin = new User(1, 'solo', null, null, User::ROLE_ADMIN, true, null, null, null);
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->with(1)->willReturn($admin);
        $repo->method('countActiveByRole')->with(User::ROLE_ADMIN)->willReturn(1);
        $service = new UserService($repo);

        $result = $service->deleteUser(1, 2);

        $this->assertArrayHasKey('errors', $result);
    }

    public function testDeleteSelfIsBlocked(): void
    {
        $admin = new User(1, 'solo', null, null, User::ROLE_ADMIN, true, null, null, null);
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->with(1)->willReturn($admin);
        $service = new UserService($repo);

        $result = $service->deleteUser(1, 1);

        $this->assertArrayHasKey('errors', $result);
    }

    public function testEnsureLegacyAdminMaterializedCreatesAdminWhenAbsent(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('s3cret', PASSWORD_DEFAULT);

        $repo = $this->createMock(UserRepository::class);
        $repo->method('tableExists')->willReturn(true);
        $repo->method('findByUsername')->with('admin')->willReturn(null);
        $repo->expects($this->once())
            ->method('create')
            ->with(
                'admin',
                $_ENV['ADMIN_PASSWORD_HASH'],
                User::ROLE_ADMIN,
                null,
                'Administrateur (compte initial)',
                true,
            )
            ->willReturn(42);

        $service = new UserService($repo);
        $service->ensureLegacyAdminMaterialized();
    }

    public function testEnsureLegacyAdminMaterializedSkipsWhenAlreadyPresent(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('s3cret', PASSWORD_DEFAULT);

        $existing = new User(1, 'admin', null, null, User::ROLE_ADMIN, true, null, null, null);
        $repo = $this->createMock(UserRepository::class);
        $repo->method('tableExists')->willReturn(true);
        $repo->method('findByUsername')->with('admin')->willReturn($existing);
        $repo->expects($this->never())->method('create');

        $service = new UserService($repo);
        $service->ensureLegacyAdminMaterialized();
    }

    public function testEnsureLegacyAdminMaterializedSkipsWhenEnvMissing(): void
    {
        unset($_ENV['ADMIN_USERNAME'], $_ENV['ADMIN_PASSWORD_HASH']);

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->never())->method('tableExists');
        $repo->expects($this->never())->method('create');

        $service = new UserService($repo);
        $service->ensureLegacyAdminMaterialized();
    }
}
