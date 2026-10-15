<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Store\StoreInterface;

/**
 * 漏桶限流算法
 *
 * 水（请求）以任意速率注入，以固定速率漏出；桶满则拒绝。
 * 适合需要"流量整形"、抑制突发的场景。
 *
 * v2.0 修复：
 *  - 超限时不再把溢出的水量写回存储（此前会造成惩罚性累积，
 *    使桶在拒绝后需要额外时间才能恢复）；
 *  - 首次请求走内存态计算，省掉一次多余的 get；
 *  - getWaitTime() 按实际溢出量计算。
 */
class LeakyBucket extends AbstractRateLimiter implements RefundableInterface
{
    private readonly string $bucketPrefix;

    public function __construct(
        StoreInterface $store,
        int $capacity,
        private readonly float $leakRate,
        int $ttl = 3600,
        string $prefix = 'leaky:'
    ) {
        parent::__construct($store, $capacity, $ttl, $prefix);

        if ($leakRate <= 0) {
            throw InvalidConfigurationException::positiveRequired('leakRate', $leakRate);
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
        $water = $this->currentWater($bucketKey, $now);

        if ($water + $tokens > $this->capacity + 1e-9) {
            // 拒绝时只落盘"已漏水后"的水位，不累计溢出部分
            $this->writeState($bucketKey, ['water' => $water, 'last_leak' => $now]);

            return false;
        }

        $this->writeState($bucketKey, ['water' => $water + $tokens, 'last_leak' => $now]);

        return true;
    }

    #[\Override]
    public function getRemaining(string $key): float
    {
        $this->assertKey($key);

        $water = $this->currentWater($this->bucketPrefix . $key, microtime(true));

        return max(0.0, $this->capacity - $water);
    }

    #[\Override]
    public function getWaitTime(string $key): float
    {
        $remaining = $this->getRemaining($key);

        if ($remaining >= 1) {
            return 0.0;
        }

        return (1.0 - $remaining) / $this->leakRate;
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
        $water = max(0.0, $this->currentWater($bucketKey, $now) - $tokens);

        $this->writeState($bucketKey, ['water' => $water, 'last_leak' => $now]);
    }

    public function getLeakRate(): float
    {
        return $this->leakRate;
    }

    /**
     * 计算按当前时刻漏水后的水位
     */
    private function currentWater(string $bucketKey, float $now): float
    {
        $state = $this->readState($bucketKey);

        if ($state === null) {
            return 0.0;
        }

        $water = (float) ($state['water'] ?? 0.0);
        $lastLeak = (float) ($state['last_leak'] ?? $now);
        $leaked = max(0.0, $now - $lastLeak) * $this->leakRate;

        return max(0.0, $water - $leaked);
    }
}
