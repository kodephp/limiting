<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\Store\StoreInterface;

/**
 * 滑动窗口限流算法
 *
 * 基于时间窗口的精确限流，适合需要平滑限流场景
 */
class SlidingWindow implements RateLimiterInterface
{
    private StoreInterface $store;
    private int $capacity;
    private float $windowSize;
    private int $ttl;
    private string $prefix;

    public function __construct(
        StoreInterface $store,
        int $capacity,
        float $windowSize = 1.0,
        int $ttl = 3600,
        string $prefix = 'sw:'
    ) {
        $this->store = $store;
        $this->capacity = $capacity;
        $this->windowSize = $windowSize;
        $this->ttl = $ttl;
        $this->prefix = $prefix;
    }

    public function allow(string $key, int $tokens = 1): bool
    {
        $now = microtime(true);
        $windowKey = $this->prefix . 'window:' . $key;
        $countKey = $this->prefix . 'count:' . $key;

        $currentCount = (int) ($this->store->get($countKey) ?? 0);

        if ($currentCount + $tokens > $this->capacity) {
            return false;
        }

        for ($i = 0; $i < $tokens; $i++) {
            $timestamp = (int) ($now * 1000000) + $i;
            $this->store->set(
                $windowKey . ':' . $timestamp,
                (string) $now,
                (int) ($this->windowSize * 2)
            );
        }

        $this->store->set($countKey, (string) ($currentCount + $tokens), (int) ($this->windowSize * 2));

        return true;
    }

    public function getRemaining(string $key): float
    {
        $countKey = $this->prefix . 'count:' . $key;
        $count = (int) ($this->store->get($countKey) ?? 0);
        return (float) max(0, $this->capacity - $count);
    }

    public function getWaitTime(string $key): float
    {
        if ($this->getRemaining($key) > 0) {
            return 0.0;
        }
        return $this->windowSize;
    }

    public function reset(string $key): void
    {
        $windowKey = $this->prefix . 'window:' . $key;
        $countKey = $this->prefix . 'count:' . $key;
        $this->store->delete($windowKey);
        $this->store->delete($countKey);
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getWindowSize(): float
    {
        return $this->windowSize;
    }
}
