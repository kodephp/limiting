<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Algorithm\LeakyBucket;
use Kode\Limiting\Store\MemoryStore;
use PHPUnit\Framework\TestCase;

class LeakyBucketTest extends TestCase
{
    private MemoryStore $store;
    private LeakyBucket $bucket;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->bucket = new LeakyBucket($this->store, 100, 10.0);
    }

    public function testAllowFirstRequest(): void
    {
        $this->assertTrue($this->bucket->allow('user:1', 1));
    }

    public function testAllowMultipleRequests(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($this->bucket->allow('user:2', 1));
        }
    }

    public function testAllowExceedCapacity(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->bucket->allow('user:3', 1);
        }
        $this->assertFalse($this->bucket->allow('user:3', 1));
    }

    public function testGetRemaining(): void
    {
        $this->bucket->allow('user:4', 30);
        $remaining = $this->bucket->getRemaining('user:4');
        $this->assertEqualsWithDelta(70.0, $remaining, 1.0);
    }

    public function testGetWaitTime(): void
    {
        $this->bucket->allow('user:5', 100);
        $waitTime = $this->bucket->getWaitTime('user:5');
        $this->assertGreaterThan(0.0, $waitTime);
    }

    public function testReset(): void
    {
        $this->bucket->allow('user:6', 50);
        $this->bucket->reset('user:6');
        $this->assertEquals(100.0, $this->bucket->getRemaining('user:6'));
    }

    public function testLeakOverTime(): void
    {
        $this->bucket->allow('user:7', 50);
        $before = $this->bucket->getRemaining('user:7');

        usleep(200000);

        $after = $this->bucket->getRemaining('user:7');
        $this->assertGreaterThan($before, $after);
    }
}
