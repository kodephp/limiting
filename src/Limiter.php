<?php

declare(strict_types=1);

namespace Kode\Limiting;

use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\Algorithm\SlidingWindow;
use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Concurrency\FiberLimiter;
use Kode\Limiting\Concurrency\ProcessLimiter;
use Kode\Limiting\Concurrency\TaskLimiter;
use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Enum\RedisMode;
use Kode\Limiting\Enum\StoreType;
use Kode\Limiting\Middleware\LimiterMiddleware;
use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\MemcachedStore;
use Kode\Limiting\Store\PdoStore;
use Kode\Limiting\Store\RedisStore;
use Kode\Limiting\Store\StoreInterface;

/**
 * 限流器统一入口类
 *
 * 提供简洁统一的 API 创建各种限流器实例
 * 支持链式调用和配置复用
 *
 * @example
 * ```php
 * // 快速开始
 * $limiter = Limiter::tokenBucket();
 * $limiter->allow('api:user:123');
 *
 * // 完整配置
 * $limiter = Limiter::create(LimiterType::TOKEN_BUCKET, [
 *     'store' => StoreType::REDIS,
 *     'capacity' => 100,
 *     'refillRate' => 10,
 * ]);
 * ```
 */
class Limiter
{
    private StoreInterface $store;
    private LimiterConfig $config;
    private ?RateLimiterInterface $limiter = null;

    public function __construct(
        private readonly StoreType $storeType = StoreType::MEMORY,
        private readonly LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        StoreInterface|array|null $store = null,
        LimiterConfig|array|null $config = null
    ) {
        $this->store = $this->resolveStore($store);
        $this->config = $this->resolveConfig($config);
    }

    /**
     * 创建令牌桶限流器（默认）
     */
    public static function tokenBucket(
        int $capacity = 10,
        float $refillRate = 1.0,
        StoreInterface|string|array|null $store = null
    ): self {
        $config = new LimiterConfig($capacity, $refillRate);
        $resolvedStore = self::resolveStoreInstance($store);

        return new self(StoreType::MEMORY, LimiterType::TOKEN_BUCKET, $resolvedStore, $config);
    }

    /**
     * 创建滑动窗口限流器
     */
    public static function slidingWindow(
        int $capacity = 10,
        float $windowSize = 60.0,
        StoreInterface|string|array|null $store = null
    ): self {
        $config = new LimiterConfig($capacity, $windowSize);
        $resolvedStore = self::resolveStoreInstance($store);

        return new self(StoreType::MEMORY, LimiterType::SLIDING_WINDOW, $resolvedStore, $config);
    }

    /**
     * 创建漏桶限流器
     */
    public static function leakyBucket(
        int $capacity = 10,
        float $leakRate = 1.0,
        StoreInterface|string|array|null $store = null
    ): self {
        $config = new LimiterConfig($capacity, $leakRate);
        $resolvedStore = self::resolveStoreInstance($store);

        return new self(StoreType::MEMORY, LimiterType::TOKEN_BUCKET, $resolvedStore, $config);
    }

    /**
     * 创建计数器限流器
     */
    public static function counter(
        int $maxRequests = 100,
        int $windowSeconds = 60,
        StoreInterface|string|array|null $store = null
    ): self {
        $config = new LimiterConfig($maxRequests, (float) $windowSeconds);
        $resolvedStore = self::resolveStoreInstance($store);

        return new self(StoreType::MEMORY, LimiterType::TOKEN_BUCKET, $resolvedStore, $config);
    }

    /**
     * 使用 Redis 存储创建限流器
     */
    public static function redis(
        LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        int $capacity = 10,
        float $refillRate = 1.0,
        string $host = '127.0.0.1',
        int $port = 6379,
        ?string $password = null,
        int $database = 0,
        RedisMode $mode = RedisMode::STANDALONE
    ): self {
        $store = match ($mode) {
            RedisMode::STANDALONE => RedisStore::create($host, $port, 'kode:limiting:', $password, $database),
            RedisMode::SENTINEL => RedisStore::createSentinel(
                ['127.0.0.1:26379'],
                'mymaster',
                $password,
                $database
            ),
            RedisMode::CLUSTER => RedisStore::createCluster(['127.0.0.1:7000']),
        };

        $config = new LimiterConfig($capacity, $refillRate);

        return new self(StoreType::REDIS, $limiterType, $store, $config);
    }

    /**
     * 使用 Memcached 存储创建限流器
     */
    public static function memcached(
        LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        int $capacity = 10,
        float $refillRate = 1.0,
        string $host = '127.0.0.1',
        int $port = 11211
    ): self {
        $store = MemcachedStore::create($host, $port);
        $config = new LimiterConfig($capacity, $refillRate);

        return new self(StoreType::MEMORY, $limiterType, $store, $config);
    }

    /**
     * 使用 PDO 存储创建限流器
     */
    public static function pdo(
        LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        int $capacity = 10,
        float $refillRate = 1.0,
        string $dsn = 'sqlite::memory:',
        ?string $username = null,
        ?string $password = null
    ): self {
        $pdo = new \PDO($dsn, $username, $password);
        $store = new PdoStore($pdo);
        $config = new LimiterConfig($capacity, $refillRate);

        return new self(StoreType::MEMORY, $limiterType, $store, $config);
    }

