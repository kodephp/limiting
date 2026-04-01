<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Concurrency\FiberLimiter;
use PHPUnit\Framework\TestCase;

class FiberLimiterTest extends TestCase
{
    private FiberLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new FiberLimiter(10, 10, 1.0);
    }

    public function testIsSupported(): void
    {
        $this->assertIsBool(FiberLimiter::isSupported());
    }

    public function testTryAcquire(): void
    {
        $this->assertTrue($this->limiter->tryAcquire('fiber:1'));
    }

    public function testRelease(): void
    {
        $this->limiter->tryAcquire('fiber:2');
        $this->limiter->release('fiber:2');
        $this->assertTrue($this->limiter->tryAcquire('fiber:2'));
    }

    public function testGetActiveCount(): void
    {
        $this->limiter->tryAcquire('fiber:3');
        $this->limiter->tryAcquire('fiber:4');
        $this->assertEquals(2, $this->limiter->getActiveCount());
    }

    public function testGetMaxFibers(): void
    {
        $this->assertEquals(10, $this->limiter->getMaxFibers());
    }
}
