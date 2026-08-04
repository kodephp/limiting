<?php

declare(strict_types=1);

namespace Kode\Limiting\Distributed;

use Kode\Limiting\Store\RedisStore;

/**
 * 分布式 Fiber 协程限流器（跨机器）
 *
 * 使用 Redis 协调多机器间的协程并发上限。
 */
class DistributedFiberLimiter extends AbstractDistributedSemaphore
{
    public function __construct(
        RedisStore $store,
        int $maxFibers,
        string $prefix = 'fiber:limiter:'
    ) {
        parent::__construct($store, $maxFibers, $prefix);
    }

    public static function create(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $maxFibers = 100,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'fiber:limiter:'
    ): self {
        return new self(
            RedisStore::create($host, $port, 'kode:limiting:', $password, $database),
            $maxFibers,
            $prefix
        );
    }

    /**
     * v1.x 遗留命名，保留为 create() 的别名
     */
    public static function createStore(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $maxFibers = 100,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'fiber:limiter:'
    ): self {
        return self::create($host, $port, $maxFibers, $password, $database, $prefix);
    }

    public static function isSupported(): bool
    {
        return class_exists(\Fiber::class);
    }

    public function tryAcquire(string $fiberId, int $ttl = 3600): bool
    {
        return $this->doTryAcquire($fiberId, $ttl);
    }

    /**
     * 等待槽位；在协程内让出执行权，在主线程内退化为轮询等待
     */
    public function acquire(string $fiberId, float $timeout = 30.0, int $ttl = 3600): bool
    {
        $start = microtime(true);

        while (!$this->tryAcquire($fiberId, $ttl)) {
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
     * 在并发额度内创建并启动 Fiber
     *
     * @return \Fiber<mixed, mixed, mixed, mixed>|null
     */
    public function start(string $fiberId, callable $callback, int $ttl = 3600): ?\Fiber
    {
        if (!self::isSupported() || !$this->tryAcquire($fiberId, $ttl)) {
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

    public function release(string $fiberId): void
    {
        $this->doRelease($fiberId);
    }

    public function getMaxFibers(): int
    {
        return $this->maxSlots;
    }
}
