<?php

declare(strict_types=1);

namespace Kode\Limiting\Concurrency;

use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\StoreInterface;

/**
 * 进程并发限流器
 *
 * v2.0 重写：
 *  - 底层换成计数信号量，maxProcesses 真实生效；
 *  - 清理了 v1.x 中"声明了 $instance 却使用 $instances"的死代码；
 *  - 单机跨进程请配合 ApcuStore / RedisStore 使用，MemoryStore 仅进程内有效。
 */
class ProcessLimiter
{
    /** @var array<string, self> */
    private static array $instances = [];

    private readonly Semaphore $semaphore;

    public function __construct(
        private readonly int $maxProcesses,
        StoreInterface $store = new MemoryStore(),
        string $prefix = 'process:',
        int $slotTtl = 3600
    ) {
        $this->semaphore = new Semaphore($maxProcesses, $store, $prefix, $slotTtl);
    }

    public static function create(
        int $maxProcesses = 10,
        ?StoreInterface $store = null,
        string $prefix = 'process:'
    ): self {
        return new self($maxProcesses, $store ?? new MemoryStore(), $prefix);
    }

    /**
     * 获取共享实例（按 maxProcesses + prefix 区分）
     */
    public static function getInstance(int $maxProcesses = 10, string $prefix = 'process:'): self
    {
        return self::$instances["{$maxProcesses}:{$prefix}"] ??= new self($maxProcesses, new MemoryStore(), $prefix);
    }

    /**
     * 清空共享实例缓存（主要用于测试）
     */
    public static function flushInstances(): void
    {
        self::$instances = [];
    }

    public function tryAcquire(string $processId): bool
    {
        return $this->semaphore->tryAcquire($processId);
    }

    public function acquire(string $processId, float $timeout = 30.0): bool
    {
        return $this->semaphore->acquire($processId, $timeout, null, 100_000);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(string $processId, callable $callback, float $timeout = 30.0): mixed
    {
        return $this->semaphore->run($processId, $callback, $timeout);
    }

    public function release(string $processId): void
    {
        $this->semaphore->release($processId);
    }

    public function isRunning(string $processId): bool
    {
        return $this->semaphore->isHeldBy($processId);
    }

    public function getActiveCount(): int
    {
        return $this->semaphore->getActiveCount();
    }

    public function getAvailable(): int
    {
        return $this->semaphore->getAvailable();
    }

    public function getMaxProcesses(): int
    {
        return $this->maxProcesses;
    }

    public function reset(): void
    {
        $this->semaphore->reset();
    }
}
