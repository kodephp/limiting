<?php

declare(strict_types=1);

namespace Kode\Limiting;

use Kode\Limiting\Algorithm\CompositeLimiter;
use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\Concurrency\FiberLimiter;
use Kode\Limiting\Concurrency\ProcessLimiter;
use Kode\Limiting\Concurrency\Semaphore;
use Kode\Limiting\Concurrency\TaskLimiter;
use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Enum\RedisMode;
use Kode\Limiting\Enum\StoreType;
use Kode\Limiting\EventHandler\LimiterEventHandler;
use Kode\Limiting\EventHandler\NullLimiterEventHandler;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Exception\RateLimitExceededException;
use Kode\Limiting\Middleware\LimiterMiddleware;
use Kode\Limiting\Store\ApcuStore;
use Kode\Limiting\Store\MemcachedStore;
use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\PdoStore;
use Kode\Limiting\Store\RedisStore;
use Kode\Limiting\Store\DegradationAwareInterface;
use Kode\Limiting\Store\StoreInterface;

/**
 * 限流器统一入口
 *
 * v2.0 改进：
 *  - 修复 leakyBucket() / counter() 名不副实的问题（此前一律返回令牌桶）；
 *  - 补齐 memcached() / pdo() 的 StoreType 映射；
 *  - 新增流式配置（withXxx）、组合限流、consumeOrFail 等能力；
 *  - 实现 RateLimiterInterface，可与任意算法互换使用。
 *
 * @example
 * ```php
 * // 快速开始
 * Limiter::tokenBucket(100, 10.0)->allow('api:user:123');
 *
 * // 流式配置
 * $limiter = Limiter::of(LimiterType::SLIDING_WINDOW)
 *     ->withCapacity(100)
 *     ->withRate(60.0)
 *     ->withStore(new MemoryStore());
 *
 * // 多规则组合：10 次/秒 且 100 次/分钟
 * Limiter::composite([
 *     'per_second' => Limiter::tokenBucket(10, 10.0)->build(),
 *     'per_minute' => Limiter::counter(100, 60)->build(),
 * ])->allow('api:user:123');
 * ```
 */
class Limiter implements RateLimiterInterface
{
    /** @var string 版本号（与 composer.json 的 version 保持同步，漏改由 VersionGuardTest 拦下） */
    public const string VERSION = '3.1.0';

    /**
     * 获取本包版本号
     */
    public static function version(): string
    {
        return self::VERSION;
    }

    private readonly StoreInterface $store;
    private readonly LimiterConfig $config;
    private ?RateLimiterInterface $limiter = null;
    private ?LimiterEventHandler $eventHandler = null;

    /**
     * @param StoreInterface|array<string, mixed>|null $store
     * @param LimiterConfig|array<string, mixed>|null  $config
     */
    public function __construct(
        private readonly StoreType $storeType = StoreType::MEMORY,
        private readonly LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        StoreInterface|array|null $store = null,
        LimiterConfig|array|null $config = null,
        ?LimiterEventHandler $eventHandler = null
    ) {
        $this->store = match (true) {
            $store instanceof StoreInterface => $store,
            is_array($store) => self::storeFromArray($store),
            default => self::storeFromType($storeType),
        };

        $this->config = match (true) {
            $config instanceof LimiterConfig => $config,
            is_array($config) => LimiterConfig::fromArray($config),
            default => new LimiterConfig(10, 1.0),
        };

        $this->eventHandler = $eventHandler;
    }

    // ---------------------------------------------------------------------
    // 算法工厂
    // ---------------------------------------------------------------------

    /**
     * 通用工厂：按类型创建
     *
     * @param LimiterConfig|array<string, mixed>|null   $config
     * @param StoreInterface|string|array<string, mixed>|null $store
     */
    public static function create(
        LimiterType $type = LimiterType::TOKEN_BUCKET,
        LimiterConfig|array|null $config = null,
        StoreInterface|string|array|null $store = null
    ): self {
        $resolved = self::resolveStore($store);

        return new self(self::detectStoreType($resolved), $type, $resolved, $config);
    }

