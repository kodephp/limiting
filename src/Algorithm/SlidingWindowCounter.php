<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Store\StoreInterface;

/**
 * 滑动窗口计数器（加权近似，v2.0 新增）
 *
 * 只维护"上一窗口 + 当前窗口"两个整数计数，按当前时刻在窗口中的位置
 * 对上一窗口计数加权，即可逼近精确滑动窗口的效果。
 *
 *   估算值 = 上一窗口计数 × (1 - 已过窗口比例) + 当前窗口计数
 *
 * 相比滑动日志：内存占用恒定（两个整数 vs N 条时间戳），
 * 且全部依赖存储层的原子 INCR，天然适合 Redis 分布式场景。
 * 代价是存在极小的统计误差（业界普遍认为 < 1%）。
 */
class SlidingWindowCounter extends AbstractRateLimiter implements RefundableInterface
{
    private readonly string $counterPrefix;

    public function __construct(
        StoreInterface $store,
        int $capacity,
        private readonly int $windowSeconds = 60,
        string $prefix = 'swc:'
    ) {
        if ($windowSeconds <= 0) {
            throw InvalidConfigurationException::positiveRequired('windowSeconds', $windowSeconds);
        }

        parent::__construct($store, $capacity, $windowSeconds * 2, $prefix);

        $this->counterPrefix = $prefix . 'w:';
    }

    public static function fromConfig(StoreInterface $store, LimiterConfig $config): self
    {
        return new self(
            $store,
            $config->capacity,
            max(1, (int) round($config->refillRate)),
            $config->prefix
        );
    }

    #[\Override]
    public function allow(string $key, int $tokens = 1): bool
    {
        $this->assertKey($key);
        $this->assertTokens($tokens);

        if ($tokens > $this->capacity) {
            return false;
        }

        $now = microtime(true);

        if ($tokens <= 0) {
            return $this->estimate($key, $now) < $this->capacity + 1e-9;
        }

        // 先原子 incr 再按加权估算判定（旧实现 check-then-set 非原子，多 worker 并发可超发）
        $currentKey = $this->windowKey($key, $this->windowIndex($now));
        $this->store->incr($currentKey, $tokens, $this->ttl);

        if ($this->estimate($key, $now) > $this->capacity + 1e-9) {
            $this->store->decr($currentKey, $tokens);

            return false;
        }

        return true;
    }

    #[\Override]
    public function getRemaining(string $key): float
    {
        $this->assertKey($key);

        return max(0.0, $this->capacity - $this->estimate($key, microtime(true)));
    }

    #[\Override]
    public function getWaitTime(string $key): float
    {
        $now = microtime(true);

        if ($this->estimate($key, $now) < $this->capacity) {
            return 0.0;
        }

        // 至少要等到当前窗口结束，上一窗口的权重才会显著下降
        return (float) $this->windowSeconds - fmod($now, (float) $this->windowSeconds);
    }

    #[\Override]
    public function reset(string $key): void
    {
        $this->assertKey($key);

        $index = $this->windowIndex(microtime(true));
        $this->store->delete($this->windowKey($key, $index));
        $this->store->delete($this->windowKey($key, $index - 1));
    }

    #[\Override]
    public function refund(string $key, int $tokens = 1): void
    {
        $this->assertKey($key);

        if ($tokens > 0) {
            $this->store->decr($this->windowKey($key, $this->windowIndex(microtime(true))), $tokens);
        }
    }

    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * 当前加权估算用量
     */
    public function estimate(string $key, ?float $now = null): float
    {
        $now ??= microtime(true);
        $index = $this->windowIndex($now);

        $current = (int) ($this->store->get($this->windowKey($key, $index)) ?? 0);
        $previous = (int) ($this->store->get($this->windowKey($key, $index - 1)) ?? 0);

        $elapsedRatio = fmod($now, (float) $this->windowSeconds) / $this->windowSeconds;
        $weight = max(0.0, 1.0 - $elapsedRatio);

        return $previous * $weight + $current;
    }

    private function windowIndex(float $now): int
    {
        return (int) floor($now / $this->windowSeconds);
    }

    private function windowKey(string $key, int $index): string
    {
        return $this->counterPrefix . $key . ':' . $index;
    }
}
