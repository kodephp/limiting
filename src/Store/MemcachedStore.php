<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

use Kode\Limiting\Exception\UnsupportedDriverException;

/**
 * Memcached 存储实现（分布式）
 *
 * v2.0 修复：
 *  - ttl() 此前遍历 getStats() 返回服务器 uptime，属于完全错误的实现；
 *    现改为写入时记录绝对过期时间的伴生键，返回真实剩余秒数。
 *  - incr() 补齐初始值与 TTL 处理。
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

    private static function assertExtension(): void
    {
        if (!extension_loaded('memcached')) {
            throw UnsupportedDriverException::extensionMissing(
                'Memcached',
                'pecl install memcached',
                'https://www.php.net/manual/zh/book.memcached.php'
            );
        }
    }

    public static function create(
        string $host = '127.0.0.1',
        int $port = 11211,
        string $prefix = 'kode:limiting:'
    ): self {
        self::assertExtension();

        $memcached = new \Memcached();
        $memcached->addServer($host, $port);

        return new self($memcached, $prefix);
    }

    /**
     * 批量添加服务器
     *
     * @param list<array{0: string, 1: int}|string> $servers
     */
    public function addServers(array $servers): self
    {
        $serverList = [];

        foreach ($servers as $server) {
            $serverList[] = is_array($server) ? [$server[0], (int) $server[1]] : [$server, 11211];
        }

        $this->memcached->addServers($serverList);

        return $this;
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    private function expireKey(string $key): string
    {
        return $this->prefix . '__exp:' . $key;
    }

    #[\Override]
    public function get(string $key): ?string
    {
        $value = $this->memcached->get($this->key($key));

        if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            return null;
        }

        return $value === false ? null : (string) $value;
    }

    #[\Override]
    public function set(string $key, string $value, int $ttl = 0): void
    {
        $expiration = $ttl > 0 ? $ttl : 0;

        $this->memcached->set($this->key($key), $value, $expiration);
        $this->memcached->set($this->expireKey($key), $ttl > 0 ? time() + $ttl : 0, $expiration);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $this->memcached->delete($this->key($key));
        $this->memcached->delete($this->expireKey($key));
    }

    #[\Override]
    public function incr(string $key, int $step = 1, int $ttl = 0): int
    {
        $realKey = $this->key($key);

        // initial_value 仅在键不存在时生效，等价于原子的 "get or create + incr"
        $result = $this->memcached->increment($realKey, $step, $step, $ttl > 0 ? $ttl : 0);

        if ($result === false) {
            $this->memcached->set($realKey, (string) $step, $ttl > 0 ? $ttl : 0);
            $result = $step;
        }

        if ($ttl > 0 && $this->memcached->get($this->expireKey($key)) === false) {
            $this->memcached->set($this->expireKey($key), time() + $ttl, $ttl);
        }

        return (int) $result;
    }

    #[\Override]
    public function decr(string $key, int $step = 1): int
    {
        $result = $this->memcached->decrement($this->key($key), $step, 0);

        return $result === false ? 0 : (int) $result;
    }

    #[\Override]
    public function ttl(string $key): int
    {
        if (!$this->has($key)) {
            return -2;
        }

        $expiresAt = $this->memcached->get($this->expireKey($key));

        if ($expiresAt === false || (int) $expiresAt <= 0) {
            return -1;
        }

        $remaining = (int) $expiresAt - time();

        return $remaining > 0 ? $remaining : -2;
    }

    #[\Override]
    public function has(string $key): bool
    {
        $this->memcached->get($this->key($key));

        return $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

    public function getClient(): \Memcached
    {
        return $this->memcached;
    }

    public function isHealthy(): bool
    {
        $stats = $this->memcached->getStats();

        return is_array($stats) && $stats !== [];
    }
}