    /**
     * 以默认配置开启流式构建
     */
    public static function of(LimiterType $type = LimiterType::TOKEN_BUCKET): self
    {
        return new self(StoreType::MEMORY, $type);
    }

    /**
     * 令牌桶：允许突发，按固定速率补充
     *
     * @param StoreInterface|string|array<string, mixed>|null $store
     */
    public static function tokenBucket(
        int $capacity = 10,
        float $refillRate = 1.0,
        StoreInterface|string|array|null $store = null
    ): self {
        return self::create(LimiterType::TOKEN_BUCKET, new LimiterConfig($capacity, $refillRate), $store);
    }

    /**
     * 滑动窗口（精确日志）
     *
     * @param StoreInterface|string|array<string, mixed>|null $store
     */
    public static function slidingWindow(
        int $capacity = 10,
        float $windowSize = 60.0,
        StoreInterface|string|array|null $store = null
    ): self {
        return self::create(LimiterType::SLIDING_WINDOW, new LimiterConfig($capacity, $windowSize), $store);
    }

    /**
     * 滑动窗口计数器（加权近似，内存占用恒定）
     *
     * @param StoreInterface|string|array<string, mixed>|null $store
     */
    public static function slidingWindowCounter(
        int $capacity = 100,
        int $windowSeconds = 60,
        StoreInterface|string|array|null $store = null
    ): self {
        return self::create(
            LimiterType::SLIDING_WINDOW_COUNTER,
            new LimiterConfig($capacity, (float) $windowSeconds),
            $store
        );
    }

    /**
     * 漏桶：固定速率漏出，抑制突发
     *
     * @param StoreInterface|string|array<string, mixed>|null $store
     */
    public static function leakyBucket(
        int $capacity = 10,
        float $leakRate = 1.0,
        StoreInterface|string|array|null $store = null
    ): self {
        return self::create(LimiterType::LEAKY_BUCKET, new LimiterConfig($capacity, $leakRate), $store);
    }

    /**
     * 固定窗口计数器
     *
     * @param StoreInterface|string|array<string, mixed>|null $store
     */
    public static function counter(
        int $maxRequests = 100,
        int $windowSeconds = 60,
        StoreInterface|string|array|null $store = null
    ): self {
        return self::create(LimiterType::COUNTER, new LimiterConfig($maxRequests, (float) $windowSeconds), $store);
    }

    /**
     * 组合多条限流规则，全部通过才放行
     *
     * @param array<string, RateLimiterInterface>|list<RateLimiterInterface> $limiters
     */
    public static function composite(array $limiters): CompositeLimiter
    {
        return new CompositeLimiter($limiters);
    }

    // ---------------------------------------------------------------------
    // 存储工厂
    // ---------------------------------------------------------------------

    /**
     * 使用 Redis 存储
     *
     * @param list<string> $sentinels Sentinel 模式下的哨兵地址
     * @param list<string> $clusterNodes Cluster 模式下的节点地址
     */
    public static function redis(
        LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        int $capacity = 10,
        float $refillRate = 1.0,
        string $host = '127.0.0.1',
        int $port = 6379,
        #[\SensitiveParameter] ?string $password = null,
        int $database = 0,
        RedisMode $mode = RedisMode::STANDALONE,
        array $sentinels = ['127.0.0.1:26379'],
        string $masterName = 'mymaster',
        string $prefix = 'kode:limiting:',
        array $clusterNodes = ['127.0.0.1:7000']
    ): self {
        $store = match ($mode) {
            RedisMode::STANDALONE => RedisStore::create($host, $port, $prefix, $password, $database),
            RedisMode::SENTINEL => RedisStore::createSentinel($sentinels, $masterName, $password, $database, $prefix),
            RedisMode::CLUSTER => RedisStore::createCluster($clusterNodes, $password, $prefix),
        };

        return new self(StoreType::REDIS, $limiterType, $store, new LimiterConfig($capacity, $refillRate));
    }

