<?php

declare(strict_types=1);

namespace Kode\Limiting\Middleware;

use Kode\Limiting\DTO\LimiterResult;

/**
 * 限流中间件接口
 *
 * 用于框架集成（Laravel / Symfony / Webman / Hyperf 等）。
 * 组件本身零依赖，不强绑 PSR-15，由使用方自行桥接。
 */
interface LimiterMiddlewareInterface
{
    /**
     * 检查请求是否被限流（会消耗额度）
     *
     * @return bool true 表示被限流，false 表示允许通过
     */
    public function isLimited(string $key, int $tokens = 1): bool;

    /**
     * 消耗额度并返回完整结果
     */
    public function consume(string $key, int $tokens = 1): LimiterResult;

    /**
     * 只读探测，不消耗额度
     */
    public function peek(string $key, int $tokens = 1): LimiterResult;

    /**
     * 获取限流详细信息（只读，不消耗额度）
     *
     * @return array{allowed: bool, remaining: float, retry_after: float, limit: int, reset_after: float, timestamp: int}
     */
    public function getLimiterInfo(string $key): array;

    /**
     * 获取中间件名称
     */
    public function getName(): string;

    /**
     * 获取限流器总额度
     */
    public function getCapacity(): int;
}
