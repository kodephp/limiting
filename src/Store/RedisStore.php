<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

/**
 * Redis 存储实现（分布式使用）
 *
 * 支持连接池、Lua 脚本原子操作、Sentinel/Cluster 模式
 */
class RedisStore implements StoreInterface
{
    private \Redis|\RedisCluster $redis;
    private string $prefix;
    private array $config;

    public function __construct(\Redis|\RedisCluster $redis, string $prefix = 'kode:limiting:', array $config = [])
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->config = array_merge([
            'pool_size' => 10,
            'timeout' => 0.0,
            'persistent' => false,
        ], $config);
    }

    /**
     * 创建单机 Redis 连接
     */
    public static function create(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $prefix = 'kode:limiting:',
        ?string $password = null,
        int $database = 0
    ): self {
        $redis = new \Redis();
        $redis->connect($host, $port, 0.0);

        if ($password !== null) {
            $redis->auth($password);
        }

        $redis->select($database);

        return new self($redis, $prefix);
    }

    /**
     * 创建 Redis Sentinel 连接（高可用）
     */
    public static function createSentinel(
        array $sentinels,
        string $masterName,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'kode:limiting:'
    ): self {
        $redis = new \Redis();

        foreach ($sentinels as $sentinel) {
            try {
                [$host, $port] = explode(':', $sentinel);
                $redis->connect($host, (int) $port, 1.0);

                $master = $redis->eval(
                    "return redis.call('SENTINEL', 'GET-MASTER-ADDR-BY-NAME', ARGV[1])",
                    [$masterName],
                    1
                );

                if ($master) {
                    $redis->connect($master[0], (int) $master[1], 1.0);

                    if ($password !== null) {
                        $redis->auth($password);
                    }
                    $redis->select($database);

                    return new self($redis, $prefix);
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        throw new \RuntimeException('无法连接到 Redis Sentinel 主节点');
    }

    /**
     * 创建 Redis Cluster 连接（分片）
     */
    public static function createCluster(
        array $nodes,
        ?string $password = null,
        string $prefix = 'kode:limiting:'
    ): self {
        $redis = new \RedisCluster(
            null,
            $nodes,
            1.0,
            1.0,
            false,
            $password
        );

        return new self($redis, $prefix);
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key): ?string
    {
        $value = $this->redis->get($this->key($key));
        return $value === false ? null : $value;
    }

    public function set(string $key, string $value, int $ttl = 0): void
    {
        if ($ttl > 0) {
            $this->redis->setex($this->key($key), $ttl, $value);
        } else {
            $this->redis->set($this->key($key), $value);
        }
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->key($key));
    }

    public function incr(string $key, int $step = 1): int
    {
        return $this->redis->incrBy($this->key($key), $step);
    }

    public function ttl(string $key): int
    {
        return $this->redis->ttl($this->key($key));
    }

    /**
     * 执行 Lua 脚本（原子操作）
     */
    public function eval(string $script, array $keys = [], int $numKeys = 0): mixed
    {
        return $this->redis->eval($script, $keys, $numKeys);
    }

    /**
     * 获取 Redis 客户端实例
     */
    public function getClient(): \Redis|\RedisCluster
    {
        return $this->redis;
    }

    /**
     * 健康检查
     */
    public function isHealthy(): bool
    {
        try {
            return $this->redis->ping() === '+PONG' || $this->redis->ping() === true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
