<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

/**
 * 内存存储实现（单进程使用）
 *
 * 使用 PHP 8.2 readonly 属性优化性能
 */
class MemoryStore implements StoreInterface
{
    private array $data = [];
    private array $ttls = [];

    public function get(string $key): ?string
    {
        if (!isset($this->data[$key])) {
            return null;
        }

        if (isset($this->ttls[$key]) && time() > $this->ttls[$key]) {
            $this->delete($key);
            return null;
        }

        return $this->data[$key];
    }

    public function set(string $key, string $value, int $ttl = 0): void
    {
        $this->data[$key] = $value;

        if ($ttl > 0) {
            $this->ttls[$key] = time() + $ttl;
        } else {
            unset($this->ttls[$key]);
        }
    }

    public function delete(string $key): void
    {
        unset($this->data[$key], $this->ttls[$key]);
    }

    public function incr(string $key, int $step = 1): int
    {
        $value = (int) ($this->get($key) ?? 0);
        $newValue = $value + $step;
        $ttl = $this->ttls[$key] ?? 0;
        $remainingTtl = $ttl > time() ? $ttl - time() : 0;
        $this->set($key, (string) $newValue, $remainingTtl);
        return $newValue;
    }

    public function ttl(string $key): int
    {
        if (!isset($this->ttls[$key])) {
            return -1;
        }

        $remaining = $this->ttls[$key] - time();
        return $remaining > 0 ? $remaining : -2;
    }

    public function clear(): void
    {
        $this->data = [];
        $this->ttls = [];
    }

    public function getData(): array
    {
        return $this->data;
    }
}
