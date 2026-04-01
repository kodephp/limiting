<?php

declare(strict_types=1);

namespace Kode\Limiting\Concurrency;

use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Store\MemoryStore;

/**
 * 并发任务限流器
 *
 * 控制同时执行的最大任务数
 */
class TaskLimiter
{
    private TokenBucket $bucket;
    private int $maxConcurrency;
    private array $activeTasks = [];

    public function __construct(
        int $maxConcurrency,
        int $capacity,
        float $refillRate,
        ?MemoryStore $store = null
    ) {
        $this->maxConcurrency = $maxConcurrency;
        $this->bucket = new TokenBucket(
            $store ?? new MemoryStore(),
            $capacity,
            $refillRate
        );
    }

    /**
     * 尝试获取任务执行许可
     */
    public function tryAcquire(string $taskId): bool
    {
        if (count($this->activeTasks) >= $this->maxConcurrency) {
            return false;
        }

        if (!$this->bucket->allow('task:' . $taskId, 1)) {
            return false;
        }

        $this->activeTasks[$taskId] = true;
        return true;
    }

    /**
     * 阻塞等待获取许可
     *
     * @param string $taskId 任务ID
     * @param float $timeout 超时时间（秒）
     * @return bool
     */
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

    /**
     * 执行任务（自动获取和释放）
     *
     * @param string $taskId 任务ID
     * @param callable $callback 任务回调
     * @return mixed
     */
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

    /**
     * 释放任务
     */
    public function release(string $taskId): void
    {
        unset($this->activeTasks[$taskId]);
        $this->bucket->reset('task:' . $taskId);
    }

    /**
     * 获取当前活跃任务数
     */
    public function getActiveCount(): int
    {
        return count($this->activeTasks);
    }

    /**
     * 获取最大并发数
     */
    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }
}
