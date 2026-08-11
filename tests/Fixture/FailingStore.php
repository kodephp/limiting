<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests\Fixture;

use Kode\Limiting\Exception\StoreException;
use Kode\Limiting\Store\StoreInterface;

/**
 * 测试用：任何读写都抛出 StoreException 的存储
 */
final class FailingStore implements StoreInterface
{
    public function get(string $key): ?string
    {
        throw new StoreException('FailingStore.get');
    }

    public function set(string $key, string $value, int $ttl = 0): void
    {
        throw new StoreException('FailingStore.set');
    }

    public function delete(string $key): void
    {
        throw new StoreException('FailingStore.delete');
    }

    public function incr(string $key, int $step = 1, int $ttl = 0): int
    {
        throw new StoreException('FailingStore.incr');
    }

    public function decr(string $key, int $step = 1): int
    {
        throw new StoreException('FailingStore.decr');
    }

    public function ttl(string $key): int
    {
        throw new StoreException('FailingStore.ttl');
    }

    public function has(string $key): bool
    {
        throw new StoreException('FailingStore.has');
    }
}
