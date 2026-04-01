<?php

declare(strict_types=1);

namespace Kode\Limiting\Enum;

/**
 * 存储类型枚举
 */
enum StoreType: string
{
    case MEMORY = 'memory';
    case REDIS = 'redis';

    public function label(): string
    {
        return match ($this) {
            self::MEMORY => '内存存储',
            self::REDIS => 'Redis 存储',
        };
    }
}
