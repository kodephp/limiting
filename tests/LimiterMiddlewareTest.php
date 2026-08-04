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
        // v2.0: getLimiterInfo 改为只读 check() 输出，键为 retry_after（非旧版的 wait_time）
        $this->assertArrayHasKey('retry_after', $info);
        $this->assertArrayHasKey('limit', $info);
    }

    public function testGetName(): void
    {
        $this->assertEquals('test', $this->middleware->getName());
    }

    public function testGetCapacity(): void
    {
        $this->assertEquals(100, $this->middleware->getCapacity());
    }

    public function testHandleInvokesNextWhenAllowed(): void
    {
        $called = false;
        $result = $this->middleware->handle('user:3', function () use (&$called) {
            $called = true;

            return 'ok';
        });
        $this->assertTrue($called);
        $this->assertEquals('ok', $result);
    }

    public function testGetHeaders(): void
    {
        $headers = $this->middleware->getHeaders('user:4');
        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
    }
}
