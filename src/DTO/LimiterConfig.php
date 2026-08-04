<?php

declare(strict_types=1);

namespace Kode\Limiting\DTO;

use Kode\Limiting\Exception\InvalidConfigurationException;

/**
 * 限流器配置 DTO（不可变对象）
 *
 * v2.0 起构造时即做参数合法性校验，避免非法配置在运行期才暴露。
 */
readonly class LimiterConfig
{
    /**
     * @param int    $capacity   容量 / 窗口内最大请求数，必须 > 0
     * @param float  $refillRate 补充速率（令牌桶）/ 窗口秒数（窗口类算法），必须 > 0
     * @param int    $ttl        存储键过期秒数，必须 > 0
     * @param string $prefix     存储键前缀
     */
    public function __construct(
        public int $capacity,
        public float $refillRate,
        public int $ttl = 3600,
        public string $prefix = 'limiter:'
    ) {
        if ($capacity <= 0) {
            throw InvalidConfigurationException::positiveRequired('capacity', $capacity);
        }

        if ($refillRate <= 0) {
            throw InvalidConfigurationException::positiveRequired('refillRate', $refillRate);
        }

        if ($ttl <= 0) {
            throw InvalidConfigurationException::positiveRequired('ttl', $ttl);
        }
    }

    /**
     * 从数组创建配置，兼容多种键名写法
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            (int) ($config['capacity'] ?? $config['limit'] ?? 10),
            (float) ($config['refillRate'] ?? $config['refill_rate'] ?? $config['window'] ?? 1.0),
            (int) ($config['ttl'] ?? 3600),
            (string) ($config['prefix'] ?? 'limiter:')
        );
    }

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

    /**
     * @return array{capacity: int, refillRate: float, ttl: int, prefix: string}
     */
    public function toArray(): array
    {
        return [
            'capacity' => $this->capacity,
            'refillRate' => $this->refillRate,
            'ttl' => $this->ttl,
            'prefix' => $this->prefix,
        ];
    }
}
