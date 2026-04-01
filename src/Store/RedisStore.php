<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

/**
 * Redis 存储实现（分布式使用）
 *
 * 支持单机、Sentinel、Cluster 模式
 * 使用 PHP 8.2 readonly 属性优化性能
 */
class RedisStore implements StoreInterface
{
    private readonly string $prefix;

    public function __construct(
        private readonly \Redis|\RedisCluster $redis,
        string $prefix = 'kode:limiting:'
    ) {
        $this->prefix = $prefix;
    }

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

    public function eval(string $script, array $keys = [], int $numKeys = 0): mixed
    {
        return $this->redis->eval($script, $keys, $numKeys);
    }

    public function getClient(): \Redis|\RedisCluster
    {
        return $this->redis;
    }

    public function isHealthy(): bool
    {
        try {
            $pong = $this->redis->ping();
            return $pong === true || $pong === '+PONG' || $pong === 'PONG';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
