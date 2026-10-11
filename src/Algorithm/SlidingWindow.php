<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Store\StoreInterface;

/**
 * 滑动窗口限流算法（滑动日志实现）
 *
 * 记录窗口内每次请求的时间戳，判定时剔除过期条目后精确统计，
 * 不存在固定窗口的边界突刺问题。
 *
 * v2.0 重写：
 *  旧实现虽名为"滑动窗口"，实际是一个永不衰减的计数器 —— 它把时间戳写进了
 *  一堆孤立的键却从不读取，计数键的 TTL 又被 (int)($windowSize * 2) 截断，
 *  当窗口小于 0.5 秒时 TTL 直接变成 0（永不过期），额度耗尽后再也无法恢复。
 *  现改为单键滑动日志，读写一次即可完成精确判定。
 */
class SlidingWindow extends AbstractRateLimiter implements RefundableInterface
{
    private readonly string $windowPrefix;

    public function __construct(
        StoreInterface $store,
        int $capacity,
        private readonly float $windowSize = 1.0,
        ?int $ttl = null,
        string $prefix = 'sw:'
    ) {
        if ($windowSize <= 0) {
            throw InvalidConfigurationException::positiveRequired('windowSize', $windowSize);
        }

        // TTL 至少覆盖两个窗口，且不小于 1 秒，修复窗口 < 0.5s 时 TTL 归零的缺陷
        parent::__construct($store, $capacity, $ttl ?? max(1, (int) ceil($windowSize * 2)), $prefix);

        $this->windowPrefix = $prefix . 'window:';
    }

    public static function fromConfig(StoreInterface $store, LimiterConfig $config): self
    {
        return new self(
            $store,
            $config->capacity,
            $config->refillRate,
            max($config->ttl, (int) ceil($config->refillRate * 2)),
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
        $windowKey = $this->windowPrefix . $key;
        $entries = $this->activeEntries($windowKey, $now);
        $used = $this->sum($entries);

        if ($used + $tokens > $this->capacity) {
            $this->writeState($windowKey, ['entries' => $entries]);

            return false;
        }

        if ($tokens > 0) {
            $entries[] = [$now, $tokens];
        }

        $this->writeState($windowKey, ['entries' => $entries]);

        return true;
    }

    #[\Override]
    public function getRemaining(string $key): float
    {
        $this->assertKey($key);

        $entries = $this->activeEntries($this->windowPrefix . $key, microtime(true));

        return (float) max(0, $this->capacity - $this->sum($entries));
    }

    #[\Override]
    public function getWaitTime(string $key): float
    {
        $now = microtime(true);
        $entries = $this->activeEntries($this->windowPrefix . $key, $now);

        if ($this->sum($entries) < $this->capacity) {
            return 0.0;
        }

        // 最早的一条记录滑出窗口后即可释放额度
        $oldest = $entries[0][0] ?? $now;

        return max(0.0, ($oldest + $this->windowSize) - $now);
    }

    #[\Override]
    public function reset(string $key): void
    {
        $this->assertKey($key);
        $this->store->delete($this->windowPrefix . $key);
    }

    #[\Override]
    public function refund(string $key, #[\SensitiveParameter] int $tokens = 1): void
    {
        $this->assertKey($key);

        if ($tokens <= 0) {
            return;
        }

        $now = microtime(true);
        $windowKey = $this->windowPrefix . $key;
        $entries = $this->activeEntries($windowKey, $now);

        // 从最近的条目开始回退，至多退还 $tokens 个额度
        $remaining = $tokens;
        for ($i = count($entries) - 1; $i >= 0 && $remaining > 0; $i--) {
            $remaining -= $entries[$i][1];
            array_pop($entries);
        }

        $this->writeState($windowKey, ['entries' => $entries]);
    }

    public function getWindowSize(): float
    {
        return $this->windowSize;
    }

    /**
     * 当前窗口内的请求条目
     *
     * @return list<array{0: float, 1: int}>
     */
    private function activeEntries(string $windowKey, float $now): array
    {
        $state = $this->readState($windowKey);
        $entries = is_array($state['entries'] ?? null) ? $state['entries'] : [];
        $boundary = $now - $this->windowSize;
        $active = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || count($entry) < 2) {
                continue;
            }

            $timestamp = (float) $entry[0];

            if ($timestamp > $boundary) {
                $active[] = [$timestamp, (int) $entry[1]];
            }
        }

        return $active;
    }

    /**
     * @param list<array{0: float, 1: int}> $entries
     */
    private function sum(array $entries): int
    {
        return array_sum(array_column($entries, 1));
    }
}
