<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Exception\RateLimitExceededException;
use Kode\Limiting\Store\StoreInterface;

/**
 * 限流算法抽象基类
 *
 * 收敛各算法重复的入参校验、结果封装与 JSON 状态读写逻辑。
 */
abstract class AbstractRateLimiter implements RateLimiterInterface
{
    public function __construct(
        protected readonly StoreInterface $store,
        protected readonly int $capacity,
        protected readonly int $ttl = 3600,
        protected readonly string $prefix = 'limiter:'
    ) {
        if ($capacity <= 0) {
            throw InvalidConfigurationException::positiveRequired('capacity', $capacity);
        }

        if ($ttl <= 0) {
            throw InvalidConfigurationException::positiveRequired('ttl', $ttl);
        }
    }

    #[\Override]
    public function check(string $key, #[\SensitiveParameter] int $tokens = 1): LimiterResult
    {
        $this->assertKey($key);
        $this->assertTokens($tokens);

        $remaining = $this->getRemaining($key);

        if ($remaining >= $tokens) {
            return LimiterResult::allowed($remaining - $tokens, $this->capacity, $this->getWaitTime($key));
        }

        return LimiterResult::denied($this->getWaitTime($key), $this->capacity, $remaining);
    }

    #[\Override]
    public function consume(string $key, #[\SensitiveParameter] int $tokens = 1): LimiterResult
    {
        $this->assertKey($key);
        $this->assertTokens($tokens);

        if ($this->allow($key, $tokens)) {
            return LimiterResult::allowed($this->getRemaining($key), $this->capacity, $this->getWaitTime($key));
        }

        return LimiterResult::denied($this->getWaitTime($key), $this->capacity, $this->getRemaining($key));
    }

    /**
     * 消耗额度，超限时直接抛出 RateLimitExceededException
     *
     * @throws RateLimitExceededException
     */
    public function consumeOrFail(string $key, #[\SensitiveParameter] int $tokens = 1): LimiterResult
    {
        $result = $this->consume($key, $tokens);

        if ($result->isDenied()) {
            throw new RateLimitExceededException($key, $result);
        }

        return $result;
    }

    #[\Override]
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    #[\Override]
    public function getStore(): StoreInterface
    {
        return $this->store;
    }

    protected function assertKey(string $key): void
    {
        if (trim($key) === '') {
            throw InvalidConfigurationException::emptyKey();
        }
    }

    protected function assertTokens(#[\SensitiveParameter] int $tokens): void
    {
        if ($tokens < 0) {
            throw InvalidConfigurationException::negativeTokens($tokens);
        }
    }

    /**
     * 安全读取 JSON 状态，损坏数据视为不存在（利用 PHP 8.3 json_validate）
     *
     * @return array<string, mixed>|null
     */
    protected function readState(string $storageKey): ?array
    {
        $raw = $this->store->get($storageKey);

        if ($raw === null || !json_validate($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    protected function writeState(string $storageKey, array $state): void
    {
        $this->store->set($storageKey, json_encode($state, JSON_THROW_ON_ERROR), $this->ttl);
    }
}
