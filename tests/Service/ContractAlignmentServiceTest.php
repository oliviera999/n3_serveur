<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Contract\ContractAlignmentService;
use App\Service\Contract\ContractLoader;
use PHPUnit\Framework\TestCase;

final class ContractAlignmentServiceTest extends TestCase
{
    private ContractAlignmentService $service;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->service = new ContractAlignmentService(
            new ContractLoader($root . DIRECTORY_SEPARATOR . 'contracts'),
            $root
        );
    }

    public function testContractsDirectoryContainsExpectedFamilies(): void
    {
        $loader = new ContractLoader(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'contracts');
        $ids = array_map(static fn (array $c): string => (string) $c['id'], $loader->loadAll());
        sort($ids);
        $this->assertSame(['energie', 'ffp3', 'msp1', 'n3pp', 'pgl'], $ids);
    }

    public function testMsp1ContractIsAlignedWithControllerAndGpioMap(): void
    {
        $result = $this->service->checkById('msp1');
        $this->assertNotNull($result);
        $this->assertSame('msp1', $result['id']);
        $this->assertSame([], $result['fields']['missing_in_code']);
        $this->assertSame([], $result['gpio']['missing_in_code']);
        $this->assertSame([], $result['gpio']['mismatches']);
        $this->assertContains($result['status'], ['ok', 'warn']);
    }

    public function testN3ppContractIsAlignedWithControllerAndGpioMap(): void
    {
        $result = $this->service->checkById('n3pp');
        $this->assertNotNull($result);
        $this->assertSame([], $result['fields']['missing_in_code']);
        $this->assertSame([], $result['gpio']['mismatches']);
        $this->assertContains($result['status'], ['ok', 'warn']);
    }

    public function testEnergieContractIsAlignedWithController(): void
    {
        $result = $this->service->checkById('energie');
        $this->assertNotNull($result);
        $this->assertSame([], $result['fields']['missing_in_code']);
        $this->assertContains($result['status'], ['ok', 'warn']);
    }

    public function testFfp3ContractIsAlignedWithControllerAndGpioMap(): void
    {
        $result = $this->service->checkById('ffp3');
        $this->assertNotNull($result);
        $this->assertSame([], $result['fields']['missing_in_code'], implode('; ', $result['issues'] ?? []));
        $this->assertSame([], $result['gpio']['mismatches'], implode('; ', $result['gpio']['mismatches'] ?? []));
        $this->assertContains($result['status'], ['ok', 'warn']);
    }

    public function testCheckAllReturnsFiveFamilies(): void
    {
        $all = $this->service->checkAll();
        $this->assertCount(5, $all);
        foreach ($all as $row) {
            $this->assertArrayHasKey('status', $row);
            $this->assertArrayHasKey('fields', $row);
        }
    }

    public function testUnknownContractReturnsNull(): void
    {
        $this->assertNull($this->service->checkById('does-not-exist'));
    }
}
