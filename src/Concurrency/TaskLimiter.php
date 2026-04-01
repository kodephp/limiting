<?php

declare(strict_types=1);

namespace Kode\Limiting\Concurrency;

use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Store\MemoryStore;

/**
 * 并发任务限流器
 *
 * 控制同时执行的最大任务数
 * 使用 PHP 8.2 readonly 属性优化性能
 */
class TaskLimiter
{
    private readonly TokenBucket $bucket;

    public function __construct(
        private readonly int $maxConcurrency,
        private readonly int $capacity,
        private readonly float $refillRate,
        private readonly MemoryStore $store,
        private readonly string $prefix = 'task:'
    ) {
        $this->bucket = new TokenBucket(
            $this->store,
            $this->capacity,
            $this->refillRate,
            3600,
            $this->prefix
        );
    }

    public static function create(
        int $maxConcurrency,
        int $capacity,
        float $refillRate = 1.0,
        ?MemoryStore $store = null
    ): self {
        return new self($maxConcurrency, $capacity, $refillRate, $store ?? new MemoryStore());
    }

    public function tryAcquire(string $taskId): bool
    {
        $key = $this->prefix . 'task:' . $taskId;
        return $this->bucket->allow($key, 1);
    }

    public function acquire(string $taskId, float $timeout = 30.0): bool
    {
        $start = microtime(true);

        while (!$this->tryAcquire($taskId)) {
            if (microtime(true) - $start >= $timeout) {
                return false;
            }
            usleep(10000);
        }

        return true;
    }

    public function run(string $taskId, callable $callback): mixed
    {
        if (!$this->acquire($taskId)) {
            throw new \RuntimeException("获取任务许可超时: {$taskId}");
        }

        try {
            return $callback();
        } finally {
            $this->release($taskId);
        }
    }

    public function release(string $taskId): void
    {
        $this->bucket->reset($this->prefix . 'task:' . $taskId);
    }

    public function getActiveCount(): int
    {
        return (int) ($this->store->get($this->prefix . 'active') ?? 0);
    }

    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }
}
