<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Concurrency\FiberLimiter;
use Kode\Limiting\Store\MemoryStore;
use PHPUnit\Framework\TestCase;

class FiberLimiterTest extends TestCase
{
    private FiberLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new FiberLimiter(3, new MemoryStore(), 'fiber:');
    }

    public function testIsSupported(): void
    {
        $this->assertIsBool(FiberLimiter::isSupported());
        $this->assertTrue(FiberLimiter::isSupported()); // PHP 8.1+
    }

    public function testTryAcquire(): void
    {
        $this->assertTrue($this->limiter->tryAcquire('fiber:1'));
    }

    public function testMaxFibersEnforced(): void
    {
        $this->assertTrue($this->limiter->tryAcquire('fiber:1'));
        $this->assertTrue($this->limiter->tryAcquire('fiber:2'));
        $this->assertTrue($this->limiter->tryAcquire('fiber:3'));
        $this->assertFalse($this->limiter->tryAcquire('fiber:4'));
    }

    public function testRelease(): void
    {
        $this->limiter->tryAcquire('fiber:2');
        $this->assertTrue($this->limiter->isRunning('fiber:2'));
        $this->limiter->release('fiber:2');
        $this->assertFalse($this->limiter->isRunning('fiber:2'));
        $this->assertTrue($this->limiter->tryAcquire('fiber:2'));
    }

    public function testGetMaxFibers(): void
    {
        $this->assertEquals(3, $this->limiter->getMaxFibers());
    }

    public function testStartAcquiresAndReleases(): void
    {
        $fiber = $this->limiter->start('fiber:5', fn() => null);
        $this->assertInstanceOf(\Fiber::class, $fiber);
        // start() 执行完回调后 finally 块自动释放槽位
        $this->assertTrue($this->limiter->tryAcquire('fiber:5'));
    }
}
