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

    /**
     * 构造函数
     *
     * @param \Redis|\RedisCluster $redis Redis 客户端实例
     * @param string $prefix 键前缀
     * @throws \RuntimeException 当 Redis 扩展未安装时
     */
    public function __construct(
        private readonly \Redis|\RedisCluster $redis,
        string $prefix = 'kode:limiting:'
    ) {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException(
                'Redis 扩展未安装，请先执行：pecl install redis 或参见 https://www.php.net/manual/zh/book.redis.php'
            );
        }
        $this->prefix = $prefix;
    }

    /**
     * 创建单机 Redis 存储实例
     *
     * @param string $host Redis 服务器地址
     * @param int $port Redis 服务器端口
     * @param string $prefix 键前缀
     * @param string|null $password 密码
     * @param int $database 数据库编号
     * @return self
     * @throws \RuntimeException 当 Redis 扩展未安装时
     */
    public static function create(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $prefix = 'kode:limiting:',
        ?string $password = null,
        int $database = 0
    ): self {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException(
                'Redis 扩展未安装，请先执行：pecl install redis 或参见 https://www.php.net/manual/zh/book.redis.php'
            );
        }

        $redis = new \Redis();
        $redis->connect($host, $port, 0.0);

        if ($password !== null) {
            $redis->auth($password);
        }

        $redis->select($database);

        return new self($redis, $prefix);
    }

    /**
     * 创建 Redis Sentinel 高可用存储实例
     *
     * @param array $sentinels Sentinel 服务器列表
     * @param string $masterName 主节点名称
     * @param string|null $password 密码
     * @param int $database 数据库编号
     * @param string $prefix 键前缀
     * @return self
     * @throws \RuntimeException 当 Redis 扩展未安装或无法连接时
     */
    public static function createSentinel(
        array $sentinels,
        string $masterName,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'kode:limiting:'
    ): self {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException(
                'Redis 扩展未安装，请先执行：pecl install redis 或参见 https://www.php.net/manual/zh/book.redis.php'
            );
        }

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
     * 创建 Redis Cluster 存储实例
     *
     * @param array $nodes 集群节点列表
     * @param string|null $password 密码
     * @param string $prefix 键前缀
     * @return self
     * @throws \RuntimeException 当 Redis 扩展未安装时
     */
    public static function createCluster(
        array $nodes,
        ?string $password = null,
        string $prefix = 'kode:limiting:'
    ): self {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException(
                'Redis 扩展未安装，请先执行：pecl install redis 或参见 https://www.php.net/manual/zh/book.redis.php'
            );
        }

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

    /**
     * 生成带前缀的键名
     */
    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * 获取值
     */
    public function get(string $key): ?string
    {
        $value = $this->redis->get($this->key($key));
        return $value === false ? null : $value;
    }

    /**
     * 设置值
     */
    public function set(string $key, string $value, int $ttl = 0): void
    {
        if ($ttl > 0) {
            $this->redis->setex($this->key($key), $ttl, $value);
        } else {
            $this->redis->set($this->key($key), $value);
        }
    }

    /**
     * 删除键
     */
    public function delete(string $key): void
    {
        $this->redis->del($this->key($key));
    }

    /**
     * 原子递增
     */
    public function incr(string $key, int $step = 1): int
    {
        return $this->redis->incrBy($this->key($key), $step);
    }

    /**
     * 获取键的 TTL
     */
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
