<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

/**
 * 内存存储实现（单进程内有效）
 *
 * 适用场景：CLI 脚本、单元测试、常驻进程内的本地限流。
 * v2.0 修复：TTL 语义与 Redis 对齐，incr 支持首次创建时设置 TTL。
 */
class MemoryStore implements StoreInterface
{
    /** @var array<string, string> */
    private array $data = [];

    /** @var array<string, float> 绝对过期时间（秒，含小数） */
    private array $expires = [];

    #[\Override]
    public function get(string $key): ?string
    {
        if (!$this->has($key)) {
            return null;
        }

        return $this->data[$key];
    }

    #[\Override]
    public function set(string $key, string $value, int $ttl = 0): void
    {
        $this->data[$key] = $value;

        if ($ttl > 0) {
            $this->expires[$key] = microtime(true) + $ttl;
        } else {
            unset($this->expires[$key]);
        }
    }

    #[\Override]
    public function delete(string $key): void
    {
        unset($this->data[$key], $this->expires[$key]);
    }

    #[\Override]
    public function incr(string $key, int $step = 1, int $ttl = 0): int
    {
        $exists = $this->has($key);
        $value = $exists ? (int) $this->data[$key] : 0;
        $newValue = $value + $step;

        $this->data[$key] = (string) $newValue;

        // 已存在的键保留原有过期时间；新键才应用传入的 TTL
        if (!$exists && $ttl > 0) {
            $this->expires[$key] = microtime(true) + $ttl;
        }

        return $newValue;
    }

    #[\Override]
    public function decr(string $key, int $step = 1): int
    {
        return $this->incr($key, -$step);
    }

    #[\Override]
    public function ttl(string $key): int
    {
        if (!$this->has($key)) {
            return -2;
        }

        if (!isset($this->expires[$key])) {
            return -1;
        }

        return (int) ceil($this->expires[$key] - microtime(true));
    }

    #[\Override]
    public function has(string $key): bool
    {
        if (!isset($this->data[$key])) {
            return false;
        }

        if (isset($this->expires[$key]) && microtime(true) >= $this->expires[$key]) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * 清空全部数据
     */
    public function clear(): void
    {
        $this->data = [];
        $this->expires = [];
    }

    /**
     * 主动清理已过期的键，返回清理数量
     */
    public function purgeExpired(): int
    {
        $now = microtime(true);
        $purged = 0;

        foreach ($this->expires as $key => $expireAt) {
            if ($now >= $expireAt) {
                $this->delete($key);
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * @return array<string, string>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function count(): int
    {
        $this->purgeExpired();

        return count($this->data);
    }

    public function isHealthy(): bool
    {
        return true;
    }
}
