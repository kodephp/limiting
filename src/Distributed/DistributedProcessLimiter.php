<?php

declare(strict_types=1);

namespace Kode\Limiting\Distributed;

use Kode\Limiting\Store\RedisStore;

/**
 * 分布式进程限流器（跨机器）
 *
 * 使用 Redis 协调多机器间的进程并发上限。
 */
class DistributedProcessLimiter extends AbstractDistributedSemaphore
{
    /** @var array<string, self> */
    private static array $instances = [];

    public function __construct(
        RedisStore $store,
        int $maxProcesses,
        string $prefix = 'process:limiter:'
    ) {
        parent::__construct($store, $maxProcesses, $prefix);
    }

    public static function create(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $maxProcesses = 10,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'process:limiter:'
    ): self {
        return new self(
            RedisStore::create($host, $port, 'kode:limiting:', $password, $database),
            $maxProcesses,
            $prefix
        );
    }

    /**
     * 获取共享实例
     */
    public static function getInstance(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $maxProcesses = 10,
        ?string $password = null,
        int $database = 0
    ): self {
        $cacheKey = "{$host}:{$port}:{$maxProcesses}:{$database}";

        return self::$instances[$cacheKey] ??= self::create($host, $port, $maxProcesses, $password, $database);
    }

    public static function flushInstances(): void
    {
        self::$instances = [];
    }

    public function tryAcquire(string $processId, int $ttl = 3600): bool
    {
        return $this->doTryAcquire($processId, $ttl);
    }

    public function acquire(string $processId, float $timeout = 30.0, int $ttl = 3600): bool
    {
        return $this->doAcquire($processId, $timeout, $ttl, 100_000);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(string $processId, callable $callback, int $ttl = 3600, float $timeout = 30.0): mixed
    {
        if (!$this->acquire($processId, $timeout, $ttl)) {
            throw new \RuntimeException("获取进程许可超时：{$processId}");
        }

        try {
            return $callback();
        } finally {
            $this->release($processId);
        }
    }

    public function release(string $processId): void
    {
        $this->doRelease($processId);
    }

    public function getMaxProcesses(): int
    {
        return $this->maxSlots;
    }
}
