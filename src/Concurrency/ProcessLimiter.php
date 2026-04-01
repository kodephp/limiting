<?php

declare(strict_types=1);

namespace Kode\Limiting\Concurrency;

use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Store\MemoryStore;

/**
 * 进程限流器
 *
 * 控制同时运行的最大进程数
 */
class ProcessLimiter
{
    private TokenBucket $bucket;
    private int $maxProcesses;
    private array $activeProcesses = [];

    public function __construct(
        int $maxProcesses,
        int $capacity,
        float $refillRate,
        ?MemoryStore $store = null
    ) {
        $this->maxProcesses = $maxProcesses;
        $this->bucket = new TokenBucket(
            $store ?? new MemoryStore(),
            $capacity,
            $refillRate
        );
    }

    /**
     * 获取单例实例
     */
    public static function getInstance(
        int $maxProcesses = 10,
        int $capacity = 10,
        float $refillRate = 1.0
    ): self {
        static $instance = null;
        $instance ??= new self($maxProcesses, $capacity, $refillRate);
        return $instance;
    }

    /**
     * 尝试获取进程许可
     */
    public function tryAcquire(string $processId): bool
    {
        if (!$this->bucket->allow('process:' . $processId, 1)) {
            return false;
        }

        $this->activeProcesses[$processId] = true;
        return true;
    }

    /**
     * 阻塞等待获取许可
     */
    public function acquire(string $processId, float $timeout = 30.0): bool
    {
        $start = microtime(true);

        while (!$this->tryAcquire($processId)) {
            if (microtime(true) - $start >= $timeout) {
                return false;
            }
            usleep(100000);
        }

        return true;
    }

    /**
     * 释放进程
     */
    public function release(string $processId): void
    {
        unset($this->activeProcesses[$processId]);
        $this->bucket->reset('process:' . $processId);
    }

    /**
     * 获取当前活跃进程数
     */
    public function getActiveCount(): int
    {
        return count($this->activeProcesses);
    }

    /**
     * 获取最大进程数
     */
    public function getMaxProcesses(): int
    {
        return $this->maxProcesses;
    }
}
