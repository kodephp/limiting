<?php

declare(strict_types=1);

namespace Kode\Limiting\Middleware;

use Kode\Limiting\Algorithm\RateLimiterInterface;

/**
 * 限流中间件
 *
 * 封装限流器，提供统一的中间件接口，方便框架集成
 * 委托模式：将请求委托给内部的限流器处理
 */
class LimiterMiddleware implements LimiterMiddlewareInterface
{
    /**
     * 构造函数
     *
     * @param RateLimiterInterface $limiter 限流器实例
     * @param string $name 中间件名称
     */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly string $name = 'default'
    ) {}

    /**
     * 检查请求是否被限流
     *
     * @param string $key 限流键
     * @param int $tokens 消耗的令牌数
     * @return bool true 表示被限流，false 表示允许通过
     */
    public function isLimited(string $key, int $tokens = 1): bool
    {
        return !$this->limiter->allow($key, $tokens);
    }

    /**
     * 获取限流详细信息
     *
     * @param string $key 限流键
     * @return array{allowed: bool, remaining: float, wait_time: float}
     */
    public function getLimiterInfo(string $key): array
    {
        return [
            'allowed' => $this->limiter->allow($key, 0),
            'remaining' => $this->limiter->getRemaining($key),
            'wait_time' => $this->limiter->getWaitTime($key),
        ];
    }

    /**
     * 获取中间件名称
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取限流器容量
     *
     * 如果限流器实现了 getCapacity 方法则调用，否则返回 0
     *
     * @return int
     */
    public function getCapacity(): int
    {
        if (method_exists($this->limiter, 'getCapacity')) {
            return $this->limiter->getCapacity();
        }

        return 0;
    }
}
