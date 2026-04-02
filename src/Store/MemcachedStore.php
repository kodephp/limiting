<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

/**
 * Memcached 存储实现
 *
 * 使用 Memcached 进行分布式存储
 * 适合多机器共享限流状态
 */
class MemcachedStore implements StoreInterface
{
    private readonly string $prefix;

    public function __construct(
        private readonly \Memcached $memcached,
        string $prefix = 'kode:limiting:'
    ) {
        $this->prefix = $prefix;
    }

    /**
     * 创建 Memcached 存储实例
     *
     * @param string $host Memcached 服务器地址
     * @param int $port Memcached 服务器端口
     * @param string $prefix 键前缀
     * @return self
     */
    public static function create(
        string $host = '127.0.0.1',
        int $port = 11211,
        string $prefix = 'kode:limiting:'
    ): self {
        $memcached = new \Memcached();
        $memcached->addServer($host, $port);

        return new self($memcached, $prefix);
    }

    /**
     * 添加多个服务器
     *
     * @param array $servers 服务器列表 [[host, port], ...]
     * @return self
     */
    public function addServers(array $servers): self
    {
        $serverList = [];
        foreach ($servers as $server) {
            if (is_array($server)) {
                $serverList[] = [$server[0], $server[1]];
            } else {
                $serverList[] = [$server, 11211];
            }
        }
        $this->memcached->addServers($serverList);
        return $this;
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
     *
     * @param string $key 键名
     * @return string|null
     */
    public function get(string $key): ?string
    {
        $value = $this->memcached->get($this->key($key));

        if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            return null;
        }

        return $value;
    }

    /**
     * 设置值
     *
     * @param string $key 键名
     * @param string $value 值
     * @param int $ttl 过期时间（秒）
     */
    public function set(string $key, string $value, int $ttl = 0): void
    {
        $this->memcached->set($this->key($key), $value, $ttl);
    }

    /**
     * 删除键
     *
     * @param string $key 键名
     */
    public function delete(string $key): void
    {
        $this->memcached->delete($this->key($key));
    }

    /**
     * 原子递增
     *
     * @param string $key 键名
     * @param int $step 步长
     * @return int
     */
    public function incr(string $key, int $step = 1): int
    {
        $result = $this->memcached->increment($this->key($key), $step);

        if ($result === false) {
            $this->memcached->set($this->key($key), (string) $step, 0);
            return $step;
        }

        return $result;
    }

    /**
     * 获取键的剩余 TTL
     *
     * @param string $key 键名
     * @return int
     */
    public function ttl(string $key): int
    {
        $stats = $this->memcached->getStats();
        foreach ($stats as $server => $info) {
            $keys = $this->memcached->getDelayed([$this->key($key)], true);
            if ($keys) {
                return $info['uptime'] ?? -1;
            }
        }
        return -1;
    }

    /**
     * 获取 Memcached 客户端实例
     *
     * @return \Memcached
     */
    public function getClient(): \Memcached
    {
        return $this->memcached;
    }

    /**
     * 健康检查
     *
     * @return bool
     */
    public function isHealthy(): bool
    {
        $stats = $this->memcached->getStats();
        return !empty($stats);
    }
}
