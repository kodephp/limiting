<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Store\StoreInterface;

/**
 * 令牌桶限流算法
 *
 * 以固定速率补充令牌，桶内有存量即可放行，因此天然支持突发流量。
 *
 * v2.0 修复：
 *  - 首次请求时若 tokens > capacity 会被错误放行且写入负数令牌，现直接拒绝；
 *  - 状态 JSON 损坏时不再触发 undefined index，按空桶重建；
 *  - getWaitTime() 按实际缺口计算，而非固定按 1 个令牌估算。
 */
class TokenBucket extends AbstractRateLimiter implements RefundableInterface
{
    private readonly string $bucketPrefix;

    public function __construct(
        StoreInterface $store,
        int $capacity,
        private readonly float $refillRate,
        int $ttl = 3600,
        string $prefix = 'bucket:'
    ) {
        parent::__construct($store, $capacity, $ttl, $prefix);

        if ($refillRate <= 0) {
            throw InvalidConfigurationException::positiveRequired('refillRate', $refillRate);
        }

        $this->bucketPrefix = $prefix . 'bucket:';
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

    #[\Override]
    public function allow(string $key, #[\SensitiveParameter] int $tokens = 1): bool
    {
        $this->assertKey($key);
        $this->assertTokens($tokens);

        if ($tokens > $this->capacity) {
            return false;
        }

        $now = microtime(true);
        $bucketKey = $this->bucketPrefix . $key;
        $state = $this->readState($bucketKey);

        $available = $state === null
            ? (float) $this->capacity
            : $this->refill(
                (float) ($state['tokens'] ?? 0),
                (float) ($state['last_update'] ?? $now),
                $now
            );

        if ($available + 1e-9 < $tokens) {
            $this->writeState($bucketKey, ['tokens' => $available, 'last_update' => $now]);

            return false;
        }

        $this->writeState($bucketKey, ['tokens' => $available - $tokens, 'last_update' => $now]);

        return true;
    }

    #[\Override]
    public function getRemaining(string $key): float
    {
        $this->assertKey($key);

        $state = $this->readState($this->bucketPrefix . $key);

        if ($state === null) {
            return (float) $this->capacity;
        }

        $now = microtime(true);

        return $this->refill(
            (float) ($state['tokens'] ?? 0),
            (float) ($state['last_update'] ?? $now),
            $now
        );
    }

    #[\Override]
    public function getWaitTime(string $key): float
    {
        return $this->getWaitTimeFor($key, 1);
    }

    /**
     * 获取攒够指定令牌数所需的等待秒数
     */
    public function getWaitTimeFor(string $key, #[\SensitiveParameter] int $tokens): float
    {
        $remaining = $this->getRemaining($key);

        if ($remaining >= $tokens) {
            return 0.0;
        }

        return ($tokens - $remaining) / $this->refillRate;
    }

    #[\Override]
    public function reset(string $key): void
    {
        $this->assertKey($key);
        $this->store->delete($this->bucketPrefix . $key);
    }

    #[\Override]
    public function refund(string $key, #[\SensitiveParameter] int $tokens = 1): void
    {
        $this->assertKey($key);

        if ($tokens <= 0) {
            return;
        }

        $now = microtime(true);
        $bucketKey = $this->bucketPrefix . $key;
        $state = $this->readState($bucketKey);
        $available = $state === null
            ? 0.0
            : $this->refill((float) ($state['tokens'] ?? 0), (float) ($state['last_update'] ?? $now), $now);

        $this->writeState($bucketKey, ['tokens' => min((float) $this->capacity, $available + $tokens), 'last_update' => $now]);
    }

    public function getRefillRate(): float
    {
        return $this->refillRate;
    }

    /**
     * 按流逝时间补充令牌，上限为桶容量
     */
    private function refill(#[\SensitiveParameter] float $tokens, float $lastUpdate, float $now): float
    {
        $elapsed = max(0.0, $now - $lastUpdate);

        return max(0.0, min((float) $this->capacity, $tokens + $elapsed * $this->refillRate));
    }
}
