<?php

declare(strict_types=1);

namespace Kode\Limiting\Distributed;

use Kode\Limiting\Algorithm\AbstractRateLimiter;
use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Store\RedisStore;

/**
 * 分布式令牌桶限流器（跨机器）
 *
 * 通过 Lua 脚本在 Redis 侧完成"读取 → 补充 → 扣减 → 写回"，保证原子性。
 *
 * v2.0 关键修复：
 *  v1.x 调用 $store->eval($script, [$key], 1, $capacity, $rate, ...) —— 而
 *  RedisStore::eval() 只接受 3 个参数，多余的实参被静默丢弃，Lua 里的
 *  ARGV 全部为 nil，tonumber(nil) 返回 nil 后脚本直接报错，
 *  即"分布式限流从来没有真正生效过"。现按 KEYS / ARGV 正确分离传参。
 */
class DistributedLimiter extends AbstractRateLimiter
{
    private const string LUA_ALLOW = <<<'LUA'
local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill_rate = tonumber(ARGV[2])
local tokens = tonumber(ARGV[3])
local now = tonumber(ARGV[4])
local ttl = tonumber(ARGV[5])

if tokens > capacity then
    return 0
end

local raw = redis.call('GET', key)

if raw == false then
    redis.call('SET', key, cjson.encode({tokens = capacity - tokens, last_update = now}), 'EX', ttl)
    return 1
end

local ok, data = pcall(cjson.decode, raw)
if not ok or type(data) ~= 'table' then
    data = {tokens = capacity, last_update = now}
end

local elapsed = now - (tonumber(data.last_update) or now)
if elapsed < 0 then elapsed = 0 end

local available = math.min(capacity, (tonumber(data.tokens) or 0) + elapsed * refill_rate)
if available < 0 then available = 0 end

if available >= tokens then
    redis.call('SET', key, cjson.encode({tokens = available - tokens, last_update = now}), 'EX', ttl)
    return 1
end

redis.call('SET', key, cjson.encode({tokens = available, last_update = now}), 'EX', ttl)
return 0
LUA;

    private readonly string $bucketPrefix;

    public function __construct(
        private readonly RedisStore $redis,
        int $capacity,
        private readonly float $refillRate,
        int $ttl = 3600,
        string $prefix = 'limiter:'
    ) {
        parent::__construct($redis, $capacity, $ttl, $prefix);

        if ($refillRate <= 0) {
            throw InvalidConfigurationException::positiveRequired('refillRate', $refillRate);
        }

        $this->bucketPrefix = $prefix . 'bucket:';
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
        #[\SensitiveParameter] ?string $password = null,
        int $database = 0,
        string $prefix = 'limiter:'
    ): self {
        return new self(
            RedisStore::create($host, $port, 'kode:limiting:', $password, $database),
            $capacity,
            $refillRate,
            3600,
            $prefix
        );
    }

    /**
     * @param list<string> $sentinels
     */
    public static function createSentinel(
        array $sentinels,
        string $masterName,
        int $capacity = 100,
        float $refillRate = 10.0,
        #[\SensitiveParameter] ?string $password = null,
        int $database = 0,
        string $prefix = 'limiter:'
    ): self {
        return new self(
            RedisStore::createSentinel($sentinels, $masterName, $password, $database),
            $capacity,
            $refillRate,
            3600,
            $prefix
        );
    }

    /**
     * @param list<string> $nodes
     */
    public static function createCluster(
        array $nodes,
        int $capacity = 100,
        float $refillRate = 10.0,
        #[\SensitiveParameter] ?string $password = null,
        string $prefix = 'limiter:'
    ): self {
        return new self(
            RedisStore::createCluster($nodes, $password),
            $capacity,
            $refillRate,
            3600,
            $prefix
        );
    }

    #[\Override]
    public function allow(string $key, #[\SensitiveParameter] int $tokens = 1): bool
    {
        $this->assertKey($key);
        $this->assertTokens($tokens);

        if ($tokens > $this->capacity) {
            return false;
        }

        $result = $this->redis->evalScript(
            self::LUA_ALLOW,
            [$this->bucketPrefix . $key],
            [$this->capacity, $this->refillRate, $tokens, microtime(true), $this->ttl]
        );

        return $result === 1 || $result === true;
    }

    public function tryAcquire(string $key, #[\SensitiveParameter] int $tokens = 1): bool
    {
        return $this->allow($key, $tokens);
    }

    #[\Override]
    public function getRemaining(string $key): float
    {
        $this->assertKey($key);

        $state = $this->readState($this->bucketPrefix . $key);

        if ($state === null) {
            return (float) $this->capacity;
        }

        $now = microtime(true);
        $elapsed = max(0.0, $now - (float) ($state['last_update'] ?? $now));

        return max(0.0, min(
            (float) $this->capacity,
            (float) ($state['tokens'] ?? 0) + $elapsed * $this->refillRate
        ));
    }

    #[\Override]
    public function getWaitTime(string $key): float
    {
        $remaining = $this->getRemaining($key);

        if ($remaining >= 1) {
            return 0.0;
        }

        return (1.0 - $remaining) / $this->refillRate;
    }

    #[\Override]
    public function reset(string $key): void
    {
        $this->assertKey($key);
        $this->redis->delete($this->bucketPrefix . $key);
    }

    /**
     * 批量判定
     *
     * @param list<string> $keys
     * @return array{0: list<string>, 1: list<string>} [放行的 key, 被限流的 key]
     */
    public function allowBatch(array $keys, #[\SensitiveParameter] int $tokens = 1): array
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

    public function getRedisStore(): RedisStore
    {
        return $this->redis;
    }

    public function getRefillRate(): float
    {
        return $this->refillRate;
    }

    public function isHealthy(): bool
    {
        return $this->redis->isHealthy();
    }
}
