<?php

declare(strict_types=1);

namespace Kode\Limiting\Distributed;

use Kode\Limiting\Store\RedisStore;

/**
 * 分布式并发任务限流器（跨机器）
 *
 * 使用 Redis 协调多机器间的并发任务控制
 */
class DistributedTaskLimiter
{
    private RedisStore $store;
    private int $maxConcurrency;
    private string $prefix;

    private const LUA_ACQUIRE = <<<'LUA'
local key = KEYS[1]
local active_key = KEYS[2]
local max_count = tonumber(ARGV[1])
local task_id = ARGV[2]
local ttl = tonumber(ARGV[3])

local active_count = tonumber(redis.call('GET', active_key) or '0')

if active_count >= max_count then
    return 0
end

redis.call('INCR', active_key)
redis.call('EXPIRE', active_key, ttl)
redis.call('HSET', key, task_id, '1')
redis.call('EXPIRE', key, ttl)

return 1
LUA;

    private const LUA_RELEASE = <<<'LUA'
local key = KEYS[1]
local active_key = KEYS[2]
local task_id = ARGV[1]

redis.call('HDEL', key, task_id)
redis.call('DECR', active_key)

return 1
LUA;

    public function __construct(
        RedisStore $store,
        int $maxConcurrency,
        string $prefix = 'task:limiter:'
    ) {
        $this->store = $store;
        $this->maxConcurrency = $maxConcurrency;
        $this->prefix = $prefix;
    }

    /**
     * 创建分布式任务限流器（单机模式）
     */
    public static function create(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $maxConcurrency = 10,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'task:limiter:'
    ): self {
        return new self(
            RedisStore::create($host, $port, $prefix, $password, $database),
            $maxConcurrency,
            $prefix
        );
    }

    /**
     * 尝试获取任务许可（非阻塞）
     */
    public function tryAcquire(string $taskId, int $ttl = 3600): bool
    {
        $key = $this->prefix . 'tasks:' . $taskId;
        $activeKey = $this->prefix . 'active';

        $result = $this->store->eval(
            self::LUA_ACQUIRE,
            [$key, $activeKey],
            2,
            $this->maxConcurrency,
            $taskId,
            $ttl
        );

        return $result === 1 || $result === true;
    }

    /**
     * 阻塞等待获取许可
     */
    public function acquire(string $taskId, float $timeout = 30.0, int $ttl = 3600): bool
    {
        $start = microtime(true);

        while (!$this->tryAcquire($taskId, $ttl)) {
            if (microtime(true) - $start >= $timeout) {
                return false;
            }
            usleep(10000);
        }

        return true;
    }

    /**
     * 执行任务（自动获取和释放）
     */
    public function run(string $taskId, callable $callback, int $ttl = 3600): mixed
    {
        if (!$this->acquire($taskId, 30.0, $ttl)) {
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
        $key = $this->prefix . 'tasks:' . $taskId;
        $activeKey = $this->prefix . 'active';

        $this->store->eval(self::LUA_RELEASE, [$key, $activeKey], 2, $taskId);
    }

    /**
     * 获取当前活跃任务数
     */
    public function getActiveCount(): int
    {
        $activeKey = $this->prefix . 'active';
        $count = $this->store->get($activeKey);
        return (int) ($count ?? 0);
    }

    /**
     * 获取最大并发数
     */
    public function getMaxConcurrency(): int
    {
        return $this->maxConcurrency;
    }

    /**
     * 检查健康状态
     */
    public function isHealthy(): bool
    {
        return $this->store->isHealthy();
    }
}
