<?php

declare(strict_types=1);

namespace Kode\Limiting\Distributed;

use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Store\RedisStore;

/**
 * 分布式限流器（跨机器限流）
 *
 * 支持单机、Sentinel、Cluster 模式
 * 使用 Lua 脚本保证原子性
 * 使用 PHP 8.2 readonly 属性优化性能
 */
class DistributedLimiter
{
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

    private readonly string $bucketPrefix;

    public function __construct(
        private readonly RedisStore $store,
        private readonly int $capacity,
        private readonly float $refillRate,
        private readonly int $ttl = 3600,
        private readonly string $prefix = 'limiter:'
    ) {
        $this->bucketPrefix = $this->prefix . 'bucket:';
    }

    public static function fromConfig(RedisStore $store, LimiterConfig $config): self
    {
        return new self($store, $config->capacity, $config->refillRate, $config->ttl, $config->prefix);
    }

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

    public function allow(string $key, int $tokens = 1): bool
    {
        $bucketKey = $this->bucketPrefix . $key;

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

    public function tryAcquire(string $key, int $tokens = 1): bool
    {
        return $this->allow($key, $tokens);
    }

    public function check(string $key, int $tokens = 1): LimiterResult
    {
        $remaining = $this->getRemaining($key);

        if ($remaining >= $tokens) {
            return LimiterResult::allowed($remaining - $tokens);
        }

        return LimiterResult::denied($this->getWaitTime($key));
    }

    public function getRemaining(string $key): float
    {
        $bucketKey = $this->bucketPrefix . $key;
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

    public function getWaitTime(string $key): float
    {
        $remaining = $this->getRemaining($key);
        if ($remaining >= 1) {
            return 0.0;
        }
        return (1.0 - $remaining) / $this->refillRate;
    }

    public function reset(string $key): void
    {
        $this->store->delete($this->bucketPrefix . $key);
    }

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

    public function getStore(): RedisStore
    {
        return $this->store;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getRefillRate(): float
    {
        return $this->refillRate;
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }
}
