<?php

declare(strict_types=1);

namespace Kode\Limiting\Exception;

use Kode\Limiting\DTO\LimiterResult;

/**
 * 触发限流异常
 *
 * 由 Limiter::consumeOrFail() / LimiterMiddleware::handle() 在超限时抛出，
 * 携带完整的 LimiterResult，便于上层直接输出 429 响应头。
 */
final class RateLimitExceededException extends \RuntimeException implements LimiterException
{
    public function __construct(
        public readonly string $limiterKey,
        public readonly LimiterResult $result,
        string $message = ''
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf(
                '请求已被限流：key=%s，请在 %.3f 秒后重试',
                $limiterKey,
                $result->retryAfter
            ),
            429
        );
    }

    /**
     * 建议返回给客户端的 HTTP 响应头
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->result->toHeaders();
    }

    public function getRetryAfter(): float
    {
        return $this->result->retryAfter;
    }
}
