<?php

declare(strict_types=1);

namespace Kode\Limiting\Middleware;

/**
 * 限流中间件接口
 *
 * 用于框架集成（如 Laravel、Symfony 等）
 */
interface LimiterMiddlewareInterface
{
    /**
     * 检查请求是否被限流
     *
     * @param string $key 限流键
     * @param int $tokens 消耗的令牌数
     * @return bool
     */
    public function isLimited(string $key, int $tokens = 1): bool;

    /**
     * 获取限流信息
     *
     * @param string $key 限流键
     * @return array{allowed: bool, remaining: float, wait_time: float}
     */
    public function getLimiterInfo(string $key): array;

    /**
     * 获取限流器名称
     */
    public function getName(): string;

    /**
     * 获取容量
     */
    public function getCapacity(): int;
}