    /**
     * 使用 Memcached 存储
     */
    public static function memcached(
        LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        int $capacity = 10,
        float $refillRate = 1.0,
        string $host = '127.0.0.1',
        int $port = 11211,
        string $prefix = 'kode:limiting:'
    ): self {
        return new self(
            StoreType::MEMCACHED,
            $limiterType,
            MemcachedStore::create($host, $port, $prefix),
            new LimiterConfig($capacity, $refillRate)
        );
    }

    /**
     * 使用 PDO 数据库存储
     */
    public static function pdo(
        LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        int $capacity = 10,
        float $refillRate = 1.0,
        string $dsn = 'sqlite::memory:',
        ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
        string $table = 'limiting'
    ): self {
        $pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        return new self(
            StoreType::PDO,
            $limiterType,
            new PdoStore($pdo, $table),
            new LimiterConfig($capacity, $refillRate)
        );
    }

    /**
     * 使用 APCu 共享内存存储（单机多进程）
     */
    public static function apcu(
        LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        int $capacity = 10,
        float $refillRate = 1.0
    ): self {
        return new self(
            StoreType::APCU,
            $limiterType,
            ApcuStore::create(),
            new LimiterConfig($capacity, $refillRate)
        );
    }

    // ---------------------------------------------------------------------
    // 并发控制工厂
    // ---------------------------------------------------------------------

    public static function task(int $maxConcurrency = 10, ?StoreInterface $store = null): TaskLimiter
    {
        return TaskLimiter::create($maxConcurrency, $store);
    }

    public static function process(int $maxConcurrency = 10, ?StoreInterface $store = null): ProcessLimiter
    {
        return ProcessLimiter::create($maxConcurrency, $store);
    }

    public static function fiber(int $maxConcurrency = 10, ?StoreInterface $store = null): FiberLimiter
    {
        return FiberLimiter::create($maxConcurrency, $store);
    }

    public static function semaphore(int $maxSlots = 10, ?StoreInterface $store = null): Semaphore
    {
        return new Semaphore($maxSlots, $store ?? new MemoryStore());
    }

    /**
     * 创建限流中间件
     */
    public static function middleware(
        LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
        int $capacity = 10,
        float $refillRate = 1.0,
        ?StoreInterface $store = null,
        string $name = 'default'
    ): LimiterMiddleware {
        $limiter = $limiterType->create(
            $store ?? new MemoryStore(),
            new LimiterConfig($capacity, $refillRate)
        );

        return new LimiterMiddleware($limiter, $name);
    }

    // ---------------------------------------------------------------------
    // 流式配置（返回新实例，保持不可变）
    // ---------------------------------------------------------------------

    public function withCapacity(int $capacity): self
    {
        return new self($this->storeType, $this->limiterType, $this->store, $this->config->withCapacity($capacity), $this->eventHandler);
    }

    public function withRate(float $rate): self
    {
        return new self($this->storeType, $this->limiterType, $this->store, $this->config->withRefillRate($rate), $this->eventHandler);
    }

    public function withTtl(int $ttl): self
    {
        return new self($this->storeType, $this->limiterType, $this->store, $this->config->withTtl($ttl), $this->eventHandler);
    }

    public function withPrefix(string $prefix): self
    {
        return new self($this->storeType, $this->limiterType, $this->store, $this->config->withPrefix($prefix), $this->eventHandler);
    }

    public function withStore(StoreInterface $store): self
    {
        return new self(self::detectStoreType($store), $this->limiterType, $store, $this->config, $this->eventHandler);
    }

    public function withType(LimiterType $type): self
    {
        return new self($this->storeType, $type, $this->store, $this->config, $this->eventHandler);
    }

