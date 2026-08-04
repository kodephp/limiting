<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Concurrency\TaskLimiter;
use PHPUnit\Framework\TestCase;

/**
 * TaskLimiter v2.0 行为验证：底层改为共享槽位计数信号量，
 * maxConcurrency 必须是真实生效的硬上限。
 */
class TaskLimiterTest extends TestCase
{
    private TaskLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = TaskLimiter::create(3);
    }

    public function testTryAcquire(): void
    {
        $this->assertTrue($this->limiter->tryAcquire('task:1'));
        $this->assertTrue($this->limiter->tryAcquire('task:2'));
    }

    public function testMaxConcurrencyEnforced(): void
    {
        $this->assertTrue($this->limiter->tryAcquire('task:1'));
        $this->assertTrue($this->limiter->tryAcquire('task:2'));
        $this->assertTrue($this->limiter->tryAcquire('task:3'));
        // 第 4 个超出 max=3，必须被拒绝
        $this->assertFalse($this->limiter->tryAcquire('task:4'));
        $this->assertEquals(3, $this->limiter->getActiveCount());
        $this->assertEquals(0, $this->limiter->getAvailable());
    }

    public function testRelease(): void
    {
        $this->limiter->tryAcquire('task:2');
        $this->assertTrue($this->limiter->isRunning('task:2'));
        $this->limiter->release('task:2');
        $this->assertFalse($this->limiter->isRunning('task:2'));
        $this->assertTrue($this->limiter->tryAcquire('task:2'));
    }

    public function testRun(): void
    {
        $result = $this->limiter->run('task:3', fn() => 'done');
        $this->assertEquals('done', $result);
        // run 结束后槽位自动释放
        $this->assertTrue($this->limiter->tryAcquire('task:3'));
    }

    public function testIdempotentReacquire(): void
    {
        $this->assertTrue($this->limiter->tryAcquire('task:1'));
        // 同一持有者重复获取为幂等成功，不额外占位
        $this->assertTrue($this->limiter->tryAcquire('task:1'));
        $this->assertEquals(1, $this->limiter->getActiveCount());
    }

    public function testGetMaxConcurrency(): void
    {
        $this->assertEquals(3, $this->limiter->getMaxConcurrency());
    }
}
