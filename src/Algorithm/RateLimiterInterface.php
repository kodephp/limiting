<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

/**
 * 限流器接口
 */
interface RateLimiterInterface
{
    /**
     * 检查是否允许请求
     */
    public function allow(string $key, int $tokens = 1): bool;

    /**
     * 获取剩余数量
     */
    public function getRemaining(string $key): float;

    /**
     * 获取等待时间
     */
    public function getWaitTime(string $key): float;

    /**
     * 重置限流器
     */
    public function reset(string $key): void;
}
