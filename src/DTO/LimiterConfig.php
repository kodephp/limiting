<?php

declare(strict_types=1);

namespace Kode\Limiting\DTO;

/**
 * 限流器配置 DTO（不可变对象）
 */
readonly class LimiterConfig
{
    public function __construct(
        public int $capacity,
        public float $refillRate,
        public int $ttl = 3600,
        public string $prefix = 'limiter:'
    ) {}

    public function withCapacity(int $capacity): self
    {
        return new self($capacity, $this->refillRate, $this->ttl, $this->prefix);
    }

    public function withRefillRate(float $refillRate): self
    {
        return new self($this->capacity, $refillRate, $this->ttl, $this->prefix);
    }

    public function withTtl(int $ttl): self
    {
        return new self($this->capacity, $this->refillRate, $ttl, $this->prefix);
    }

    public function withPrefix(string $prefix): self
    {
        return new self($this->capacity, $this->refillRate, $this->ttl, $prefix);
    }
}
