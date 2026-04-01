<?php

declare(strict_types=1);

namespace Kode\Limiting\Enum;

/**
 * 限流器类型枚举
 */
enum LimiterType: string
{
    case TOKEN_BUCKET = 'token_bucket';
    case SLIDING_WINDOW = 'sliding_window';

    public function label(): string
    {
        return match ($this) {
            self::TOKEN_BUCKET => '令牌桶',
            self::SLIDING_WINDOW => '滑动窗口',
        };
    }
}
