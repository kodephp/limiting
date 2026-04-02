<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\Store\StoreInterface;

/**
 * 计数器限流器（简单固定窗口）
 *
 * 特点：实现简单，适合固定速率限制
 * 在固定时间窗口内允许固定数量的请求
 */
class Counter implements RateLimiterInterface
{
    private readonly string $counterPrefix;

    public function __construct(
        private readonly StoreInterface $store,
        private readonly int $limit,
        private readonly int $windowSeconds = 60,
        private readonly string $prefix = 'counter:'
    ) {
        $this->counterPrefix = $this->prefix;
    }

    /**
     * 检查是否允许请求
     */
    public function allow(string $key, int $tokens = 1): bool
    {
        $now = time();
        $windowKey = $this->getWindowKey($key, $now);

        $count = (int) $this->store->get($windowKey);

        if ($count + $tokens > $this->limit) {
            return false;
        }

        $this->store->incr($windowKey, $tokens);
        $this->store->set($windowKey, (string) ((int) $this->store->get($windowKey)), $this->windowSeconds);

        return true;
    }

    /**
     * 获取当前窗口的请求数
     */
    public function getCount(string $key): int
    {
        $now = time();
        $windowKey = $this->getWindowKey($key, $now);
        return (int) ($this->store->get($windowKey) ?? 0);
    }

    public function getRemaining(string $key): float
    {
        return (float) max(0, $this->limit - $this->getCount($key));
    }

    public function getWaitTime(string $key): float
    {
        $remaining = $this->getRemaining($key);

        if ($remaining > 0) {
            return 0.0;
        }

        $now = time();
        $windowKey = $this->getWindowKey($key, $now);

        $lastUpdate = $this->store->ttl($windowKey);

        if ($lastUpdate > 0) {
            return (float) $lastUpdate;
        }

        return (float) $this->windowSeconds;
    }

    public function reset(string $key): void
    {
        $now = time();
        $windowKey = $this->getWindowKey($key, $now);
        $this->store->delete($windowKey);
    }

    private function getWindowKey(string $key, int $timestamp): string
    {
        $window = (int) floor($timestamp / $this->windowSeconds);
        return $this->counterPrefix . $key . ':' . $window;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }
}
