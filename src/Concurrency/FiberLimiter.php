<?php

declare(strict_types=1);

namespace Kode\Limiting\Concurrency;

use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\StoreInterface;

/**
 * Fiber 协程并发限流器
 *
 * v2.0 重写：
 *  - 底层换成计数信号量，maxFibers 真实生效；
 *  - acquire() 仅在处于 Fiber 上下文时才 suspend，否则回退为 usleep，
 *    避免 v1.x 中在主线程调用 Fiber::suspend() 直接抛致命错误的问题。
 */
class FiberLimiter
{
    private readonly Semaphore $semaphore;

    public function __construct(
        private readonly int $maxFibers,
        StoreInterface $store = new MemoryStore(),
        string $prefix = 'fiber:',
        int $slotTtl = 3600
    ) {
        $this->semaphore = new Semaphore($maxFibers, $store, $prefix, $slotTtl);
    }

    public static function create(
        int $maxFibers = 10,
        ?StoreInterface $store = null,
        string $prefix = 'fiber:'
    ): self {
        return new self($maxFibers, $store ?? new MemoryStore(), $prefix);
    }

    /**
     * 当前 PHP 是否支持 Fiber（PHP 8.1+ 均支持）
     */
    public static function isSupported(): bool
    {
        return class_exists(\Fiber::class);
    }

    public function tryAcquire(string $fiberId): bool
    {
        return $this->semaphore->tryAcquire($fiberId);
    }

    /**
     * 等待槽位；在协程内让出执行权，在主线程内退化为轮询等待
     */
    public function acquire(string $fiberId, float $timeout = 30.0): bool
    {
        $start = microtime(true);

        while (!$this->tryAcquire($fiberId)) {
            if (microtime(true) - $start >= $timeout) {
                return false;
            }

            if (self::isSupported() && \Fiber::getCurrent() !== null) {
                \Fiber::suspend();
            } else {
                usleep(10_000);
            }
        }

        return true;
    }

    /**
     * 在并发额度内创建并启动 Fiber，槽位在协程结束时自动释放
     *
     * @return \Fiber<mixed, mixed, mixed, mixed>|null 无可用槽位或不支持 Fiber 时返回 null
     */
    public function start(string $fiberId, callable $callback): ?\Fiber
    {
        if (!self::isSupported() || !$this->tryAcquire($fiberId)) {
            return null;
        }

        $fiber = new \Fiber(function () use ($fiberId, $callback): void {
            try {
                $callback();
            } finally {
                $this->release($fiberId);
            }
        });

        $fiber->start();

        return $fiber;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(string $fiberId, callable $callback, float $timeout = 30.0): mixed
    {
        return $this->semaphore->run($fiberId, $callback, $timeout);
    }

    public function release(string $fiberId): void
    {
        $this->semaphore->release($fiberId);
    }

    public function isRunning(string $fiberId): bool
    {
        return $this->semaphore->isHeldBy($fiberId);
    }

    public function getActiveCount(): int
    {
        return $this->semaphore->getActiveCount();
    }

    public function getAvailable(): int
    {
        return $this->semaphore->getAvailable();
    }

    public function getMaxFibers(): int
    {
        return $this->maxFibers;
    }

    public function reset(): void
    {
        $this->semaphore->reset();
    }
}
