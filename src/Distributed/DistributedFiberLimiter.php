<?php

declare(strict_types=1);

namespace Kode\Limiting\Distributed;

use Kode\Limiting\Store\RedisStore;

/**
 * 分布式 Fiber 限流器（跨机器）
 *
 * 使用 Redis 协调多机器间的 Fiber 协程并发控制
 */
class DistributedFiberLimiter
{
    private RedisStore $store;
    private int $maxFibers;
    private string $prefix;

    private const LUA_ACQUIRE = <<<'LUA'
local key = KEYS[1]
local active_key = KEYS[2]
local max_count = tonumber(ARGV[1])
local fiber_id = ARGV[2]
local ttl = tonumber(ARGV[3])

local active_count = tonumber(redis.call('GET', active_key) or '0')

if active_count >= max_count then
    return 0
end

redis.call('INCR', active_key)
redis.call('EXPIRE', active_key, ttl)
redis.call('HSET', key, fiber_id, '1')
redis.call('EXPIRE', key, ttl)

return 1
LUA;

    private const LUA_RELEASE = <<<'LUA'
local key = KEYS[1]
local active_key = KEYS[2]
local fiber_id = ARGV[1]

redis.call('HDEL', key, fiber_id)
redis.call('DECR', active_key)

return 1
LUA;

    private static bool $fiberSupported;

    public function __construct(
        RedisStore $store,
        int $maxFibers,
        string $prefix = 'fiber:limiter:'
    ) {
        $this->store = $store;
        $this->maxFibers = $maxFibers;
        $this->prefix = $prefix;
        self::$fiberSupported ??= class_exists(\Fiber::class);
    }

    public static function isSupported(): bool
    {
        return self::$fiberSupported;
    }

    /**
     * 创建分布式 Fiber 限流器（单机模式）
     */
    public static function createStore(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $maxFibers = 100,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'fiber:limiter:'
    ): self {
        return new self(
            RedisStore::create($host, $port, $prefix, $password, $database),
            $maxFibers,
            $prefix
        );
    }

    /**
     * 尝试获取 Fiber 许可（非阻塞）
     */
    public function tryAcquire(string $fiberId, int $ttl = 3600): bool
    {
        $key = $this->prefix . 'fibers:' . $fiberId;
        $activeKey = $this->prefix . 'active';

        $result = $this->store->eval(
            self::LUA_ACQUIRE,
            [$key, $activeKey],
            2,
            $this->maxFibers,
            $fiberId,
            $ttl
        );

        return $result === 1 || $result === true;
    }

    /**
     * 阻塞等待获取许可
     */
    public function acquire(string $fiberId, float $timeout = 30.0, int $ttl = 3600): bool
    {
        $start = microtime(true);

        while (!$this->tryAcquire($fiberId, $ttl)) {
            if (microtime(true) - $start >= $timeout) {
                return false;
            }

            if (self::$fiberSupported) {
                \Fiber::suspend();
            } else {
                usleep(10000);
            }
        }

        return true;
    }

    /**
     * 创建并启动 Fiber
     */
    public function start(string $fiberId, callable $callback, int $ttl = 3600): ?\Fiber
    {
        if (!self::$fiberSupported) {
            return null;
        }

        if (!$this->tryAcquire($fiberId, $ttl)) {
            return null;
        }

        $fiber = new \Fiber(function () use ($fiberId, $callback) {
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
     * 释放 Fiber
     */
    public function release(string $fiberId): void
    {
        $key = $this->prefix . 'fibers:' . $fiberId;
        $activeKey = $this->prefix . 'active';

        $this->store->eval(self::LUA_RELEASE, [$key, $activeKey], 2, $fiberId);
    }

    /**
     * 获取当前活跃 Fiber 数
     */
    public function getActiveCount(): int
    {
        $activeKey = $this->prefix . 'active';
        $count = $this->store->get($activeKey);
        return (int) ($count ?? 0);
    }

    /**
     * 获取最大 Fiber 数
     */
    public function getMaxFibers(): int
    {
        return $this->maxFibers;
    }

    /**
     * 检查健康状态
     */
    public function isHealthy(): bool
    {
        return $this->store->isHealthy();
    }
}
