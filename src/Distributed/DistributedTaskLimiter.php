<?php

declare(strict_types=1);

namespace Kode\Limiting\Distributed;

use Kode\Limiting\Store\RedisStore;

/**
 * 分布式并发任务限流器（跨机器）
 *
 * 使用 Redis 协调多机器间的任务并发上限。
 */
class DistributedTaskLimiter extends AbstractDistributedSemaphore
{
    public function __construct(
        RedisStore $store,
        int $maxConcurrency,
        string $prefix = 'task:limiter:'
    ) {
        parent::__construct($store, $maxConcurrency, $prefix);
    }

    public static function create(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $maxConcurrency = 10,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'task:limiter:'
    ): self {
        return new self(
            RedisStore::create($host, $port, 'kode:limiting:', $password, $database),
            $maxConcurrency,
            $prefix
        );
    }

    public function tryAcquire(string $taskId, int $ttl = 3600): bool
    {
        return $this->doTryAcquire($taskId, $ttl);
    }

    public function acquire(string $taskId, float $timeout = 30.0, int $ttl = 3600): bool
    {
        return $this->doAcquire($taskId, $timeout, $ttl, 10_000);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(string $taskId, callable $callback, int $ttl = 3600, float $timeout = 30.0): mixed
    {
        if (!$this->acquire($taskId, $timeout, $ttl)) {
            throw new \RuntimeException("获取任务许可超时：{$taskId}");
        }

        try {
            return $callback();
        } finally {
            $this->release($taskId);
        }
    }

    public function release(string $taskId): void
    {
        $this->doRelease($taskId);
    }

    public function getMaxConcurrency(): int
    {
        return $this->maxSlots;
    }
}
