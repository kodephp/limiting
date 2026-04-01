<?php

declare(strict_types=1);

namespace Kode\Limiting\Concurrency;

use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Store\MemoryStore;

/**
 * Fiber 协程限流器
 *
 * 控制同时运行的 Fiber 数量
 */
class FiberLimiter
{
    private TokenBucket $bucket;
    private int $maxFibers;
    private array $activeFibers = [];
    private static bool $fiberSupported;

    public function __construct(
        int $maxFibers,
        int $capacity,
        float $refillRate,
        ?MemoryStore $store = null
    ) {
        $this->maxFibers = $maxFibers;
        $this->bucket = new TokenBucket(
            $store ?? new MemoryStore(),
            $capacity,
            $refillRate
        );
        self::$fiberSupported = class_exists(\Fiber::class);
    }

    public static function isSupported(): bool
    {
        return self::$fiberSupported;
    }

    /**
     * 尝试获取 Fiber 许可
     */
    public function tryAcquire(string $fiberId): bool
    {
        if (!$this->bucket->allow('fiber:' . $fiberId, 1)) {
            return false;
        }

        $this->activeFibers[$fiberId] = true;
        return true;
    }

    /**
     * 阻塞等待获取许可
     */
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

    /**
     * 创建并启动 Fiber
     */
    public function create(string $fiberId, callable $callback): ?\Fiber
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

    /**
     * 释放 Fiber
     */
    public function release(string $fiberId): void
    {
        unset($this->activeFibers[$fiberId]);
        $this->bucket->reset('fiber:' . $fiberId);
    }

    /**
     * 获取当前活跃 Fiber 数
     */
    public function getActiveCount(): int
    {
        return count($this->activeFibers);
    }

    /**
     * 获取最大 Fiber 数
     */
    public function getMaxFibers(): int
    {
        return $this->maxFibers;
    }
}
