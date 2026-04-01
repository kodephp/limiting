<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Concurrency\ProcessLimiter;
use PHPUnit\Framework\TestCase;

class ProcessLimiterTest extends TestCase
{
    private ProcessLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new ProcessLimiter(10, 10, 1.0);
    }

    public function testTryAcquire(): void
    {
        $this->assertTrue($this->limiter->tryAcquire('process:1'));
    }

    public function testRelease(): void
    {
        $this->limiter->tryAcquire('process:2');
        $this->limiter->release('process:2');
        $this->assertTrue($this->limiter->tryAcquire('process:2'));
    }

    public function testGetActiveCount(): void
    {
        $this->limiter->tryAcquire('process:3');
        $this->limiter->tryAcquire('process:4');
        $this->assertEquals(2, $this->limiter->getActiveCount());
    }

    public function testGetMaxProcesses(): void
    {
        $this->assertEquals(10, $this->limiter->getMaxProcesses());
    }

    public function testGetInstance(): void
    {
        $instance1 = ProcessLimiter::getInstance();
        $instance2 = ProcessLimiter::getInstance();
        $this->assertSame($instance1, $instance2);
    }
}
