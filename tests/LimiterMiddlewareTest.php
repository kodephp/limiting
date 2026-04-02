<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Middleware\LimiterMiddleware;
use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Store\MemoryStore;
use PHPUnit\Framework\TestCase;

class LimiterMiddlewareTest extends TestCase
{
    private LimiterMiddleware $middleware;

    protected function setUp(): void
    {
        $store = new MemoryStore();
        $limiter = new TokenBucket($store, 100, 10.0);
        $this->middleware = new LimiterMiddleware($limiter, 'test');
    }

    public function testIsLimited(): void
    {
        $this->assertFalse($this->middleware->isLimited('user:1'));
    }

    public function testGetLimiterInfo(): void
    {
        $info = $this->middleware->getLimiterInfo('user:2');
        $this->assertArrayHasKey('allowed', $info);
        $this->assertArrayHasKey('remaining', $info);
        $this->assertArrayHasKey('wait_time', $info);
    }

    public function testGetName(): void
    {
        $this->assertEquals('test', $this->middleware->getName());
    }

    public function testGetCapacity(): void
    {
        $this->assertEquals(100, $this->middleware->getCapacity());
    }
}