    /**
     * 创建任务并发控制器
     */
    public static function task(int $maxConcurrency = 10): TaskLimiter
    {
        return TaskLimiter::create($maxConcurrency, $maxConcurrency, 1.0);
    }

    /**
     * 创建进程并发控制器
     */
    public static function process(int $maxConcurrency = 10): ProcessLimiter
    {
        return ProcessLimiter::getInstance($maxConcurrency, $maxConcurrency, 1.0);
    }

    /**
     * 创建 Fiber 并发控制器
     */
    public static function fiber(int $maxConcurrency = 10): FiberLimiter
    {
        return new FiberLimiter($maxConcurrency, $maxConcurrency, 1.0, new MemoryStore());
    }

    /**
     * 创建中间件
     */
    public static function middleware(
        LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        int $capacity = 10,
        float $refillRate = 1.0,
        ?StoreInterface $store = null
    ): LimiterMiddleware {
        $store ??= new MemoryStore();
        $config = new LimiterConfig($capacity, $refillRate);
        $limiter = self::createLimiter($limiterType, $store, $config);

        return new LimiterMiddleware($limiter);
    }

    /**
     * 创建限流器实例
     */
    public function build(): RateLimiterInterface
    {
        if ($this->limiter === null) {
            $this->limiter = self::createLimiter($this->limiterType, $this->store, $this->config);
        }

        return $this->limiter;
    }

    /**
     * 检查是否允许操作
     */
    public function allow(string $key, int $tokens = 1): bool
    {
        return $this->build()->allow($key, $tokens);
    }

    /**
     * 获取剩余可用数量
     */
    public function getRemaining(string $key): float
    {
        return $this->build()->getRemaining($key);
    }

    /**
     * 获取等待时间
     */
    public function getWaitTime(string $key): float
    {
        return $this->build()->getWaitTime($key);
    }

    /**
     * 重置限流器
     */
    public function reset(string $key): void
    {
        $this->build()->reset($key);
    }

    /**
     * 获取当前存储
     */
    public function getStore(): StoreInterface
    {
        return $this->store;
    }

    /**
     * 获取当前配置
     */
    public function getConfig(): LimiterConfig
    {
        return $this->config;
    }

    /**
     * 创建限流器实例
     */
    private static function createLimiter(
        LimiterType $type,
        StoreInterface $store,
        LimiterConfig $config
    ): RateLimiterInterface {
        return match ($type) {
            LimiterType::TOKEN_BUCKET => TokenBucket::fromConfig($store, $config),
            LimiterType::SLIDING_WINDOW => SlidingWindow::fromConfig($store, $config),
        };
    }

    /**
     * 解析存储实例
     */
    private function resolveStore(StoreInterface|array|null $store): StoreInterface
    {
        if ($store instanceof StoreInterface) {
            return $store;
        }

        if (is_array($store)) {
            return $this->createStoreFromConfig($store);
        }

        return $this->createDefaultStore();
    }

    /**
     * 解析配置
     */
    private function resolveConfig(LimiterConfig|array|null $config): LimiterConfig
    {
        if ($config instanceof LimiterConfig) {
            return $config;
        }

        if (is_array($config)) {
            return new LimiterConfig(
                $config['capacity'] ?? 10,
                (float) ($config['refillRate'] ?? 1.0),
                $config['ttl'] ?? 3600,
                $config['prefix'] ?? 'limiter:'
            );
        }

        return new LimiterConfig(10, 1.0);
    }

    /**
     * 创建默认存储
     */
    private function createDefaultStore(): StoreInterface
    {
        return match ($this->storeType) {
            StoreType::MEMORY => new MemoryStore(),
            StoreType::REDIS => RedisStore::create('127.0.0.1', 6379, 'kode:limiting:', null, 0),
        };
    }

    /**
     * 从配置创建存储
     */
    private function createStoreFromConfig(array $config): StoreInterface
    {
        $type = StoreType::tryFrom($config['type'] ?? 'memory') ?? StoreType::MEMORY;

        return match ($type) {
            StoreType::MEMORY => new MemoryStore(),
            StoreType::REDIS => RedisStore::create(
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 6379,
                'kode:limiting:',
                $config['password'] ?? null,
                $config['database'] ?? 0
            ),
        };
    }

    /**
     * 解析存储实例（静态方法）
     */
    private static function resolveStoreInstance(StoreInterface|string|array|null $store): StoreInterface
    {
        if ($store instanceof StoreInterface) {
            return $store;
        }

        if (is_string($store)) {
            return match ($store) {
                'redis' => RedisStore::create('127.0.0.1', 6379, 'kode:limiting:', null, 0),
                'memcached' => MemcachedStore::create(),
                default => new MemoryStore(),
            };
        }

        if (is_array($store)) {
            $type = StoreType::tryFrom($store['type'] ?? 'memory') ?? StoreType::MEMORY;
            return match ($type) {
                StoreType::MEMORY => new MemoryStore(),
                StoreType::REDIS => RedisStore::create(
                    $store['host'] ?? '127.0.0.1',
                    $store['port'] ?? 6379,
                    'kode:limiting:',
                    $store['password'] ?? null,
                    $store['database'] ?? 0
                ),
            };
        }

        return new MemoryStore();
    }
}
