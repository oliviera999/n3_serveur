<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\RealtimeUrlHelper;
use PHPUnit\Framework\TestCase;

final class RealtimeUrlHelperTest extends TestCase
{
    public function testEnergieRealtimeApiBase(): void
    {
        $this->assertSame('/energie-test/api/realtime', RealtimeUrlHelper::getEnergieRealtimeApiBase('energie_test'));
        $this->assertSame('/energie/api/realtime', RealtimeUrlHelper::getEnergieRealtimeApiBase('prod'));
        $this->assertSame('/energie-test/api/realtime', RealtimeUrlHelper::getModuleRealtimeApiBase('energie', 'energie_test'));
    }

    public function testExistingFamiliesAreUnchanged(): void
    {
        $this->assertSame('/msp1-test/api/realtime', RealtimeUrlHelper::getModuleRealtimeApiBase('msp1', 'msp_test'));
        $this->assertSame('/n3pp/api/realtime', RealtimeUrlHelper::getModuleRealtimeApiBase('n3pp', 'prod'));
        $this->assertSame('/api/realtime-test', RealtimeUrlHelper::getModuleRealtimeApiBase('ffp3', 'test'));
    }
}
