<?php

declare(strict_types=1);

namespace Kode\Limiting\DTO;

/**
 * 限流结果 DTO（不可变对象）
 *
 * v2.0 修复：此前构造函数对已提升的 readonly 属性二次赋值，
 * 导致任何一次实例化都会抛出 "Cannot modify readonly property"。
 *
 * 现在时间戳由构造函数一次性计算并写入非提升的 readonly 属性。
 */
readonly class LimiterResult implements \JsonSerializable
{
    /** 结果产生时刻（毫秒时间戳） */
    public int $timestamp;

    /**
     * @param bool  $allowed    是否放行
     * @param float $remaining  剩余可用额度
     * @param float $retryAfter 建议重试等待秒数（放行时为 0）
     * @param int   $limit      本次判定所使用的总额度
     * @param float $resetAfter 额度完全恢复所需秒数
     * @param bool  $degraded   是否处于降级态（存储故障时兜底放行）
     * @param string|null $degradedReason 触发降级的原因
     */
    public function __construct(
        public bool $allowed,
        public float $remaining = 0.0,
        public float $retryAfter = 0.0,
        public int $limit = 0,
        public private(set) float $resetAfter = 0.0,
        ?int $timestamp = null,
        public bool $degraded = false,
        public ?string $degradedReason = null
    ) {
        $this->timestamp = $timestamp ?? (int) (microtime(true) * 1000);
    }

    public static function allowed(
        float $remaining = 0.0,
        int $limit = 0,
        float $resetAfter = 0.0,
        bool $degraded = false,
        ?string $degradedReason = null
    ): self {
        return new self(true, max(0.0, $remaining), 0.0, $limit, $resetAfter, null, $degraded, $degradedReason);
    }

    public static function denied(
        float $retryAfter,
        int $limit = 0,
        float $remaining = 0.0,
        bool $degraded = false,
        ?string $degradedReason = null
    ): self {
        return new self(
            false,
            max(0.0, $remaining),
            max(0.0, $retryAfter),
            $limit,
            $retryAfter,
            null,
            $degraded,
            $degradedReason
        );
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isDenied(): bool
    {
        return !$this->allowed;
    }

    /**
     * 返回带降级标记的副本（用于限流器在存储故障时标注结果）
     */
    public function withDegraded(bool $degraded, ?string $reason = null): self
    {
        return new self(
            $this->allowed,
            $this->remaining,
            $this->retryAfter,
            $this->limit,
            $this->resetAfter,
            $this->timestamp,
            $degraded,
            $reason ?? $this->degradedReason
        );
    }

    /**
     * 生成标准限流响应头
     *
     * 遵循 IETF draft-polli-ratelimit-headers 常见实践。
     * 降级态会附带 X-RateLimit-Degraded: 1 提示调用方。
     *
     * @return array<string, string>
     */
    public function toHeaders(): array
    {
        $headers = [
            'X-RateLimit-Limit' => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) (int) floor($this->remaining),
            'X-RateLimit-Reset' => (string) (int) ceil(($this->timestamp / 1000) + $this->resetAfter),
        ];

        if (!$this->allowed) {
            $headers['Retry-After'] = (string) max(1, (int) ceil($this->retryAfter));
        }

        if ($this->degraded) {
            $headers['X-RateLimit-Degraded'] = '1';
        }

        return $headers;
    }

    /**
     * @return array{allowed: bool, remaining: float, retry_after: float, limit: int, reset_after: float, timestamp: int, degraded: bool, degraded_reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'remaining' => $this->remaining,
            'retry_after' => $this->retryAfter,
            'limit' => $this->limit,
            'reset_after' => $this->resetAfter,
            'timestamp' => $this->timestamp,
            'degraded' => $this->degraded,
            'degraded_reason' => $this->degradedReason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
