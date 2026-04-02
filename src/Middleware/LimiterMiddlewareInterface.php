<?php

declare(strict_types=1);

namespace Kode\Limiting\Middleware;

/**
 * 限流中间件接口
 *
 * 用于框架集成（如 Laravel、Symfony 等）
 * 定义限流中间件的标准方法
 */
interface LimiterMiddlewareInterface
{
    /**
     * 检查请求是否被限流
     *
     * @param string $key 限流键
     * @param int $tokens 消耗的令牌数
     * @return bool true 表示被限流，false 表示允许通过
     */
    public function isLimited(string $key, int $tokens = 1): bool;

    /**
     * 获取限流详细信息
     *
     * @param string $key 限流键
     * @return array{allowed: bool, remaining: float, wait_time: float}
     */
    public function getLimiterInfo(string $key): array;

    /**
     * 获取中间件名称
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 获取限流器容量
     *
     * @return int
     */
    public function getCapacity(): int;
}
