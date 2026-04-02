<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\Store\StoreInterface;

/**
 * 计数器限流器（简单固定窗口）
 *
 * 特点：实现简单，适合固定速率限制
 * 在固定时间窗口内允许固定数量的请求
 *
 * 与滑动窗口的区别：
 * - 滑动窗口：精确控制，窗口内请求均匀分布
 * - 计数器：实现简单，窗口边界可能出现突刺
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
     *
     * @param string $key 限流键
     * @param int $tokens 请求的令牌数
     * @return bool
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
     *
     * @param string $key 限流键
     * @return int
     */
    public function getCount(string $key): int
    {
        $now = time();
        $windowKey = $this->getWindowKey($key, $now);
        return (int) ($this->store->get($windowKey) ?? 0);
    }

    /**
     * 获取剩余请求数
     *
     * @param string $key 限流键
     * @return float
     */
    public function getRemaining(string $key): float
    {
        return (float) max(0, $this->limit - $this->getCount($key));
    }

    /**
     * 获取等待时间
     *
     * @param string $key 限流键
     * @return float
     */
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

    /**
     * 重置限流器
     *
     * @param string $key 限流键
     */
    public function reset(string $key): void
    {
        $now = time();
        $windowKey = $this->getWindowKey($key, $now);
        $this->store->delete($windowKey);
    }

    /**
     * 计算窗口键
     *
     * @param string $key 限流键
     * @param int $timestamp 时间戳
     * @return string
     */
    private function getWindowKey(string $key, int $timestamp): string
    {
        $window = (int) floor($timestamp / $this->windowSeconds);
        return $this->counterPrefix . $key . ':' . $window;
    }

    /**
     * 获取限制数
     *
     * @return int
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * 获取窗口大小（秒）
     *
     * @return int
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }
}
