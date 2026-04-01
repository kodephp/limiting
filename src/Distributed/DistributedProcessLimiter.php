<?php

declare(strict_types=1);

namespace Kode\Limiting\Distributed;

use Kode\Limiting\Store\RedisStore;

/**
 * 分布式进程限流器（跨机器）
 *
 * 使用 Redis 协调多机器间的进程并发控制
 */
class DistributedProcessLimiter
{
    private RedisStore $store;
    private int $maxProcesses;
    private string $prefix;

    private const LUA_ACQUIRE = <<<'LUA'
local key = KEYS[1]
local active_key = KEYS[2]
local max_count = tonumber(ARGV[1])
local process_id = ARGV[2]
local ttl = tonumber(ARGV[3])

local active_count = tonumber(redis.call('GET', active_key) or '0')

if active_count >= max_count then
    return 0
end

redis.call('INCR', active_key)
redis.call('EXPIRE', active_key, ttl)
redis.call('HSET', key, process_id, '1')
redis.call('EXPIRE', key, ttl)

return 1
LUA;

    private const LUA_RELEASE = <<<'LUA'
local key = KEYS[1]
local active_key = KEYS[2]
local process_id = ARGV[1]

redis.call('HDEL', key, process_id)
redis.call('DECR', active_key)

return 1
LUA;

    private static ?self $instance = null;

    public function __construct(
        RedisStore $store,
        int $maxProcesses,
        string $prefix = 'process:limiter:'
    ) {
        $this->store = $store;
        $this->maxProcesses = $maxProcesses;
        $this->prefix = $prefix;
    }

    /**
     * 创建分布式进程限流器（单机模式）
     */
    public static function create(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $maxProcesses = 10,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'process:limiter:'
    ): self {
        return new self(
            RedisStore::create($host, $port, $prefix, $password, $database),
            $maxProcesses,
            $prefix
        );
    }

    /**
     * 获取单例实例
     */
    public static function getInstance(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $maxProcesses = 10,
        ?string $password = null,
        int $database = 0
    ): self {
        $key = "{$host}:{$port}:{$maxProcesses}:{$database}";

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = self::create($host, $port, $maxProcesses, $password, $database);
        }

        return self::$instances[$key];
    }

    private static array $instances = [];

    /**
     * 尝试获取进程许可（非阻塞）
     */
    public function tryAcquire(string $processId, int $ttl = 3600): bool
    {
        $key = $this->prefix . 'processes:' . $processId;
        $activeKey = $this->prefix . 'active';

        $result = $this->store->eval(
            self::LUA_ACQUIRE,
            [$key, $activeKey],
            2,
            $this->maxProcesses,
            $processId,
            $ttl
        );

        return $result === 1 || $result === true;
    }

    /**
     * 阻塞等待获取许可
     */
    public function acquire(string $processId, float $timeout = 30.0, int $ttl = 3600): bool
    {
        $start = microtime(true);

        while (!$this->tryAcquire($processId, $ttl)) {
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
        $key = $this->prefix . 'processes:' . $processId;
        $activeKey = $this->prefix . 'active';

        $this->store->eval(self::LUA_RELEASE, [$key, $activeKey], 2, $processId);
    }

    /**
     * 获取当前活跃进程数
     */
    public function getActiveCount(): int
    {
        $activeKey = $this->prefix . 'active';
        $count = $this->store->get($activeKey);
        return (int) ($count ?? 0);
    }

    /**
     * 获取最大进程数
     */
    public function getMaxProcesses(): int
    {
        return $this->maxProcesses;
    }

    /**
     * 检查健康状态
     */
    public function isHealthy(): bool
    {
        return $this->store->isHealthy();
    }
}
