<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\User;
use App\Security\RoleAccessService;
use PHPUnit\Framework\TestCase;

final class RoleAccessServiceTest extends TestCase
{
    private RoleAccessService $service;

    protected function setUp(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/routes_config.php';
        $this->service = new RoleAccessService($config);
    }

    public function testReaderCanAccessDashboard(): void
    {
        $this->assertTrue($this->service->hasAccess('/dashboard', User::ROLE_READER));
        $this->assertSame(User::ROLE_READER, $this->service->getMinimumRoleForPath('/dashboard'));
    }

    public function testReaderCannotAccessControl(): void
    {
        $this->assertFalse($this->service->hasAccess('/aquaponie-control', User::ROLE_READER));
        $this->assertSame(User::ROLE_OPERATOR, $this->service->getMinimumRoleForPath('/aquaponie-control'));
    }

    public function testOperatorCanAccessSupervision(): void
    {
        $this->assertTrue($this->service->hasAccess('/supervision', User::ROLE_OPERATOR));
    }

    public function testOperatorCannotAccessUserManagement(): void
    {
        $this->assertFalse($this->service->hasAccess('/admin/users', User::ROLE_OPERATOR));
        $this->assertSame(User::ROLE_ADMIN, $this->service->getMinimumRoleForPath('/admin/users'));
    }

    public function testAdminCanAccessUserManagement(): void
    {
        $this->assertTrue($this->service->hasAccess('/admin/users', User::ROLE_ADMIN));
    }

    public function testPublicPathHasNoRoleRequirement(): void
    {
        $this->assertNull($this->service->getMinimumRoleForPath('/aquaponie'));
        $this->assertTrue($this->service->hasAccess('/aquaponie', null));
    }
}
