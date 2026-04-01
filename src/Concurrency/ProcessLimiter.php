<?php

declare(strict_types=1);

namespace Kode\Limiting\Concurrency;

use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Store\MemoryStore;

/**
 * 进程限流器（单例模式）
 *
 * 控制同时运行的最大进程数
 * 使用 PHP 8.2 readonly 属性优化性能
 */
class ProcessLimiter
{
    private static ?self $instance = null;
    private readonly TokenBucket $bucket;

    public function __construct(
        private readonly int $maxProcesses,
        private readonly int $capacity,
        private readonly float $refillRate,
        private readonly MemoryStore $store,
        private readonly string $prefix = 'process:'
    ) {
        $this->bucket = new TokenBucket(
            $this->store,
            $this->capacity,
            $this->refillRate,
            3600,
            $this->prefix
        );
    }

    public static function getInstance(
        int $maxProcesses = 10,
        int $capacity = 10,
        float $refillRate = 1.0
    ): self {
        $instanceKey = "{$maxProcesses}:{$capacity}:{$refillRate}";

        if (!isset(self::$instances[$instanceKey])) {
            self::$instances[$instanceKey] = new self($maxProcesses, $capacity, $refillRate, new MemoryStore());
        }

        return self::$instances[$instanceKey];
    }

    private static array $instances = [];

    public function tryAcquire(string $processId): bool
    {
        $key = $this->prefix . 'process:' . $processId;
        return $this->bucket->allow($key, 1);
    }

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

    public function release(string $processId): void
    {
        $this->bucket->reset($this->prefix . 'process:' . $processId);
    }

    public function getActiveCount(): int
    {
        return (int) ($this->store->get($this->prefix . 'active') ?? 0);
    }

    public function getMaxProcesses(): int
    {
        return $this->maxProcesses;
    }
}
