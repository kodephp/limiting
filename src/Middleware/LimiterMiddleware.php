<?php

declare(strict_types=1);

namespace Kode\Limiting\Middleware;

use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Exception\RateLimitExceededException;

/**
 * 限流中间件
 *
 * v2.0 改进：
 *  - getLimiterInfo() 此前内部调用 allow($key, 0)，属于"读操作产生写副作用"，
 *    在滑动窗口等算法下还会污染窗口数据；现改为只读的 check()；
 *  - 新增 handle() 管道方法与 429 响应头输出，可直接接入各类框架。
 */
class LimiterMiddleware implements LimiterMiddlewareInterface
{
    /**
     * @param RateLimiterInterface $limiter 限流器实例
     * @param string $name 中间件名称
     */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly string $name = 'default'
    ) {}

    #[\Override]
    public function isLimited(string $key, int $tokens = 1): bool
    {
        return !$this->limiter->allow($key, $tokens);
    }

    #[\Override]
    public function consume(string $key, int $tokens = 1): LimiterResult
    {
        return $this->limiter->consume($key, $tokens);
    }

    #[\Override]
    public function peek(string $key, int $tokens = 1): LimiterResult
    {
        return $this->limiter->check($key, $tokens);
    }

    /**
     * 中间件管道：通过则执行 $next，被限流则执行 $onLimited（缺省抛异常）
     *
     * @template T
     * @param callable(LimiterResult): T $next
     * @param (callable(LimiterResult): T)|null $onLimited
     * @return T
     * @throws RateLimitExceededException 当被限流且未提供 $onLimited 时
     */
    public function handle(string $key, callable $next, ?callable $onLimited = null, int $tokens = 1): mixed
    {
        $result = $this->consume($key, $tokens);

        if ($result->isAllowed()) {
            return $next($result);
        }

        if ($onLimited !== null) {
            return $onLimited($result);
        }

        throw new RateLimitExceededException($key, $result);
    }

    /**
     * 建议附加到响应上的限流头
     *
     * @return array<string, string>
     */
    public function getHeaders(string $key): array
    {
        return $this->peek($key)->toHeaders();
    }

    #[\Override]
    public function getLimiterInfo(string $key): array
    {
        return $this->peek($key)->toArray();
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[\Override]
    public function getCapacity(): int
    {
        return $this->limiter->getCapacity();
    }

    public function getLimiter(): RateLimiterInterface
    {
        return $this->limiter;
    }
}
