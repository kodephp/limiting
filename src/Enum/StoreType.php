<?php

declare(strict_types=1);

namespace Kode\Limiting\Enum;

/**
 * 存储类型枚举
 *
 * v2.0 补齐 MEMCACHED / PDO / APCU，与实际已实现的驱动保持一致。
 */
enum StoreType: string
{
    case MEMORY = 'memory';
    case APCU = 'apcu';
    case REDIS = 'redis';
    case MEMCACHED = 'memcached';
    case PDO = 'pdo';

    public function label(): string
    {
        return match ($this) {
            self::MEMORY => '内存存储（单进程）',
            self::APCU => 'APCu 共享内存（单机多进程）',
            self::REDIS => 'Redis 存储（分布式）',
            self::MEMCACHED => 'Memcached 存储（分布式）',
            self::PDO => '数据库存储（分布式）',
        };
    }

    /**
     * 是否可跨机器共享限流状态
     */
    public function isDistributed(): bool
    {
        return match ($this) {
            self::MEMORY, self::APCU => false,
            self::REDIS, self::MEMCACHED, self::PDO => true,
        };
    }

    /**
     * 该驱动依赖的 PHP 扩展，null 表示无需额外扩展
     */
    public function requiredExtension(): ?string
    {
        return match ($this) {
            self::MEMORY => null,
            self::APCU => 'apcu',
            self::REDIS => 'redis',
            self::MEMCACHED => 'memcached',
            self::PDO => 'pdo',
        };
    }

    /**
     * 当前运行环境是否可用
     */
    public function isAvailable(): bool
    {
        $extension = $this->requiredExtension();

        return $extension === null || extension_loaded($extension);
    }
}
