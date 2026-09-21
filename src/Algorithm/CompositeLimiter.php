<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\StoreInterface;

/**
 * 组合限流器（v2.0 新增）
 *
 * 把多条限流规则串联成一条，全部通过才放行 —— 这正是真实业务里
 * "10 次/秒 且 100 次/分钟 且 2000 次/天" 的典型诉求。
 *
 * 采用"先全量预检、再统一提交"的两阶段策略：
 * 任一规则预检不通过就整体拒绝，不会出现部分规则被扣减的情况；
 * 若提交阶段仍有规则失败（并发竞争），已扣减的规则按本次增量 refund 扣回，
 * 绝不 reset 整窗（否则会清空其它请求的合法计数）。
 *
 * @example
 * ```php
 * $limiter = new CompositeLimiter([
 *     'per_second' => new TokenBucket($store, 10, 10.0),
 *     'per_minute' => new Counter($store, 100, 60),
 * ]);
 * $limiter->allow('api:user:1');
 * ```
 */
class CompositeLimiter implements RateLimiterInterface, RefundableInterface
{
    /** @var array<string, RateLimiterInterface> */
    private readonly array $limiters;

    /**
     * @param array<string, RateLimiterInterface>|list<RateLimiterInterface> $limiters
     */
    public function __construct(array $limiters)
    {
        if ($limiters === []) {
            throw new InvalidConfigurationException('组合限流器至少需要一条规则');
        }

        $normalized = [];

        foreach ($limiters as $name => $limiter) {
            if (!$limiter instanceof RateLimiterInterface) {
                throw new InvalidConfigurationException('组合限流器的每一项都必须实现 RateLimiterInterface');
            }

            $normalized[(string) $name] = $limiter;
        }

        $this->limiters = $normalized;
    }

    /**
     * @param array<string, RateLimiterInterface>|list<RateLimiterInterface> $limiters
     */
    public static function of(array $limiters): self
    {
        return new self($limiters);
    }

    #[\Override]
    public function allow(string $key, int $tokens = 1): bool
    {
        // 阶段一：全部预检
        foreach ($this->limiters as $limiter) {
            if ($limiter->check($key, $tokens)->isDenied()) {
                return false;
            }
        }

        // 阶段二：统一提交，失败则回滚已提交的规则
        $committed = [];

        foreach ($this->limiters as $name => $limiter) {
            if ($limiter->allow($key, $tokens)) {
                $committed[$name] = $limiter;
                continue;
            }

            // 只扣回本次请求的增量；reset 会清空整个活窗口，可被攻击者利用来"归零"快规则
            foreach ($committed as $done) {
                if ($done instanceof RefundableInterface) {
                    $done->refund($key, $tokens);
                } else {
                    $done->reset($key);
                }
            }

            return false;
        }

        return true;
    }

    #[\Override]
    public function check(string $key, int $tokens = 1): LimiterResult
    {
        $worst = null;

        foreach ($this->limiters as $limiter) {
            $result = $limiter->check($key, $tokens);

            if ($worst === null
                || ($result->isDenied() && $worst->isAllowed())
                || ($result->isDenied() === $worst->isDenied() && $result->remaining < $worst->remaining)
            ) {
                $worst = $result;
            }
        }

        return $worst ?? LimiterResult::allowed();
    }

    #[\Override]
    public function consume(string $key, int $tokens = 1): LimiterResult
    {
        if ($this->allow($key, $tokens)) {
            return LimiterResult::allowed($this->getRemaining($key), $this->getCapacity(), $this->getWaitTime($key));
        }

        return LimiterResult::denied($this->getWaitTime($key), $this->getCapacity(), $this->getRemaining($key));
    }

    #[\Override]
    public function getRemaining(string $key): float
    {
        $values = array_map(static fn (RateLimiterInterface $l): float => $l->getRemaining($key), $this->limiters);

        return $values === [] ? 0.0 : min($values);
    }

    #[\Override]
    public function getWaitTime(string $key): float
    {
        $values = array_map(static fn (RateLimiterInterface $l): float => $l->getWaitTime($key), $this->limiters);

        return $values === [] ? 0.0 : max($values);
    }

    #[\Override]
    public function reset(string $key): void
    {
        foreach ($this->limiters as $limiter) {
            $limiter->reset($key);
        }
    }

    /**
     * 逐规则扣回本次增量，供外层组合回滚时使用（避免整窗清空）
     */
    #[\Override]
    public function refund(string $key, int $tokens = 1): void
    {
        foreach ($this->limiters as $limiter) {
            if ($limiter instanceof RefundableInterface) {
                $limiter->refund($key, $tokens);
            }
        }
    }

    #[\Override]
    public function getCapacity(): int
    {
        $values = array_map(static fn (RateLimiterInterface $l): int => $l->getCapacity(), $this->limiters);

        return $values === [] ? 0 : min($values);
    }

    #[\Override]
    public function getStore(): StoreInterface
    {
        foreach ($this->limiters as $limiter) {
            return $limiter->getStore();
        }

        return new MemoryStore();
    }

    /**
     * 定位最先拒绝请求的规则名，便于日志与排障
     */
    public function firstDeniedRule(string $key, int $tokens = 1): ?string
    {
        foreach ($this->limiters as $name => $limiter) {
            if ($limiter->check($key, $tokens)->isDenied()) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<string, RateLimiterInterface>
     */
    public function getLimiters(): array
    {
        return $this->limiters;
    }

    /**
     * 逐规则输出结果，便于观测
     *
     * @return array<string, LimiterResult>
     */
    public function inspect(string $key, int $tokens = 1): array
    {
        $results = [];

        foreach ($this->limiters as $name => $limiter) {
            $results[$name] = $limiter->check($key, $tokens);
        }

        return $results;
    }
}
