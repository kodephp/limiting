<?php

declare(strict_types=1);

namespace Kode\Limiting\Middleware;

use Kode\Limiting\Algorithm\RateLimiterInterface;

/**
 * 限流中间件
 *
 * 封装限流器，提供中间件接口
 */
class LimiterMiddleware implements LimiterMiddlewareInterface
{
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly string $name = 'default'
    ) {}

    public function isLimited(string $key, int $tokens = 1): bool
    {
        return !$this->limiter->allow($key, $tokens);
    }

    public function getLimiterInfo(string $key): array
    {
        return [
            'allowed' => $this->limiter->allow($key, 0),
            'remaining' => $this->limiter->getRemaining($key),
            'wait_time' => $this->limiter->getWaitTime($key),
        ];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCapacity(): int
    {
        if (method_exists($this->limiter, 'getCapacity')) {
            return $this->limiter->getCapacity();
        }

        return 0;
    }
}
