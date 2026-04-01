<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

/**
 * 内存存储实现（单进程使用）
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
        $ttl = isset($this->ttls[$key]) && $this->ttls[$key] > time() ? $this->ttls[$key] - time() : 0;
        $this->set($key, (string) $newValue, $ttl);
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
}
