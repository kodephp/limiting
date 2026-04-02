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

    /**
     * 构造函数
     *
     * @param \Memcached $memcached Memcached 客户端实例
     * @param string $prefix 键前缀
     * @throws \RuntimeException 当 Memcached 扩展未安装时
     */
    public function __construct(
        private readonly \Memcached $memcached,
        string $prefix = 'kode:limiting:'
    ) {
        if (!extension_loaded('memcached')) {
            throw new \RuntimeException(
                'Memcached 扩展未安装，请先执行：pecl install memcached 或参见 https://www.php.net/manual/zh/book.memcached.php'
            );
        }
        $this->prefix = $prefix;
    }

    /**
     * 创建 Memcached 存储实例
     *
     * @param string $host Memcached 服务器地址
     * @param int $port Memcached 服务器端口
     * @param string $prefix 键前缀
     * @return self
     * @throws \RuntimeException 当 Memcached 扩展未安装时
     */
    public static function create(
        string $host = '127.0.0.1',
        int $port = 11211,
        string $prefix = 'kode:limiting:'
    ): self {
        if (!extension_loaded('memcached')) {
            throw new \RuntimeException(
                'Memcached 扩展未安装，请先执行：pecl install memcached 或参见 https://www.php.net/manual/zh/book.memcached.php'
            );
        }

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
     */
    public function set(string $key, string $value, int $ttl = 0): void
    {
        $this->memcached->set($this->key($key), $value, $ttl);
    }

    /**
     * 删除键
     */
    public function delete(string $key): void
    {
        $this->memcached->delete($this->key($key));
    }

    /**
     * 原子递增
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
     */
    public function getClient(): \Memcached
    {
        return $this->memcached;
    }

    /**
     * 健康检查
     */
    public function isHealthy(): bool
    {
        $stats = $this->memcached->getStats();
        return !empty($stats);
    }
}
