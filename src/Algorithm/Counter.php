<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Store\StoreInterface;

/**
 * 固定窗口计数器限流算法
 *
 * 实现最简单、开销最低，缺点是窗口切换瞬间可能出现两倍突刺。
 *
 * v2.0 修复：
 *  - 此前是"先 get 判断、再 incr、再 set"的三步非原子操作，高并发下必然超发；
 *    现改为"原子 incr → 超限回滚 decr"，单次往返即可完成判定；
 *  - 此前每次 set 都会重置 TTL，导致窗口被无限延长；现仅在窗口首次创建时设置 TTL；
 *  - getWaitTime() 返回当前窗口真实剩余秒数。
 */
class Counter extends AbstractRateLimiter implements RefundableInterface
{
    private readonly string $counterPrefix;

    public function __construct(
        StoreInterface $store,
        private readonly int $limit,
        private readonly int $windowSeconds = 60,
        string $prefix = 'counter:'
    ) {
        if ($windowSeconds <= 0) {
            throw InvalidConfigurationException::positiveRequired('windowSeconds', $windowSeconds);
        }

        parent::__construct($store, $limit, $windowSeconds, $prefix);

        $this->counterPrefix = $prefix;
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
    public function allow(string $key, #[\SensitiveParameter] int $tokens = 1): bool
    {
        $this->assertKey($key);
        $this->assertTokens($tokens);

        if ($tokens === 0) {
            return $this->getCount($key) < $this->limit;
        }

        if ($tokens > $this->limit) {
            return false;
        }

        $windowKey = $this->getWindowKey($key, time());
        $count = $this->store->incr($windowKey, $tokens, $this->windowSeconds);

        if ($count > $this->limit) {
            // 原子回滚，避免拒绝的请求也占用配额
            $this->store->decr($windowKey, $tokens);

            return false;
        }

        return true;
    }

    /**
     * 获取当前窗口已使用的请求数
     */
    public function getCount(string $key): int
    {
        $this->assertKey($key);

        return max(0, (int) ($this->store->get($this->getWindowKey($key, time())) ?? 0));
    }

    #[\Override]
    public function getRemaining(string $key): float
    {
        return (float) max(0, $this->limit - $this->getCount($key));
    }

    #[\Override]
    public function getWaitTime(string $key): float
    {
        if ($this->getRemaining($key) > 0) {
            return 0.0;
        }

        $now = time();
        $ttl = $this->store->ttl($this->getWindowKey($key, $now));

        if ($ttl > 0) {
            return (float) $ttl;
        }

        // 兜底：按窗口边界推算
        return (float) ($this->windowSeconds - ($now % $this->windowSeconds));
    }

    #[\Override]
    public function reset(string $key): void
    {
        $this->assertKey($key);
        $this->store->delete($this->getWindowKey($key, time()));
    }

    #[\Override]
    public function refund(string $key, #[\SensitiveParameter] int $tokens = 1): void
    {
        $this->assertKey($key);

        if ($tokens > 0) {
            $this->store->decr($this->getWindowKey($key, time()), $tokens);
        }
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * 当前窗口序号对应的存储键
     */
    private function getWindowKey(string $key, int $timestamp): string
    {
        return $this->counterPrefix . $key . ':' . intdiv($timestamp, $this->windowSeconds);
    }
}
