<?php

declare(strict_types=1);

namespace Kode\Limiting\Concurrency;

use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Store\MemoryStore;

/**
 * Fiber 协程限流器
 *
 * 控制同时运行的 Fiber 数量
 * 使用 PHP 8.2 readonly 属性优化性能
 */
class FiberLimiter
{
    private readonly TokenBucket $bucket;
    private static bool $fiberSupported;

    public function __construct(
        private readonly int $maxFibers,
        private readonly int $capacity,
        private readonly float $refillRate,
        private readonly MemoryStore $store,
        private readonly string $prefix = 'fiber:'
    ) {
        $this->bucket = new TokenBucket(
            $this->store,
            $this->capacity,
            $this->refillRate,
            3600,
            $this->prefix
        );
        self::$fiberSupported ??= class_exists(\Fiber::class);
    }

    public static function isSupported(): bool
    {
        return self::$fiberSupported;
    }

    public function tryAcquire(string $fiberId): bool
    {
        $key = $this->prefix . 'fiber:' . $fiberId;
        return $this->bucket->allow($key, 1);
    }

    public function acquire(string $fiberId, float $timeout = 30.0): bool
    {
        $start = microtime(true);

        while (!$this->tryAcquire($fiberId)) {
            if (microtime(true) - $start >= $timeout) {
                return false;
            }

            if (self::$fiberSupported) {
                \Fiber::suspend();
            } else {
                usleep(10000);
            }
        }

        return true;
    }

    public function start(string $fiberId, callable $callback): ?\Fiber
    {
        if (!self::$fiberSupported) {
            return null;
        }

        if (!$this->tryAcquire($fiberId)) {
            return null;
        }

        $fiber = new \Fiber(function () use ($fiberId, $callback) {
            try {
                $callback();
            } finally {
                $this->release($fiberId);
            }
        });

        $fiber->start();
        return $fiber;
    }

    public function release(string $fiberId): void
    {
        $this->bucket->reset($this->prefix . 'fiber:' . $fiberId);
    }

    public function getActiveCount(): int
    {
        return (int) ($this->store->get($this->prefix . 'active') ?? 0);
    }

    public function getMaxFibers(): int
    {
        return $this->maxFibers;
    }
}
