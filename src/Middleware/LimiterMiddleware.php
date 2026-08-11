<?php

declare(strict_types=1);

namespace Kode\Limiting\Middleware;

use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\EventHandler\LimiterEventHandler;
use Kode\Limiting\EventHandler\NullLimiterEventHandler;
use Kode\Limiting\Exception\RateLimitExceededException;
use Kode\Limiting\Store\DegradationAwareInterface;

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
     * @param RateLimiterInterface   $limiter 限流器实例
     * @param string                 $name    中间件名称
     * @param LimiterEventHandler|null $eventHandler 事件回调（默认空操作）
     */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly string $name = 'default',
        private readonly ?LimiterEventHandler $eventHandler = null
    ) {}

    /**
     * 绑定事件回调（返回新实例，保持不可变）
     */
    public function withEventHandler(LimiterEventHandler $eventHandler): self
    {
        return new self($this->limiter, $this->name, $eventHandler);
    }

    #[\Override]
    public function isLimited(string $key, int $tokens = 1): bool
    {
        return !$this->limiter->allow($key, $tokens);
    }

    #[\Override]
    public function consume(string $key, int $tokens = 1): LimiterResult
    {
        try {
            $result = $this->enrich($this->limiter->consume($key, $tokens));
            $this->handler()->onResult($key, $result);

            return $result;
        } catch (\Throwable $e) {
            $this->handler()->onError($key, $e);

            throw $e;
        }
    }

    #[\Override]
    public function peek(string $key, int $tokens = 1): LimiterResult
    {
        return $this->enrich($this->limiter->check($key, $tokens));
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

    /**
     * 若底层存储处于降级态，把"降级放行"标记到结果上并清除标记
     */
    private function enrich(LimiterResult $result): LimiterResult
    {
        $store = $this->limiter->getStore();

        if ($store instanceof DegradationAwareInterface && $store->isDegraded()) {
            $reason = $store->getDegradationReason();
            $store->clearDegradation();

            return $result->withDegraded(true, $reason);
        }

        return $result;
    }

    private function handler(): LimiterEventHandler
    {
        return $this->eventHandler ?? new NullLimiterEventHandler();
    }
}
