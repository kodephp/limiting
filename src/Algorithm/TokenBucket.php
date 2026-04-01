<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\Store\StoreInterface;

/**
 * 令牌桶限流算法
 *
 * 支持突发流量，按固定速率补充令牌
 */
class TokenBucket implements RateLimiterInterface
{
    private StoreInterface $store;
    private int $capacity;
    private float $refillRate;
    private int $ttl;

    public function __construct(
        StoreInterface $store,
        int $capacity,
        float $refillRate,
        int $ttl = 3600
    ) {
        $this->store = $store;
        $this->capacity = $capacity;
        $this->refillRate = $refillRate;
        $this->ttl = $ttl;
    }

    /**
     * 检查是否允许请求
     *
     * @param string $key 限流键
     * @param int $tokens 消耗令牌数
     * @return bool
     */
    public function allow(string $key, int $tokens = 1): bool
    {
        $now = microtime(true);
        $bucketKey = 'bucket:' . $key;

        $data = $this->store->get($bucketKey);

        if ($data === null) {
            $this->store->set($bucketKey, json_encode([
                'tokens' => $this->capacity - $tokens,
                'last_update' => $now,
            ]), $this->ttl);
            return true;
        }

        $bucket = json_decode($data, true);
        $elapsed = $now - $bucket['last_update'];
        $refillAmount = $elapsed * $this->refillRate;

        $bucket['tokens'] = min($this->capacity, $bucket['tokens'] + $refillAmount);
        $bucket['last_update'] = $now;

        if ($bucket['tokens'] >= $tokens) {
            $bucket['tokens'] -= $tokens;
            $this->store->set($bucketKey, json_encode($bucket), $this->ttl);
            return true;
        }

        $this->store->set($bucketKey, json_encode($bucket), $this->ttl);
        return false;
    }

    /**
     * 获取剩余令牌数
     */
    public function getRemaining(string $key): float
    {
        $now = microtime(true);
        $bucketKey = 'bucket:' . $key;

        $data = $this->store->get($bucketKey);

        if ($data === null) {
            return (float) $this->capacity;
        }

        $bucket = json_decode($data, true);
        $elapsed = $now - $bucket['last_update'];
        $refillAmount = $elapsed * $this->refillRate;

        return max(0, min($this->capacity, $bucket['tokens'] + $refillAmount));
    }

    /**
     * 获取等待时间（秒）
     */
    public function getWaitTime(string $key): float
    {
        $remaining = $this->getRemaining($key);
        if ($remaining >= 1) {
            return 0.0;
        }
        return (1.0 - $remaining) / $this->refillRate;
    }

    /**
     * 重置限流器
     */
    public function reset(string $key): void
    {
        $this->store->delete('bucket:' . $key);
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getRefillRate(): float
    {
        return $this->refillRate;
    }
}
