<?php

declare(strict_types=1);

namespace Kode\Limiting\Enum;

use Kode\Limiting\Algorithm\Counter;
use Kode\Limiting\Algorithm\LeakyBucket;
use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\Algorithm\SlidingWindow;
use Kode\Limiting\Algorithm\SlidingWindowCounter;
use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\Store\StoreInterface;

/**
 * 限流器类型枚举
 *
 * v2.0 补齐 LEAKY_BUCKET / COUNTER / SLIDING_WINDOW_COUNTER，
 * 并内聚工厂方法，避免入口类里出现"名不副实"的映射。
 */
enum LimiterType: string
{
    case TOKEN_BUCKET = 'token_bucket';
    case SLIDING_WINDOW = 'sliding_window';
    case SLIDING_WINDOW_COUNTER = 'sliding_window_counter';
    case LEAKY_BUCKET = 'leaky_bucket';
    case COUNTER = 'counter';

    public function label(): string
    {
        return match ($this) {
            self::TOKEN_BUCKET => '令牌桶',
            self::SLIDING_WINDOW => '滑动窗口（精确日志）',
            self::SLIDING_WINDOW_COUNTER => '滑动窗口计数器（加权近似）',
            self::LEAKY_BUCKET => '漏桶',
            self::COUNTER => '固定窗口计数器',
        };
    }

    /**
     * 是否允许突发流量
     */
    public function allowsBurst(): bool
    {
        return match ($this) {
            self::TOKEN_BUCKET, self::COUNTER => true,
            self::LEAKY_BUCKET, self::SLIDING_WINDOW, self::SLIDING_WINDOW_COUNTER => false,
        };
    }

    /**
     * 按类型创建对应算法实例
     */
    public function create(StoreInterface $store, LimiterConfig $config): RateLimiterInterface
    {
        return match ($this) {
            self::TOKEN_BUCKET => TokenBucket::fromConfig($store, $config),
            self::SLIDING_WINDOW => SlidingWindow::fromConfig($store, $config),
            self::SLIDING_WINDOW_COUNTER => SlidingWindowCounter::fromConfig($store, $config),
            self::LEAKY_BUCKET => LeakyBucket::fromConfig($store, $config),
            self::COUNTER => Counter::fromConfig($store, $config),
        };
    }
}
