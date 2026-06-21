<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Domain\User;
use App\Repository\UserRepository;
use App\Service\UserService;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
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

    public function testUpdateUserRejectsInvalidPasswordBeforePersistingRole(): void
    {
        $existing = new User(2, 'operator', null, null, User::ROLE_OPERATOR, true, null, null, null);
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->with(2)->willReturn($existing);
        $repo->expects($this->never())->method('update');
        $repo->expects($this->never())->method('updatePassword');
        $service = new UserService($repo);

        $result = $service->updateUser(2, [
            'email' => '',
            'display_name' => '',
            'role' => User::ROLE_READER,
            'is_active' => 'on',
            'password' => 'court',
            'password_confirm' => 'court',
        ]);

        $this->assertArrayHasKey('errors', $result);
        $this->assertSame(['Le mot de passe doit contenir au moins 8 caractères.'], $result['errors']);
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
}
