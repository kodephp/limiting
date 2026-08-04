<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Concurrency\ProcessLimiter;
use PHPUnit\Framework\TestCase;

class ProcessLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        ProcessLimiter::flushInstances();
    }

    public function testTryAcquire(): void
    {
        $limiter = ProcessLimiter::getInstance(4, 'p:');
        $this->assertTrue($limiter->tryAcquire('process:1'));
    }

    public function testMaxProcessesEnforced(): void
    {
        $limiter = ProcessLimiter::getInstance(2, 'q:');
        $this->assertTrue($limiter->tryAcquire('process:1'));
        $this->assertTrue($limiter->tryAcquire('process:2'));
        $this->assertFalse($limiter->tryAcquire('process:3'));
        $this->assertEquals(2, $limiter->getMaxProcesses());
    }

    public function testRelease(): void
    {
        $limiter = ProcessLimiter::getInstance(4, 'r:');
        $limiter->tryAcquire('process:2');
        $this->assertTrue($limiter->isRunning('process:2'));
        $limiter->release('process:2');
        $this->assertFalse($limiter->isRunning('process:2'));
        $this->assertTrue($limiter->tryAcquire('process:2'));
    }

    public function testGetInstance(): void
    {
        $i1 = ProcessLimiter::getInstance(4, 's:');
        $i2 = ProcessLimiter::getInstance(4, 's:');
        $this->assertSame($i1, $i2);
        // 不同前缀 => 不同共享实例
        $i3 = ProcessLimiter::getInstance(4, 's2:');
        $this->assertNotSame($i1, $i3);
    }
}
