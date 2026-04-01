<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

interface StoreInterface
{
    /**
     * 获取指定键的值
     */
    public function get(string $key): ?string;

    /**
     * 设置指定键的值
     *
     * @param string $key 键
     * @param string $value 值
     * @param int $ttl 过期时间（秒），0表示不过期
     */
    public function set(string $key, string $value, int $ttl = 0): void;

    /**
     * 删除指定键
     */
    public function delete(string $key): void;

    /**
     * 原子递增（用于计数器）
     */
    public function incr(string $key, int $step = 1): int;

    /**
     * 获取键的剩余 TTL
     */
    public function ttl(string $key): int;
}
