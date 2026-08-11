<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

use Kode\Limiting\Enum\FailurePolicy;
use Kode\Limiting\Exception\StoreException;

/**
 * 容灾存储装饰器
 *
 * 包裹任意 StoreInterface，在底层存储抛错时按 {@see FailurePolicy} 兜底，
 * 避免"限流器自己把业务打挂"。默认 fail-open：读操作返回安全默认值，
 * 写操作静默忽略，并标记降级态供上层感知。
 *
 * @example
 * ```php
 * $safe = new ResilientStore(RedisStore::create(), FailurePolicy::ALLOW);
 * Limiter::tokenBucket(100, 10.0, $safe)->allow('api:user:1'); // Redis 宕机也放行
 * ```
 */
final class ResilientStore implements StoreInterface, DegradationAwareInterface
{
    private bool $degraded = false;
    private ?string $reason = null;

    /**
     * @param FailurePolicy $policy 兜底策略，默认 ALLOW（fail-open）
     */
    public function __construct(
        private readonly StoreInterface $inner,
        private readonly FailurePolicy $policy = FailurePolicy::ALLOW
    ) {}

    #[\Override]
    public function get(string $key): ?string
    {
        return $this->guard(fn (): ?string => $this->inner->get($key), null);
    }

    #[\Override]
    public function set(string $key, string $value, int $ttl = 0): void
    {
        $this->guardVoid(function () use ($key, $value, $ttl): void {
            $this->inner->set($key, $value, $ttl);
        });
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->guardVoid(function () use ($key): void {
            $this->inner->delete($key);
        });
    }

    #[\Override]
    public function incr(string $key, int $step = 1, int $ttl = 0): int
    {
        // 故障时返回 step，使得计数器类算法表现为"尚有额度"，从而放行
        return $this->guard(fn (): int => $this->inner->incr($key, $step, $ttl), $step);
    }

    #[\Override]
    public function decr(string $key, int $step = 1): int
    {
        return $this->guard(fn (): int => $this->inner->decr($key, $step), -$step);
    }

    #[\Override]
    public function ttl(string $key): int
    {
        return $this->guard(fn (): int => $this->inner->ttl($key), -2);
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->guard(fn (): bool => $this->inner->has($key), false);
    }

    #[\Override]
    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    #[\Override]
    public function getDegradationReason(): ?string
    {
        return $this->reason;
    }

    #[\Override]
    public function clearDegradation(): void
    {
        $this->degraded = false;
        $this->reason = null;
    }

    /**
     * 底层存储是否健康（未降级即视为健康）
     */
    public function isHealthy(): bool
    {
        return !$this->degraded;
    }

    /**
     * 取出被装饰的原始存储（用于健康检查、调试）
     */
    public function getInnerStore(): StoreInterface
    {
        return $this->inner;
    }

    /**
     * 包裹一次"有返回值"的存储操作：成功返回其结果；失败按策略兜底
     *
     * @template T
     * @param callable(): T $operation
     * @param T            $fallback
     * @return T
     */
    private function guard(callable $operation, mixed $fallback = null): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            $this->markDegraded($e);

            if ($this->policy === FailurePolicy::RETHROW) {
                throw $this->wrap($e);
            }

            return $fallback;
        }
    }

    /**
     * 包裹一次"无返回值"的存储操作（如 set / delete）
     */
    private function guardVoid(callable $operation): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->markDegraded($e);

            if ($this->policy === FailurePolicy::RETHROW) {
                throw $this->wrap($e);
            }
        }
    }

    private function markDegraded(\Throwable $e): void
    {
        $this->degraded = true;
        $this->reason = $e->getMessage();
    }

    private function wrap(\Throwable $e): StoreException
    {
        return $e instanceof StoreException
            ? $e
            : StoreException::operationFailed('ResilientStore', '存储操作', $e);
    }
}
