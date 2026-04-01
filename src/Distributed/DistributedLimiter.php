<?php

declare(strict_types=1);

namespace Kode\Limiting\Distributed;

use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Store\RedisStore;

/**
 * 分布式限流器（跨机器限流）
 *
 * 支持单机、Sentinel、Cluster 模式
 * 使用 Lua 脚本保证原子性
 */
class DistributedLimiter
{
    private RedisStore $store;
    private int $capacity;
    private float $refillRate;
    private int $ttl;
    private string $prefix;

    private const LUA_ALLOW = <<<'LUA'
local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill_rate = tonumber(ARGV[2])
local tokens = tonumber(ARGV[3])
local now = tonumber(ARGV[4])
local ttl = tonumber(ARGV[5])

local bucket = redis.call('GET', key)
if bucket == false then
    redis.call('SET', key, cjson.encode({tokens = capacity - tokens, last_update = now}), 'EX', ttl)
    return 1
end

local data = cjson.decode(bucket)
local elapsed = now - data.last_update
local refill_amount = elapsed * refill_rate
data.tokens = math.min(capacity, data.tokens + refill_amount)
data.last_update = now

if data.tokens >= tokens then
    data.tokens = data.tokens - tokens
    redis.call('SET', key, cjson.encode(data), 'EX', ttl)
    return 1
end

redis.call('SET', key, cjson.encode(data), 'EX', ttl)
return 0
LUA;

    public function __construct(
        RedisStore $store,
        int $capacity,
        float $refillRate,
        int $ttl = 3600,
        string $prefix = 'limiter:'
    ) {
        $this->store = $store;
        $this->capacity = $capacity;
        $this->refillRate = $refillRate;
        $this->ttl = $ttl;
        $this->prefix = $prefix;
    }

    /**
     * 创建分布式限流器（单机模式）
     */
    public static function create(
        string $host = '127.0.0.1',
        int $port = 6379,
        int $capacity = 100,
        float $refillRate = 10.0,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'limiter:'
    ): self {
        return new self(
            RedisStore::create($host, $port, $prefix, $password, $database),
            $capacity,
            $refillRate
        );
    }

    /**
     * 创建分布式限流器（Sentinel 高可用模式）
     */
    public static function createSentinel(
        array $sentinels,
        string $masterName,
        int $capacity = 100,
        float $refillRate = 10.0,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'limiter:'
    ): self {
        return new self(
            RedisStore::createSentinel($sentinels, $masterName, $password, $database, $prefix),
            $capacity,
            $refillRate
        );
    }

    /**
     * 创建分布式限流器（Cluster 模式）
     */
    public static function createCluster(
        array $nodes,
        int $capacity = 100,
        float $refillRate = 10.0,
        ?string $password = null,
        string $prefix = 'limiter:'
    ): self {
        return new self(
            RedisStore::createCluster($nodes, $password, $prefix),
            $capacity,
            $refillRate
        );
    }

    /**
     * 检查是否允许请求（原子操作）
     */
    public function allow(string $key, int $tokens = 1): bool
    {
        $bucketKey = $this->prefix . 'bucket:' . $key;

        $result = $this->store->eval(
            self::LUA_ALLOW,
            [$bucketKey],
            1,
            $this->capacity,
            $this->refillRate,
            $tokens,
            microtime(true),
            $this->ttl
        );

        return $result === 1 || $result === true;
    }

    /**
     * 尝试获取（别名方法，兼容本地限流器）
     */
    public function tryAcquire(string $key, int $tokens = 1): bool
    {
        return $this->allow($key, $tokens);
    }

    /**
     * 获取剩余令牌数
     */
    public function getRemaining(string $key): float
    {
        $bucketKey = $this->prefix . 'bucket:' . $key;
        $now = microtime(true);

        $data = $this->store->get($bucketKey);

        if ($data === null) {
            return (float) $this->capacity;
        }

        $bucket = json_decode($data, true);
        $elapsed = $now - $bucket['last_update'];
        $refillAmount = $elapsed * $this->refillRate;

        return max(0, min($this->capacity, $bucket['tokens'] + $refillAmount));
    }

    /**
     * 获取等待时间
     */
    public function getWaitTime(string $key): float
    {
        $remaining = $this->getRemaining($key);
        if ($remaining >= 1) {
            return 0.0;
        }
        return (1.0 - $remaining) / $this->refillRate;
    }

    /**
     * 重置限流器
     */
    public function reset(string $key): void
    {
        $this->store->delete($this->prefix . 'bucket:' . $key);
    }

    /**
     * 批量限流检查
     *
     * @param array $keys 键数组
     * @param int $tokens 每个键消耗的令牌数
     * @return array [成功键名数组, 失败键名数组]
     */
    public function allowBatch(array $keys, int $tokens = 1): array
    {
        $success = [];
        $failed = [];

        foreach ($keys as $key) {
            if ($this->allow($key, $tokens)) {
                $success[] = $key;
            } else {
                $failed[] = $key;
            }
        }

        return [$success, $failed];
    }

    /**
     * 获取存储实例
     */
    public function getStore(): RedisStore
    {
        return $this->store;
    }

    /**
     * 获取容量
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * 获取补充速率
     */
    public function getRefillRate(): float
    {
        return $this->refillRate;
    }
}
