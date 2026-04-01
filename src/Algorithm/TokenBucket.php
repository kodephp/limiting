<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Store\StoreInterface;

/**
 * 令牌桶限流算法
 *
 * 支持突发流量，按固定速率补充令牌
 * 使用 PHP 8.2 readonly 属性优化性能
 */
class TokenBucket implements RateLimiterInterface
{
    private readonly string $bucketPrefix;

    public function __construct(
        private readonly StoreInterface $store,
        private readonly int $capacity,
        private readonly float $refillRate,
        private readonly int $ttl = 3600,
        private readonly string $prefix = 'bucket:'
    ) {
        $this->bucketPrefix = $this->prefix . 'bucket:';
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
        $bucketKey = $this->bucketPrefix . $key;

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
        $now = microtime(true);
        $bucketKey = $this->bucketPrefix . $key;

        $data = $this->store->get($bucketKey);

        if ($data === null) {
            return (float) $this->capacity;
        }

        $bucket = json_decode($data, true);
        $elapsed = $now - $bucket['last_update'];
        $refillAmount = $elapsed * $this->refillRate;

        return max(0, min($this->capacity, $bucket['tokens'] + $refillAmount));
    }

    public function getWaitTime(string $key): float
    {
        $remaining = $this->getRemaining($key);
        if ($remaining >= 1) {
            return 0.0;
        }
        return (1.0 - $remaining) / $this->refillRate;
    }

    public function reset(string $key): void
    {
        $this->store->delete($this->bucketPrefix . $key);
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getRefillRate(): float
    {
        return $this->refillRate;
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }
}
