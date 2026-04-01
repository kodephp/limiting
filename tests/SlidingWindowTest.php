<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Algorithm\SlidingWindow;
use Kode\Limiting\Store\MemoryStore;
use PHPUnit\Framework\TestCase;

class SlidingWindowTest extends TestCase
{
    private MemoryStore $store;
    private SlidingWindow $window;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->window = new SlidingWindow($this->store, 100, 1.0);
    }

    public function testAllowWithinCapacity(): void
    {
        $this->assertTrue($this->window->allow('api:1', 50));
    }

    public function testAllowExceedCapacity(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($this->window->allow('api:2', 1));
        }
        $this->assertFalse($this->window->allow('api:2', 1));
    }

    public function testGetRemaining(): void
    {
        $this->window->allow('api:4', 30);
        $remaining = $this->window->getRemaining('api:4');
        $this->assertEqualsWithDelta(70.0, $remaining, 1.0);
    }

    public function testGetRemainingAfterRequests(): void
    {
        $this->window->allow('api:5', 10);
        $remaining = $this->window->getRemaining('api:5');
        $this->assertGreaterThanOrEqual(89.0, $remaining);
    }

    public function testReset(): void
    {
        $this->window->allow('api:6', 50);
        $this->window->reset('api:6');
        $remaining = $this->window->getRemaining('api:6');
        $this->assertEqualsWithDelta(100.0, $remaining, 1.0);
    }

    public function testWaitTimeWhenAvailable(): void
    {
        $waitTime = $this->window->getWaitTime('api:7');
        $this->assertEquals(0.0, $waitTime);
    }

    public function testWaitTimeWhenExhausted(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->window->allow('api:8', 1);
        }
        $waitTime = $this->window->getWaitTime('api:8');
        $this->assertGreaterThan(0.0, $waitTime);
    }
}