    /**
     * 绑定事件回调（返回新实例，保持不可变）
     */
    public function withEventHandler(LimiterEventHandler $eventHandler): self
    {
        return new self($this->storeType, $this->limiterType, $this->store, $this->config, $eventHandler);
    }

    // ---------------------------------------------------------------------
    // 运行期
    // ---------------------------------------------------------------------

    /**
     * 构建（并缓存）底层算法实例
     */
    public function build(): RateLimiterInterface
    {
        return $this->limiter ??= $this->limiterType->create($this->store, $this->config);
    }

    #[\Override]
    public function allow(string $key, #[\SensitiveParameter] int $tokens = 1): bool
    {
        return $this->build()->allow($key, $tokens);
    }

    #[\Override]
    public function check(string $key, #[\SensitiveParameter] int $tokens = 1): LimiterResult
    {
        try {
            $result = $this->attachDegradation($this->build()->check($key, $tokens));
            $this->eventHandler()->onResult($key, $result);

            return $result;
        } catch (\Throwable $e) {
            $this->eventHandler()->onError($key, $e);

            throw $e;
        }
    }

    #[\Override]
    public function consume(string $key, #[\SensitiveParameter] int $tokens = 1): LimiterResult
    {
        try {
            $result = $this->attachDegradation($this->build()->consume($key, $tokens));
            $this->eventHandler()->onResult($key, $result);

            return $result;
        } catch (\Throwable $e) {
            $this->eventHandler()->onError($key, $e);

            throw $e;
        }
    }

    /**
     * 消耗额度，超限直接抛出 429 异常
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
    public function getRemaining(string $key): float
    {
        return $this->build()->getRemaining($key);
    }

    #[\Override]
    public function getWaitTime(string $key): float
    {
        return $this->build()->getWaitTime($key);
    }

    #[\Override]
    public function reset(string $key): void
    {
        $this->build()->reset($key);
    }

    #[\Override]
    public function getCapacity(): int
    {
        return $this->config->capacity;
    }

    #[\Override]
    public function getStore(): StoreInterface
    {
        return $this->store;
    }

    public function getConfig(): LimiterConfig
    {
        return $this->config;
    }

    public function getStoreType(): StoreType
    {
        return $this->storeType;
    }

    public function getLimiterType(): LimiterType
    {
        return $this->limiterType;
    }

    /**
     * 包装成中间件
     */
    public function toMiddleware(string $name = 'default'): LimiterMiddleware
    {
        return new LimiterMiddleware($this->build(), $name, $this->eventHandler);
    }

    /**
     * 若底层存储处于降级态，把"降级放行"标记到结果上并清除标记
     */
    private function attachDegradation(LimiterResult $result): LimiterResult
    {
        if ($this->store instanceof DegradationAwareInterface && $this->store->isDegraded()) {
            $reason = $this->store->getDegradationReason();
            $this->store->clearDegradation();

            return $result->withDegraded(true, $reason);
        }

        return $result;
    }

    private function eventHandler(): LimiterEventHandler
    {
        return $this->eventHandler ?? new NullLimiterEventHandler();
    }

    // ---------------------------------------------------------------------
    // 内部：存储解析
    // ---------------------------------------------------------------------

    /**
     * @param StoreInterface|string|array<string, mixed>|null $store
     */
    private static function resolveStore(StoreInterface|string|array|null $store): StoreInterface
    {
        return match (true) {
            $store instanceof StoreInterface => $store,
            is_string($store) => self::storeFromType(
                StoreType::tryFrom($store) ?? throw new InvalidConfigurationException("未知的存储类型：{$store}")
            ),
            is_array($store) => self::storeFromArray($store),
            default => new MemoryStore(),
        };
    }

    /**
     * 将配置解析为存储驱动实例（供 LimiterManager 等复用）
     *
     * @param StoreInterface|string|array<string, mixed>|null $store
     */
    public static function store(StoreInterface|string|array|null $store = null): StoreInterface
    {
        return self::resolveStore($store);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function storeFromArray(array $config): StoreInterface
    {
        $type = StoreType::tryFrom(self::getConfigString($config, 'type', 'memory')) ?? StoreType::MEMORY;

        return match ($type) {
            StoreType::MEMORY => new MemoryStore(),
            StoreType::APCU => ApcuStore::create(self::getConfigString($config, 'prefix', 'kode:limiting:')),
            StoreType::REDIS => match (RedisMode::tryFrom(self::getConfigString($config, 'mode', 'standalone')) ?? RedisMode::STANDALONE) {
                RedisMode::STANDALONE => RedisStore::create(
                    self::getConfigString($config, 'host', '127.0.0.1'),
                    self::getConfigInt($config, 'port', 6379),
                    self::getConfigString($config, 'prefix', 'kode:limiting:'),
                    isset($config['password']) ? self::getConfigString($config, 'password', '') : null,
                    self::getConfigInt($config, 'database', 0)
                ),
                RedisMode::SENTINEL => RedisStore::createSentinel(
                    /** @var array<int, string> */
                    self::normalizeStringArrayFromConfig($config, 'sentinels', ['127.0.0.1:26379']),
                    self::getConfigString($config, 'masterName', 'mymaster'),
                    isset($config['password']) ? self::getConfigString($config, 'password', '') : null,
                    self::getConfigInt($config, 'database', 0),
                    self::getConfigString($config, 'prefix', 'kode:limiting:')
                ),
                RedisMode::CLUSTER => RedisStore::createCluster(
                    /** @var array<int, string> */
                    self::normalizeStringArrayFromConfig($config, 'clusterNodes', ['127.0.0.1:7000']),
                    isset($config['password']) ? self::getConfigString($config, 'password', '') : null,
                    self::getConfigString($config, 'prefix', 'kode:limiting:')
                ),
            },
            StoreType::MEMCACHED => MemcachedStore::create(
                self::getConfigString($config, 'host', '127.0.0.1'),
                self::getConfigInt($config, 'port', 11211),
                self::getConfigString($config, 'prefix', 'kode:limiting:')
            ),
            StoreType::PDO => new PdoStore(
                new \PDO(
                    self::getConfigString($config, 'dsn', 'sqlite::memory:'),
                    isset($config['username']) ? self::getConfigString($config, 'username', '') : null,
                    isset($config['password']) ? self::getConfigString($config, 'password', '') : null,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                ),
                /** @var string $table */
                is_string($config['table'] ?? 'limiting') ? ($config['table'] ?? 'limiting') : 'limiting'
            ),
        };
    }

    /**
     * @param mixed $value
     * @param list<string> $default
     * @return list<string>
     */
    private static function normalizeStringArray(mixed $value, array $default): array
    {
        $result = [];
        foreach ((array) $value as $v) {
            $result[] = (string) $v;
        }

        return $result ?: $default;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $default
     * @return list<string>
     */
    private static function normalizeStringArrayFromConfig(array $config, string $key, array $default): array
    {
        /** @var mixed $value */
        $value = $config[$key] ?? $default;

        return self::normalizeStringArray($value, $default);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getConfigString(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getConfigInt(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        return (int) $value;
    }

    private static function storeFromType(StoreType $type): StoreInterface
    {
        return match ($type) {
            StoreType::MEMORY => new MemoryStore(),
            StoreType::APCU => ApcuStore::create(),
            StoreType::REDIS => RedisStore::create(),
            StoreType::MEMCACHED => MemcachedStore::create(),
            StoreType::PDO => PdoStore::createSqlite(),
        };
    }

    private static function detectStoreType(StoreInterface $store): StoreType
    {
        return match (true) {
            $store instanceof RedisStore => StoreType::REDIS,
            $store instanceof MemcachedStore => StoreType::MEMCACHED,
            $store instanceof PdoStore => StoreType::PDO,
            $store instanceof ApcuStore => StoreType::APCU,
            default => StoreType::MEMORY,
        };
    }
}
