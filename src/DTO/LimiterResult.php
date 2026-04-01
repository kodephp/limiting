<?php

declare(strict_types=1);

namespace Kode\Limiting\DTO;

/**
 * 限流结果 DTO（不可变对象）
 */
readonly class LimiterResult
{
    public function __construct(
        public bool $allowed,
        public float $remaining,
        public float $waitTime,
        public int $timestamp
    ) {
        $this->timestamp = (int) (microtime(true) * 1000);
    }

    public static function allowed(float $remaining = 0.0): self
    {
        return new self(true, $remaining, 0.0, 0);
    }

    public static function denied(float $waitTime): self
    {
        return new self(false, 0.0, $waitTime, 0);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'remaining' => $this->remaining,
            'wait_time' => $this->waitTime,
            'timestamp' => $this->timestamp,
        ];
    }
}
