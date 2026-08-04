<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Concurrency\Semaphore;
use Kode\Limiting\Store\MemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * 计数信号量（并发限流器公共内核）单元测试。
 */
class SemaphoreTest extends TestCase
{
    public function testAcquireUpToMax(): void
    {
        $sem = new Semaphore(2, new MemoryStore(), 's:');

        $this->assertTrue($sem->tryAcquire('a'));
        $this->assertTrue($sem->tryAcquire('b'));
        $this->assertFalse($sem->tryAcquire('c'));
        $this->assertEquals(2, $sem->getActiveCount());
        $this->assertEquals(0, $sem->getAvailable());
    }

    public function testReleaseFreesSlot(): void
    {
        $sem = new Semaphore(2, new MemoryStore(), 's:');

        $sem->tryAcquire('a');
        $sem->release('a');
        $this->assertFalse($sem->isHeldBy('a'));
        $this->assertTrue($sem->tryAcquire('c'));
    }

    public function testIdempotentReacquire(): void
    {
        $sem = new Semaphore(2, new MemoryStore(), 's:');

        $this->assertTrue($sem->tryAcquire('a'));
        $this->assertTrue($sem->tryAcquire('a'));
        $this->assertEquals(1, $sem->getActiveCount());
    }

    public function testRunReleasesOnReturn(): void
    {
        $sem = new Semaphore(1, new MemoryStore(), 's:');

        $out = $sem->run('a', fn() => 42);
        $this->assertEquals(42, $out);
        $this->assertFalse($sem->isHeldBy('a'));
    }

    public function testRunReleasesOnException(): void
    {
        $sem = new Semaphore(1, new MemoryStore(), 's:');

        try {
            $sem->run('a', static fn() => throw new \RuntimeException('boom'));
        } catch (\RuntimeException $e) {
            $this->assertEquals('boom', $e->getMessage());
        }

        $this->assertFalse($sem->isHeldBy('a'));
    }

    public function testEmptyHolderRejected(): void
    {
        $sem = new Semaphore(1, new MemoryStore(), 's:');

        $this->expectException(\Kode\Limiting\Exception\InvalidConfigurationException::class);
        $sem->tryAcquire('');
    }

    public function testInvalidMaxSlots(): void
    {
        $this->expectException(\Kode\Limiting\Exception\InvalidConfigurationException::class);
        new Semaphore(0, new MemoryStore(), 's:');
    }
}
