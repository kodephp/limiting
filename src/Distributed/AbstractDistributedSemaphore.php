<?php

declare(strict_types=1);

namespace Kode\Limiting\Distributed;

use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Store\RedisStore;

/**
 * 分布式计数信号量基类（v2.0 新增）
 *
 * v1.x 的 DistributedTaskLimiter / DistributedProcessLimiter /
 * DistributedFiberLimiter 是三份逐字复制的代码，且共享同样的两个缺陷：
 *
 *  1. eval() 传参方式错误，Lua 的 ARGV 全为空 —— 功能实际不可用；
 *  2. release() 无条件 DECR，重复释放会把活跃计数打成负数，
 *     进而导致并发上限被击穿。
 *
 * 本基类统一修复上述问题：HDEL 命中才 DECR，并把计数钳制在 0 以上。
 */
abstract class AbstractDistributedSemaphore
{
    protected const string LUA_ACQUIRE = <<<'LUA'
local holders_key = KEYS[1]
local active_key = KEYS[2]
local max_count = tonumber(ARGV[1])
local holder_id = ARGV[2]
local ttl = tonumber(ARGV[3])

-- 幂等：已持有则仅续期，不重复占位
if redis.call('HEXISTS', holders_key, holder_id) == 1 then
    redis.call('EXPIRE', holders_key, ttl)
    redis.call('EXPIRE', active_key, ttl)
    return 1
end

local active = tonumber(redis.call('GET', active_key) or '0')
if active < 0 then
    active = 0
    redis.call('SET', active_key, '0')
end

if active >= max_count then
    return 0
end

redis.call('INCR', active_key)
redis.call('EXPIRE', active_key, ttl)
redis.call('HSET', holders_key, holder_id, ARGV[4])
redis.call('EXPIRE', holders_key, ttl)

return 1
LUA;

    protected const string LUA_RELEASE = <<<'LUA'
local holders_key = KEYS[1]
local active_key = KEYS[2]
local holder_id = ARGV[1]

-- 只有确实持有槽位时才递减，避免重复释放把计数打成负数
if redis.call('HDEL', holders_key, holder_id) == 0 then
    return 0
end

local active = tonumber(redis.call('DECR', active_key))
if active < 0 then
    redis.call('SET', active_key, '0')
end

return 1
LUA;

    public function __construct(
        protected readonly RedisStore $store,
        protected readonly int $maxSlots,
        protected readonly string $prefix
    ) {
        if ($maxSlots <= 0) {
            throw InvalidConfigurationException::positiveRequired('maxSlots', $maxSlots);
        }
    }

    /**
     * 持有者哈希键（子类可覆盖以区分命名空间）
     */
    protected function holdersKey(): string
    {
        return $this->prefix . 'holders';
    }

    protected function activeKey(): string
    {
        return $this->prefix . 'active';
    }

    protected function doTryAcquire(string $holderId, int $ttl): bool
    {
        if (trim($holderId) === '') {
            throw InvalidConfigurationException::emptyKey();
        }

        $result = $this->store->evalScript(
            static::LUA_ACQUIRE,
            [$this->holdersKey(), $this->activeKey()],
            [$this->maxSlots, $holderId, $ttl, time() + $ttl]
        );

        return $result === 1 || $result === true;
    }

    protected function doRelease(string $holderId): bool
    {
        $result = $this->store->evalScript(
            static::LUA_RELEASE,
            [$this->holdersKey(), $this->activeKey()],
            [$holderId]
        );

        return $result === 1 || $result === true;
    }

    protected function doAcquire(string $holderId, float $timeout, int $ttl, int $intervalUsec): bool
    {
        $start = microtime(true);

        while (!$this->doTryAcquire($holderId, $ttl)) {
            if (microtime(true) - $start >= $timeout) {
                return false;
            }

            usleep(max(1_000, $intervalUsec));
        }

        return true;
    }

    /**
     * 当前活跃槽位数
     */
    public function getActiveCount(): int
    {
        return max(0, (int) ($this->store->get($this->activeKey()) ?? 0));
    }

    public function getAvailable(): int
    {
        return max(0, $this->maxSlots - $this->getActiveCount());
    }

    public function getMaxSlots(): int
    {
        return $this->maxSlots;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getStore(): RedisStore
    {
        return $this->store;
    }

    /**
     * 清空全部槽位
     */
    public function reset(): void
    {
        $this->store->delete($this->holdersKey());
        $this->store->delete($this->activeKey());
    }

    public function isHealthy(): bool
    {
        return $this->store->isHealthy();
    }
}
