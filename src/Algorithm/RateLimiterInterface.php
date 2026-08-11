<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Store\StoreInterface;

/**
 * 限流算法统一接口
 *
 * v2.0 起 check() / consume() / getCapacity() 纳入契约，
 * 使所有算法可以互换使用，并统一返回结构化的 LimiterResult。
 */
interface RateLimiterInterface
{
    /**
     * 尝试消耗额度，返回是否放行
     */
    public function allow(string $key, int $tokens = 1): bool;

    /**
     * 只读探测，不消耗额度
     */
    public function check(string $key, int $tokens = 1): LimiterResult;

    /**
     * 消耗额度并返回完整结果（含剩余额度、重试等待时间、响应头信息）
     */
    public function consume(string $key, int $tokens = 1): LimiterResult;

    /**
     * 获取剩余可用额度
     */
    public function getRemaining(string $key): float;

    /**
     * 获取建议等待秒数，0 表示当前可立即通过
     */
    public function getWaitTime(string $key): float;

    /**
     * 重置指定键的限流状态
     */
    public function reset(string $key): void;

    /**
     * 获取总额度
     */
    public function getCapacity(): int;

    /**
     * 获取底层存储驱动（容灾降级检测所需）
     */
    public function getStore(): StoreInterface;
}
