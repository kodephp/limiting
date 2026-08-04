<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

/**
 * 存储驱动接口
 *
 * TTL 语义与 Redis 对齐：
 *  - 返回 >= 0：剩余存活秒数
 *  - 返回 -1  ：键存在但永不过期
 *  - 返回 -2  ：键不存在（或已过期）
 */
interface StoreInterface
{
    /**
     * 获取指定键的值，不存在返回 null
     */
    public function get(string $key): ?string;

    /**
     * 设置指定键的值
     *
     * @param int $ttl 过期秒数，0 表示不过期
     */
    public function set(string $key, string $value, int $ttl = 0): void;

    /**
     * 删除指定键
     */
    public function delete(string $key): void;

    /**
     * 原子递增（用于计数器）
     *
     * @param int $ttl 仅当键首次创建时生效的过期秒数，0 表示不设置
     * @return int 递增后的值
     */
    public function incr(string $key, int $step = 1, int $ttl = 0): int;

    /**
     * 原子递减，返回递减后的值
     */
    public function decr(string $key, int $step = 1): int;

    /**
     * 获取键的剩余 TTL
     */
    public function ttl(string $key): int;

    /**
     * 键是否存在且未过期
     */
    public function has(string $key): bool;
}
