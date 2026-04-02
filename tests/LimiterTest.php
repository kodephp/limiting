<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Limiter;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Enum\StoreType;
use PHPUnit\Framework\TestCase;

class LimiterTest extends TestCase
{
    public function testTokenBucket(): void
    {
        $limiter = Limiter::tokenBucket(5, 1.0);
        $this->assertTrue($limiter->allow('test:1'));
        $this->assertTrue($limiter->allow('test:2'));
        $this->assertIsFloat($limiter->getRemaining('test:1'));
    }

    public function testSlidingWindow(): void
    {
        $limiter = Limiter::slidingWindow(10, 60.0);
        $this->assertTrue($limiter->allow('sw:1'));
        $this->assertIsFloat($limiter->getRemaining('sw:1'));
    }

    public function testLeakyBucket(): void
    {
        $limiter = Limiter::leakyBucket(10, 1.0);
        $this->assertTrue($limiter->allow('leaky:1'));
    }

    public function testCounter(): void
    {
        $limiter = Limiter::counter(100, 60);
        $this->assertTrue($limiter->allow('counter:1'));
    }

    public function testBuild(): void
    {
        $limiter = Limiter::tokenBucket(5, 1.0);
        $this->assertNotNull($limiter->build());
    }

    public function testGetStore(): void
    {
        $limiter = Limiter::tokenBucket(5, 1.0);
        $store = $limiter->getStore();
        $this->assertNotNull($store);
    }

    public function testGetConfig(): void
    {
        $limiter = Limiter::tokenBucket(5, 1.0);
        $config = $limiter->getConfig();
        $this->assertEquals(5, $config->capacity);
        $this->assertEquals(1.0, $config->refillRate);
    }

    public function testConstructor(): void
    {
        $limiter = new Limiter(StoreType::MEMORY, LimiterType::TOKEN_BUCKET);
        $this->assertTrue($limiter->allow('ctor:1'));
    }

    public function testTask(): void
    {
        $taskLimiter = Limiter::task(5);
        $this->assertInstanceOf(\Kode\Limiting\Concurrency\TaskLimiter::class, $taskLimiter);
    }

    public function testProcess(): void
    {
        $processLimiter = Limiter::process(5);
        $this->assertInstanceOf(\Kode\Limiting\Concurrency\ProcessLimiter::class, $processLimiter);
    }

    public function testFiber(): void
    {
        $fiberLimiter = Limiter::fiber(5);
        $this->assertInstanceOf(\Kode\Limiting\Concurrency\FiberLimiter::class, $fiberLimiter);
    }

    public function testMiddleware(): void
    {
        $middleware = Limiter::middleware(LimiterType::TOKEN_BUCKET, 10, 1.0);
        $this->assertInstanceOf(\Kode\Limiting\Middleware\LimiterMiddleware::class, $middleware);
    }

    public function testReset(): void
    {
        $limiter = Limiter::tokenBucket(5, 1.0);
        $limiter->allow('reset:1');
        $limiter->reset('reset:1');
        $this->assertIsFloat($limiter->getRemaining('reset:1'));
    }
}
