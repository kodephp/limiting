<?php

declare(strict_types=1);

namespace Kode\Limiting\Concurrency;

use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\StoreInterface;

/**
 * 并发任务限流器
 *
 * 控制同时执行的最大任务数。
 *
 * v2.0 重写：底层从"每个 taskId 一个令牌桶"（并发上限形同虚设）
 * 改为共享槽位的计数信号量，maxConcurrency 现在是真实生效的硬上限。
 */
class TaskLimiter
{
    private readonly Semaphore $semaphore;

    public function __construct(
        private readonly int $maxConcurrency,
        StoreInterface $store = new MemoryStore(),
        private readonly string $prefix = 'task:',
        int $slotTtl = 3600
    ) {
        $this->semaphore = new Semaphore($maxConcurrency, $store, $prefix, $slotTtl);
    }

    public static function create(
        int $maxConcurrency = 10,
        ?StoreInterface $store = null,
        string $prefix = 'task:'
    ): self {
        return new self($maxConcurrency, $store ?? new MemoryStore(), $prefix);
    }

    public function tryAcquire(string $taskId): bool
    {
        return $this->semaphore->tryAcquire($taskId);
    }

    public function acquire(string $taskId, float $timeout = 30.0): bool
    {
        return $this->semaphore->acquire($taskId, $timeout);
    }

    /**
     * 在并发额度内执行任务，结束后自动释放
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(string $taskId, callable $callback, float $timeout = 30.0): mixed
    {
        return $this->semaphore->run($taskId, $callback, $timeout);
    }

    public function release(string $taskId): void
    {
        $this->semaphore->release($taskId);
    }

    public function isRunning(string $taskId): bool
    {
        return $this->semaphore->isHeldBy($taskId);
    }

    public function getActiveCount(): int
    {
        return $this->semaphore->getActiveCount();
    }

    public function getAvailable(): int
    {
        return $this->semaphore->getAvailable();
    }

    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function reset(): void
    {
        $this->semaphore->reset();
    }
}
