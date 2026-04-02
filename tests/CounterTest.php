<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Algorithm\Counter;
use Kode\Limiting\Store\MemoryStore;
use PHPUnit\Framework\TestCase;

class CounterTest extends TestCase
{
    private MemoryStore $store;
    private Counter $counter;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->counter = new Counter($this->store, 100, 60);
    }

    public function testAllowWithinLimit(): void
    {
        $this->assertTrue($this->counter->allow('api:1', 50));
    }

    public function testAllowExceedLimit(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($this->counter->allow('api:2', 1));
        }
        $this->assertFalse($this->counter->allow('api:2', 1));
    }

    public function testGetCount(): void
    {
        $this->counter->allow('api:3', 10);
        $this->assertEquals(10, $this->counter->getCount('api:3'));
    }

    public function testGetRemaining(): void
    {
        $this->counter->allow('api:4', 30);
        $remaining = $this->counter->getRemaining('api:4');
        $this->assertEquals(70, $remaining);
    }

    public function testReset(): void
    {
        $this->counter->allow('api:5', 50);
        $this->counter->reset('api:5');
        $this->assertEquals(100, $this->counter->getRemaining('api:5'));
    }

    public function testWaitTimeWhenAvailable(): void
    {
        $waitTime = $this->counter->getWaitTime('api:6');
        $this->assertEquals(0.0, $waitTime);
    }
}
