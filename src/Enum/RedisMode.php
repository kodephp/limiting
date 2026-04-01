<?php

declare(strict_types=1);

namespace Kode\Limiting\Enum;

/**
 * Redis 部署模式枚举
 */
enum RedisMode: string
{
    case STANDALONE = 'standalone';
    case SENTINEL = 'sentinel';
    case CLUSTER = 'cluster';

    public function label(): string
    {
        return match ($this) {
            self::STANDALONE => '单机模式',
            self::SENTINEL => 'Sentinel 高可用',
            self::CLUSTER => 'Cluster 分片',
        };
    }
}
