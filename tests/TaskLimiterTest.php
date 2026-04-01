<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Concurrency\TaskLimiter;
use PHPUnit\Framework\TestCase;

class TaskLimiterTest extends TestCase
{
    private TaskLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = TaskLimiter::create(5, 5, 1.0);
    }

    public function testTryAcquire(): void
    {
        $this->assertTrue($this->limiter->tryAcquire('task:1'));
    }

    public function testTryAcquireMultiple(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->limiter->tryAcquire('task:' . $i));
        }
    }

    public function testRelease(): void
    {
        $this->limiter->tryAcquire('task:2');
        $this->limiter->release('task:2');
        $this->assertTrue($this->limiter->tryAcquire('task:2'));
    }

    public function testRun(): void
    {
        $result = $this->limiter->run('task:3', fn() => 'done');
        $this->assertEquals('done', $result);
    }

    public function testGetMaxConcurrency(): void
    {
        $this->assertEquals(5, $this->limiter->getMaxConcurrency());
    }
}
