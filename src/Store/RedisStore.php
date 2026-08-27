<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

use Kode\Limiting\Exception\StoreException;
use Kode\Limiting\Exception\UnsupportedDriverException;

/**
 * Redis 存储实现（分布式）
 *
 * 支持单机、Sentinel、Cluster 三种模式。
 *
 * v2.0 关键修复：
 *  - evalScript() 明确区分 KEYS 与 ARGV，此前分布式限流器传入的
 *    参数全部被丢弃，导致 Lua 脚本内 ARGV 为空、限流完全失效。
 *  - 内置 EVALSHA 脚本缓存，减少每次调用的脚本传输开销。
 */
class RedisStore implements StoreInterface
{
    private readonly string $prefix;

    /** @var array<string, string> Lua 脚本 SHA1 缓存 */
    private array $scriptCache = [];

    /**
     * @param \Redis|\RedisCluster $redis Redis 客户端实例
     */
    public function __construct(
        private readonly \Redis|\RedisCluster $redis,
        string $prefix = 'kode:limiting:'
    ) {
        $this->prefix = $prefix;
    }

    private static function assertExtension(): void
    {
        if (!extension_loaded('redis')) {
            throw UnsupportedDriverException::extensionMissing(
                'Redis',
                'pecl install redis',
                'https://www.php.net/manual/zh/book.redis.php'
            );
        }
    }

    /**
     * 创建单机 Redis 存储实例
     */
    public static function create(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $prefix = 'kode:limiting:',
        ?string $password = null,
        int $database = 0,
        float $timeout = 1.0
    ): self {
        self::assertExtension();

        $redis = new \Redis();

        try {
            $redis->connect($host, $port, $timeout);

            if ($password !== null && $password !== '') {
                $redis->auth($password);
            }

            $redis->select($database);
        } catch (\Throwable $e) {
            throw StoreException::connectionFailed('Redis', $e->getMessage());
        }

        return new self($redis, $prefix);
    }

    /**
     * 创建 Redis Sentinel 高可用存储实例
     *
     * @param list<string> $sentinels Sentinel 地址列表，形如 127.0.0.1:26379
     */
    public static function createSentinel(
        array $sentinels,
        string $masterName,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'kode:limiting:',
        float $timeout = 1.0
    ): self {
        self::assertExtension();

        foreach ($sentinels as $sentinel) {
            try {
                [$host, $port] = array_pad(explode(':', $sentinel, 2), 2, '26379');

                $probe = new \Redis();
                $probe->connect($host, (int) $port, $timeout);

                /** @var array{0: string, 1: string}|false $master */
                $master = $probe->rawCommand('SENTINEL', 'get-master-addr-by-name', $masterName);
                $probe->close();

                if (!is_array($master) || count($master) < 2) {
                    continue;
                }

                $redis = new \Redis();
                $redis->connect($master[0], (int) $master[1], $timeout);

                if ($password !== null && $password !== '') {
                    $redis->auth($password);
                }

                $redis->select($database);

                return new self($redis, $prefix);
            } catch (\Throwable) {
                continue;
            }
        }

        throw StoreException::connectionFailed('Redis Sentinel', "无法定位主节点 {$masterName}");
    }

    /**
     * 创建 Redis Cluster 存储实例
     *
     * @param list<string> $nodes
     */
    public static function createCluster(
        array $nodes,
        ?string $password = null,
        string $prefix = 'kode:limiting:',
        float $timeout = 1.0
    ): self {
        self::assertExtension();

        try {
            $redis = new \RedisCluster(null, $nodes, $timeout, $timeout, false, $password);
        } catch (\Throwable $e) {
            throw StoreException::connectionFailed('Redis Cluster', $e->getMessage());
        }

        return new self($redis, $prefix);
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    #[\Override]
    public function get(string $key): ?string
    {
        $value = $this->redis->get($this->key($key));

        if ($value === false || $value === null) {
            return null;
        }

        /** @var string $value */
        return is_string($value) ? $value : (string) $value;
    }

    #[\Override]
    public function set(string $key, string $value, int $ttl = 0): void
    {
        if ($ttl > 0) {
            $this->redis->setex($this->key($key), $ttl, $value);
        } else {
            $this->redis->set($this->key($key), $value);
        }
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->redis->del($this->key($key));
    }

    #[\Override]
    public function incr(string $key, int $step = 1, int $ttl = 0): int
    {
        $realKey = $this->key($key);
        $value = (int) $this->redis->incrBy($realKey, $step);

        // 仅首次创建时设置过期，避免每次递增都续期造成窗口漂移
        if ($ttl > 0 && $value === $step) {
            $this->redis->expire($realKey, $ttl);
        }

        return $value;
    }

    #[\Override]
    public function decr(string $key, int $step = 1): int
    {
        return (int) $this->redis->decrBy($this->key($key), $step);
    }

    #[\Override]
    public function ttl(string $key): int
    {
        return (int) $this->redis->ttl($this->key($key));
    }

    #[\Override]
    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->key($key));
    }

    /**
     * 执行 Lua 脚本（原子操作）
     *
     * @param list<string> $keys Lua 中的 KEYS（会自动补前缀）
     * @param list<int|float|string> $args Lua 中的 ARGV
     */
    public function evalScript(string $script, array $keys = [], array $args = []): mixed
    {
        $prefixedKeys = array_map(fn (string $k): string => $this->key($k), $keys);
        $payload = [...$prefixedKeys, ...array_map(static fn ($v): string => (string) $v, $args)];
        $numKeys = count($prefixedKeys);

        $sha = $this->scriptCache[$script] ??= $this->loadScript($script);

        if ($sha !== '') {
            $result = $this->redis->evalSha($sha, $payload, $numKeys);

            if ($result !== false || $this->lastErrorIsNoScript()) {
                if ($result !== false) {
                    return $result;
                }

                unset($this->scriptCache[$script]);
            }
        }

        return $this->redis->eval($script, $payload, $numKeys);
    }

    /**
     * 向后兼容的别名
     *
     * @param list<string> $keys
     * @param list<int|float|string> $args
     */
    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        return $this->evalScript($script, $keys, $args);
    }

    private function loadScript(string $script): string
    {
        try {
            $sha = $this->redis instanceof \RedisCluster
                ? $this->redis->script($this->redis->_masters()[0] ?? 'default', 'load', $script)
                : $this->redis->script('load', $script);

            return is_string($sha) ? $sha : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function lastErrorIsNoScript(): bool
    {
        $error = $this->redis->getLastError();

        return is_string($error) && str_contains($error, 'NOSCRIPT');
    }

    public function getClient(): \Redis|\RedisCluster
    {
        return $this->redis;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * 健康检查
     */
    public function isHealthy(): bool
    {
        try {
            $pong = $this->redis instanceof \RedisCluster
                ? $this->redis->ping($this->redis->_masters()[0] ?? 'default')
                : $this->redis->ping();

            return $pong === true || $pong === '+PONG' || $pong === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }
}
