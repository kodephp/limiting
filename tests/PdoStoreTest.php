<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Store\PdoStore;
use PHPUnit\Framework\TestCase;

class PdoStoreTest extends TestCase
{
    private PdoStore $store;

    protected function setUp(): void
    {
        $this->store = PdoStore::createSqlite(':memory:');
    }

    public function testSetAndGet(): void
    {
        $this->store->set('key1', 'value1', 3600);
        $this->assertEquals('value1', $this->store->get('key1'));
    }

    public function testGetNonExistent(): void
    {
        $this->assertNull($this->store->get('non_existent'));
    }

    public function testDelete(): void
    {
        $this->store->set('key2', 'value2', 3600);
        $this->store->delete('key2');
        $this->assertNull($this->store->get('key2'));
    }

    public function testIncr(): void
    {
        $this->store->set('counter', '0', 3600);
        $this->assertEquals(1, $this->store->incr('counter'));
        $this->assertEquals(5, $this->store->incr('counter', 4));
    }

    public function testTtl(): void
    {
        $this->store->set('key3', 'value3', 100);
        $ttl = $this->store->ttl('key3');
        $this->assertGreaterThan(90, $ttl);
        $this->assertLessThanOrEqual(100, $ttl);
    }

    public function testIsHealthy(): void
    {
        $this->assertTrue($this->store->isHealthy());
    }
}
