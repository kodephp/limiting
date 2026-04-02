<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\Store\StoreInterface;

/**
 * 漏桶算法限流器
 *
 * 特点：固定速率输出，适合流量整形
 * 水（请求）以任意速率进入桶，但以固定速率离开
 */
class LeakyBucket implements RateLimiterInterface
{
    private readonly string $bucketPrefix;

    public function __construct(
        private readonly StoreInterface $store,
        private readonly int $capacity,
        private readonly float $leakRate,
        private readonly int $ttl = 3600,
        private readonly string $prefix = 'leaky:'
    ) {
        $this->bucketPrefix = $this->prefix . 'bucket:';
    }

    /**
     * 检查是否允许请求
     *
     * @param string $key 限流键
     * @param int $tokens 请求的令牌数（相当于注入的水量）
     * @return bool
     */
    public function allow(string $key, int $tokens = 1): bool
    {
        $now = microtime(true);
        $bucketKey = $this->bucketPrefix . $key;

        $data = $this->store->get($bucketKey);

        if ($data === null) {
            $this->store->set($bucketKey, json_encode([
                'water' => 0.0,
                'last_leak' => $now,
            ]), $this->ttl);
            $data = $this->store->get($bucketKey);
        }

        $bucket = json_decode($data, true);

        $this->leak($bucket, $now);
        $bucket['water'] += $tokens;

        if ($bucket['water'] > $this->capacity) {
            $this->store->set($bucketKey, json_encode($bucket), $this->ttl);
            return false;
        }

        $this->store->set($bucketKey, json_encode($bucket), $this->ttl);
        return true;
    }

    /**
     * 漏水处理
     */
    private function leak(array &$bucket, float $now): void
    {
        $elapsed = $now - $bucket['last_leak'];
        $leaked = $elapsed * $this->leakRate;

        $bucket['water'] = max(0, $bucket['water'] - $leaked);
        $bucket['last_leak'] = $now;
    }

    public function getRemaining(string $key): float
    {
        $now = microtime(true);
        $bucketKey = $this->bucketPrefix . $key;
        $data = $this->store->get($bucketKey);

        if ($data === null) {
            return (float) $this->capacity;
        }

        $bucket = json_decode($data, true);
        $this->leak($bucket, $now);

        return max(0, $this->capacity - $bucket['water']);
    }

    public function getWaitTime(string $key): float
    {
        $remaining = $this->getRemaining($key);

        if ($remaining >= 1) {
            return 0.0;
        }

        return (1.0 - $remaining) / $this->leakRate;
    }

    public function reset(string $key): void
    {
        $this->store->delete($this->bucketPrefix . $key);
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getLeakRate(): float
    {
        return $this->leakRate;
    }
}
