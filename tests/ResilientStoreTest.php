<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Enum\FailurePolicy;
use Kode\Limiting\Exception\StoreException;
use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\ResilientStore;
use Kode\Limiting\Tests\Fixture\FailingStore;
use PHPUnit\Framework\TestCase;

/**
 * ResilientStore 容灾装饰器测试
 */
final class ResilientStoreTest extends TestCase
{
    public function testFailOpenReturnsSafeFallbacks(): void
    {
        $store = new ResilientStore(new FailingStore(), FailurePolicy::ALLOW);

        self::assertNull($store->get('k'));
        self::assertFalse($store->has('k'));
        self::assertSame(-2, $store->ttl('k'));
        self::assertSame(1, $store->incr('k', 1));   // 退化为 step
        self::assertSame(-1, $store->decr('k', 1));  // 退化为 -step

        // set / delete 不应抛异常
        $store->set('k', 'v');
        $store->delete('k');

        self::assertTrue($store->isDegraded());
        self::assertNotNull($store->getDegradationReason());
        self::assertFalse($store->isHealthy());
    }

    public function testFailOpenMarksDegradedAndClears(): void
    {
        $store = new ResilientStore(new FailingStore());

        $store->get('k');
        self::assertTrue($store->isDegraded());

        $store->clearDegradation();
        self::assertFalse($store->isDegraded());
        self::assertNull($store->getDegradationReason());
    }

    public function testRethrowPolicyPropagatesException(): void
    {
        $store = new ResilientStore(new FailingStore(), FailurePolicy::RETHROW);

        $this->expectException(StoreException::class);
        $store->get('k');
    }

    public function testHealthyPassthrough(): void
    {
        $inner = new MemoryStore();
        $store = new ResilientStore($inner);

        $store->set('k', 'v', 60);
        self::assertSame('v', $store->get('k'));
        self::assertFalse($store->isDegraded());
        self::assertSame($inner, $store->getInnerStore());
    }
}
