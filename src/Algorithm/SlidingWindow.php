<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Store\StoreInterface;

/**
 * 滑动窗口限流算法
 *
 * 基于时间窗口的精确限流，适合需要平滑限流场景
 * 使用 PHP 8.2 readonly 属性优化性能
 */
class SlidingWindow implements RateLimiterInterface
{
    private readonly string $windowPrefix;
    private readonly string $countPrefix;

    public function __construct(
        private readonly StoreInterface $store,
        private readonly int $capacity,
        private readonly float $windowSize = 1.0,
        private readonly int $ttl = 3600,
        private readonly string $prefix = 'sw:'
    ) {
        $this->windowPrefix = $this->prefix . 'window:';
        $this->countPrefix = $this->prefix . 'count:';
    }

    public static function fromConfig(StoreInterface $store, LimiterConfig $config): self
    {
        return new self(
            $store,
            $config->capacity,
            $config->refillRate,
            $config->ttl,
            $config->prefix
        );
    }

    public function allow(string $key, int $tokens = 1): bool
    {
        $now = microtime(true);
        $windowKey = $this->windowPrefix . $key;
        $countKey = $this->countPrefix . $key;

        $currentCount = (int) ($this->store->get($countKey) ?? 0);

        if ($currentCount + $tokens > $this->capacity) {
            return false;
        }

        for ($i = 0; $i < $tokens; $i++) {
            $timestamp = (int) ($now * 1000) + $i;
            $this->store->set(
                $windowKey . ':' . $timestamp,
                (string) $now,
                (int) ($this->windowSize * 2)
            );
        }

        $this->store->set($countKey, (string) ($currentCount + $tokens), (int) ($this->windowSize * 2));

        return true;
    }

    public function check(string $key, int $tokens = 1): LimiterResult
    {
        $remaining = $this->getRemaining($key);

        if ($remaining >= $tokens) {
            return LimiterResult::allowed($remaining - $tokens);
        }

        return LimiterResult::denied($this->getWaitTime($key));
    }

    public function getRemaining(string $key): float
    {
        $countKey = $this->countPrefix . $key;
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
        $this->store->delete($this->windowPrefix . $key);
        $this->store->delete($this->countPrefix . $key);
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
