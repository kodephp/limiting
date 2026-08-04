<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Algorithm\SlidingWindowCounter;
use Kode\Limiting\Store\MemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * 滑动窗口计数器（加权近似）单元测试。
 */
class SlidingWindowCounterTest extends TestCase
{
    public function testAllowUpToCapacity(): void
    {
        $swc = new SlidingWindowCounter(new MemoryStore(), 5, 60, 'sw:');

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($swc->allow('user:1'), "第 {$i} 次应放行");
        }
        $this->assertFalse($swc->allow('user:1'));
    }

    public function testRemainingReflectsUsage(): void
    {
        $swc = new SlidingWindowCounter(new MemoryStore(), 10, 60, 'sw:');
        $swc->allow('user:2', 4);

        $this->assertEqualsWithDelta(6.0, $swc->getRemaining('user:2'), 0.5);
    }

    public function testResetClearsCounter(): void
    {
        $swc = new SlidingWindowCounter(new MemoryStore(), 10, 60, 'sw:');
        $swc->allow('user:3', 5);
        $swc->reset('user:3');

        $this->assertEqualsWithDelta(10.0, $swc->getRemaining('user:3'), 0.01);
    }

    public function testEstimateIsWeighted(): void
    {
        $swc = new SlidingWindowCounter(new MemoryStore(), 100, 60, 'sw:');
        $swc->allow('user:4', 30);

        $est = $swc->estimate('user:4');
        // 窗口起始处上一窗口计数为 0，加权估算应等于当前窗口计数
        $this->assertEqualsWithDelta(30.0, $est, 1e-6);
    }

    public function testInvalidWindowRejected(): void
    {
        $this->expectException(\Kode\Limiting\Exception\InvalidConfigurationException::class);
        new SlidingWindowCounter(new MemoryStore(), 10, 0, 'sw:');
    }
}
