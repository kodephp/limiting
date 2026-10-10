# kode/limiting

高性能 PHP 限流器，支持令牌桶、漏桶、滑动窗口、滑动窗口计数器、固定窗口计数器与多规则**组合限流**；内置内存 / APCu / Redis / Memcached / PDO 存储与分布式并发控制。PHP 8.4+。


## 版本自述

本包版本可由类常量核对：`Kode\Limiting\Limiter::VERSION`，或调用 `Limiter::version()`（当前 `2.4.0`）。

`composer.json` 的 `version` 字段是 composer 侧的权威值，类常量是它的交叉核对副本——`tests/VersionGuardTest.php` 在两者不一致时直接失败，杜绝「tag 打了、常量忘改」的漂移。

## 功能特性

- **五种限流算法**：令牌桶（支持突发）、漏桶（抑制突发）、滑动窗口（精确日志）、滑动窗口计数器（加权近似、内存恒定）、固定窗口计数器。
- **组合限流**：`CompositeLimiter` 将多条规则串联，全部通过才放行（两阶段：预检 + 提交；提交失败按本次增量扣回 `refund()`，不清空活窗口）。
- **多存储驱动**：内存（单进程）、APCu（单机多进程）、Redis / Memcached / PDO（分布式，跨机器一致）。
- **并发控制**：任务 / 进程 / Fiber 协程的本地与分布式并发上限，底层统一为共享槽位**计数信号量**。
- **分布式原子性**：Redis Lua 脚本保证「读取→补充→扣减→写回」原子执行，支持单机 / Sentinel / Cluster。
- **声明式注解**：`#[RateLimit]` 标注在控制器上，由框架中间件反射读取。
- **结构化结果**：`LimiterResult` 携带剩余额度、重试等待、429 响应头，可直接接入各类框架。
- **配置驱动注册表**：`LimiterManager::fromConfig()` 一份数组批量注册命名限流器，按名取用。
- **容灾降级**：`ResilientStore` 装饰任意存储，Redis / PDO 宕机时按策略兜底（默认 fail-open 放行并标记降级），业务不被限流器拖垮。
- **事件回调**：`LimiterEventHandler` 在每次决策后（`onResult`）与异常时（`onError`）回调，方便接入日志 / 指标 / 告警。
- **质量门禁**：内置 PHPStan（level 6）静态分析，`composer analyze` 一键检查。
- **PHP 8.4+**：使用 `#[\Override]`、typed class constants、`json_validate()` 等新特性。

## 系统要求

- **PHP >= 8.3**
- 可选扩展（按所用存储驱动）：`ext-redis`、`ext-apcu`、`ext-memcached`、`ext-pdo`

## 安装

```bash
composer require kode/limiting
```

## 统一入口 `Limiter`（推荐）

```php
use Kode\Limiting\Limiter;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Enum\RedisMode;

// 令牌桶（默认，支持突发）
Limiter::tokenBucket(100, 10.0)->allow('api:user:123');

// 滑动窗口（精确日志）
Limiter::slidingWindow(100, 60.0)->allow('api:user:123');

// 滑动窗口计数器（加权近似，内存恒定）
Limiter::slidingWindowCounter(100, 60)->allow('api:user:123');

// 漏桶（抑制突发）
Limiter::leakyBucket(100, 1.0)->allow('api:user:123');

// 固定窗口计数器
Limiter::counter(1000, 60)->allow('api:user:123');

// 分布式：Redis / Memcached / PDO / APCu（$prefix 参数可自定义键前缀，默认 'kode:limiting:'）
Limiter::redis(LimiterType::TOKEN_BUCKET, 100, 10.0, '127.0.0.1', 6379, null, 0, RedisMode::STANDALONE, ['127.0.0.1:26379'], 'mymaster', 'myapp:rl:');
Limiter::memcached(LimiterType::TOKEN_BUCKET, 100, 10.0, '127.0.0.1', 11211, 'myapp:rl:');
Limiter::pdo(LimiterType::TOKEN_BUCKET, 100, 10.0, 'mysql:host=127.0.0.1;dbname=limiting', 'root', 'password');
Limiter::apcu(LimiterType::TOKEN_BUCKET, 100, 10.0);

// Redis Sentinel / Cluster 模式（prefix 统一透传到 RedisStore）
Limiter::redis(LimiterType::TOKEN_BUCKET, 100, 10.0, mode: RedisMode::SENTINEL, sentinels: ['192.168.1.1:26379', '192.168.1.2:26379'], masterName: 'mymaster', prefix: 'myapp:rl:');
Limiter::redis(LimiterType::TOKEN_BUCKET, 100, 10.0, mode: RedisMode::CLUSTER, clusterNodes: ['192.168.1.1:7000', '192.168.1.2:7000'], prefix: 'myapp:rl:');

// 多规则组合：10 次/秒 且 100 次/分钟
Limiter::composite([
    'per_second' => Limiter::tokenBucket(10, 10.0)->build(),
    'per_minute' => Limiter::counter(100, 60)->build(),
])->allow('api:user:123');

// 并发控制
Limiter::task(10);      // 任务并发
Limiter::process(10);   // 进程并发
Limiter::fiber(10);     // Fiber 并发
Limiter::semaphore(10); // 计数信号量

// 中间件
$middleware = Limiter::middleware(LimiterType::TOKEN_BUCKET, 100, 10.0);
```

`Limiter` 实例方法：`allow()` / `check()`（返回 `LimiterResult`）/ `consume()` / `consumeOrFail()`（超限抛 `RateLimitExceededException`）/ `getRemaining()` / `getWaitTime()` / `reset()` / `getCapacity()`；流式配置 `withCapacity()` / `withRate()` / `withTtl()` / `withPrefix()` / `withStore()` / `withType()` / `withEventHandler()`（均返回新实例，保持不可变）。

## 快速开始

### 本地限流（令牌桶）

```php
use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Store\MemoryStore;

$store = new MemoryStore();
$bucket = new TokenBucket($store, 100, 10.0);

if ($bucket->allow('user:123', 1)) {
    echo "请求通过";
} else {
    echo "被限流";
}

echo "剩余令牌: " . $bucket->getRemaining('user:123');
echo "等待时间: " . $bucket->getWaitTime('user:123') . "秒";
```

### 组合限流

```php
use Kode\Limiting\Algorithm\CompositeLimiter;
use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Algorithm\Counter;
use Kode\Limiting\Store\MemoryStore;

$composite = new CompositeLimiter([
    'per_second' => new TokenBucket(new MemoryStore(), 10, 10.0, 3600, 'c:'),
    'per_minute' => new Counter(new MemoryStore(), 100, 60, 'c:'),
]);

if ($composite->allow('user:1')) {
    // 两条规则同时放行
}

// 定位最先拒绝的规则，便于日志排障
$rule = $composite->firstDeniedRule('user:1');
```

### 并发控制（共享槽位信号量）

```php
use Kode\Limiting\Concurrency\TaskLimiter;

// v2.0 签名：create(最大并发, ?存储, 前缀)
$limiter = TaskLimiter::create(5);

if ($limiter->tryAcquire('task:1')) {
    try {
        do_something();
    } finally {
        $limiter->release('task:1');
    }
}

// 自动释放：槽位在回调结束后释放（成功或异常皆然）
$result = $limiter->run('task:2', fn() => do_something());

$limiter->getActiveCount(); // 当前活跃数
$limiter->getAvailable();   // 剩余可用槽位
```

> **v2.0 迁移提示**：v1.x 的 `TaskLimiter::create($max, $capacity, $rate)`、`ProcessLimiter::getInstance($max, $capacity, $rate)`、`new FiberLimiter($max, $capacity, $rate, $store)` 已全部改为「共享槽位信号量」语义。并发上限现在真实生效，旧签名不再兼容。

### 分布式限流（跨机器）

```php
use Kode\Limiting\Distributed\DistributedLimiter;

// 单机
$limiter = DistributedLimiter::create('127.0.0.1', 6379, 1000, 100.0);
// Sentinel 高可用
$limiter = DistributedLimiter::createSentinel(
    ['192.168.1.1:26379', '192.168.1.2:26379'], 'mymaster', 1000, 100.0
);
// Cluster 分片
$limiter = DistributedLimiter::createCluster(
    ['192.168.1.1:6379', '192.168.1.2:6379', '192.168.1.3:6379'], 1000, 100.0
);

if ($limiter->allow('global:api', 1)) {
    echo "请求通过";
}

// 批量判定：返回 [放行的 key, 被限流的 key]
[$ok, $denied] = $limiter->allowBatch(['k1', 'k2', 'k3']);
```

### 声明式注解

```php
use Kode\Limiting\Attribute\RateLimit;

#[RateLimit(capacity: 60, rate: 1.0, key: 'api:{user_id}')]
public function index(): Response { /* ... */ }

// 运行期渲染 key
$key = $attr->resolveKey(['user_id' => 42]); // => 'api:42'
```

### 配置驱动注册表 `LimiterManager`

用一份数组在框架引导阶段集中声明多条限流规则，运行期按业务名取用：

```php
use Kode\Limiting\LimiterManager;

$manager = LimiterManager::fromConfig([
    'stores' => [
        'redis' => ['type' => 'redis', 'host' => '127.0.0.1'],
        // Redis Sentinel / Cluster（storeFromArray 按 mode 自动路由）
        'redis_ha' => ['type' => 'redis', 'mode' => 'sentinel', 'sentinels' => ['192.168.1.1:26379', '192.168.1.2:26379'], 'masterName' => 'mymaster', 'prefix' => 'myapp:rl:'],
        'redis_cluster' => ['type' => 'redis', 'mode' => 'cluster', 'clusterNodes' => ['192.168.1.1:7000', '192.168.1.2:7000'], 'prefix' => 'myapp:rl:'],
    ],
    'limiters' => [
        'api'   => ['type' => 'token_bucket', 'capacity' => 100, 'refillRate' => 10, 'store' => 'redis'],
        'login' => ['type' => 'counter', 'limit' => 5, 'window' => 60], // 未指定 store 则用内存
    ],
]);

$manager->allow('api', 'user:123');                // 是否放行
$result = $manager->consume('login', 'user:456');  // 消耗并取结果
$manager->has('api');                              // 是否已注册
$manager->names();                                 // ['api', 'login']
```

同名存储只构建一次并复用；`store` 字段可填存储名、存储配置数组或类型字符串。

### 容灾降级与事件回调

把任意存储包进 `ResilientStore`，底层（Redis / PDO 等）不可用时按策略兜底，业务不会被限流器拖垮：

```php
use Kode\Limiting\Enum\FailurePolicy;
use Kode\Limiting\Store\RedisStore;
use Kode\Limiting\Store\ResilientStore;
use Kode\Limiting\Limiter;

// 默认 fail-open：存储宕机时放行，并把结果标记为降级态
$safe = new ResilientStore(RedisStore::create(), FailurePolicy::ALLOW);
$result = Limiter::tokenBucket(100, 10.0, $safe)->consume('api:user:1');

if ($result->degraded) {
    // 降级放行：记录告警，但请求照常通过
    error_log('限流器降级：' . $result->degradedReason);
}

// 严格模式：存储故障时原样抛出 StoreException
$strict = new ResilientStore(RedisStore::create(), FailurePolicy::RETHROW);
```

降级结果会体现在 `LimiterResult`（额外携带 `degraded` / `degradedReason`）与响应头（`X-RateLimit-Degraded: 1`）上，便于观测。

事件回调（`LimiterEventHandler`）则在每次决策后 / 异常时触发，方便接入日志、指标与告警：

```php
use Kode\Limiting\EventHandler\LimiterEventHandler;
use Kode\Limiting\DTO\LimiterResult;

$handler = new class implements LimiterEventHandler {
    public function onResult(string $key, LimiterResult $result): void {
        if ($result->degraded) { /* 上报降级 */ }
    }
    public function onError(string $key, \Throwable $error): void {
        /* 上报异常 */
    }
};

Limiter::tokenBucket(100, 10.0)->withEventHandler($handler)->consume('api:user:1');
```

`LimiterMiddleware` 同样支持 `withEventHandler()`，并在降级时把 `X-RateLimit-Degraded` 写入响应头。

## 架构

```
src/
├── Limiter.php                      # 统一入口类
├── LimiterManager.php               # 配置驱动注册表（fromConfig）
├── Attribute/RateLimit.php          # 声明式限流注解
├── DTO/
│   ├── LimiterConfig.php            # 限流配置（不可变，构造即校验）
│   └── LimiterResult.php            # 限流结果（不可变，含 429 响应头）
├── Enum/
│   ├── LimiterType.php              # 算法类型
│   ├── StoreType.php                # 存储类型
│   ├── RedisMode.php               # Redis 模式
│   └── FailurePolicy.php           # 存储故障兜底策略（ALLOW / RETHROW）
├── Exception/                       # 异常体系（接口 + 4 个实现）
├── EventHandler/                    # 事件回调
│   ├── LimiterEventHandler.php      # 决策 / 异常回调契约
│   └── NullLimiterEventHandler.php  # 空实现（默认）
├── Store/                           # 存储层
│   ├── StoreInterface.php           # 存储接口
│   ├── DegradationAwareInterface.php# 降级感知标记接口
│   ├── MemoryStore.php              # 内存存储
│   ├── ApcuStore.php               # APCu 存储（单机多进程）
│   ├── RedisStore.php              # Redis 存储（Sentinel/Cluster + Lua）
│   ├── MemcachedStore.php          # Memcached 存储
│   └── PdoStore.php               # PDO 存储（MySQL/SQLite/PostgreSQL）
│   └── ResilientStore.php         # 容灾装饰器（fail-open / rethrow）
├── Algorithm/                       # 限流算法
│   ├── RateLimiterInterface.php     # 统一接口（allow/check/consume/.../getStore）
│   ├── AbstractRateLimiter.php      # 公共基类（校验/JSON 状态读写）
│   ├── TokenBucket.php              # 令牌桶
│   ├── SlidingWindow.php            # 滑动窗口（精确日志）
│   ├── SlidingWindowCounter.php     # 滑动窗口计数器（加权近似）
│   ├── LeakyBucket.php              # 漏桶
│   ├── Counter.php                  # 固定窗口计数器
│   └── CompositeLimiter.php        # 组合限流
├── Distributed/                     # 分布式限流
│   ├── DistributedLimiter.php       # 分布式令牌桶（Lua）
│   ├── AbstractDistributedSemaphore.php
│   ├── DistributedTaskLimiter.php
│   ├── DistributedProcessLimiter.php
│   └── DistributedFiberLimiter.php
├── Concurrency/                     # 本地并发控制
│   ├── Semaphore.php                # 计数信号量（公共内核）
│   ├── TaskLimiter.php
│   ├── ProcessLimiter.php
│   └── FiberLimiter.php
└── Middleware/
    ├── LimiterMiddleware.php        # 限流中间件（handle 管道 + 响应头 + 降级/事件）
    └── LimiterMiddlewareInterface.php
```

## API 速查

### StoreInterface

```php
public function get(string $key): ?string;              // 获取值
public function set(string $key, string $value, int $ttl = 0): void; // 设置值，ttl=0 不过期
public function delete(string $key): void;             // 删除键
public function incr(string $key, int $step = 1, int $ttl = 0): int; // 原子递增
public function decr(string $key, int $step = 1): int; // 原子递减
public function ttl(string $key): int;                 // 剩余 TTL（>=0 / -1 永不过期 / -2 不存在）
public function has(string $key): bool;                // 键是否存在且未过期
```

### RateLimiterInterface

```php
public function allow(string $key, int $tokens = 1): bool;        // 是否放行
public function check(string $key, int $tokens = 1): LimiterResult; // 只读探测
public function consume(string $key, int $tokens = 1): LimiterResult; // 消耗并返回结果
public function getRemaining(string $key): float;                // 剩余额度
public function getWaitTime(string $key): float;                 // 建议等待秒数
public function reset(string $key): void;                       // 重置
public function getCapacity(): int;                              // 总额度
```

### LimiterResult

```php
$result = $bucket->check('key', 1);

$result->allowed;        // 是否放行
$result->remaining;      // 剩余额度
$result->retryAfter;     // 建议重试等待秒数（放行时为 0）
$result->limit;          // 总额度
$result->resetAfter;     // 额度完全恢复所需秒数
$result->timestamp;      // 结果产生时刻（毫秒）
$result->degraded;       // 是否处于降级态（存储故障时兜底放行）
$result->degradedReason; // 触发降级的原因（未降级时为 null）

$result->isAllowed();
$result->isDenied();
$result->toHeaders();     // ['X-RateLimit-Limit'=>..., 'X-RateLimit-Remaining'=>..., 'Retry-After'=>...]（被拒时）；降级时附 'X-RateLimit-Degraded'=>'1'
$result->toArray();
json_encode($result);    // 等同 toArray()
```

### LimiterConfig

```php
$config = new LimiterConfig($capacity, $refillRate, $ttl = 3600, $prefix = 'limiter:');
$config->capacity;
$config->refillRate;
$config->ttl;
$config->prefix;

// 链式创建新实例（不可变）
$newConfig = $config->withCapacity(200)->withRefillRate(20.0);

// 从数组创建（兼容 capacity/limit、refillRate/refill_rate/window 等键名）
$config = LimiterConfig::fromArray(['capacity' => 100, 'window' => 60]);
```

## 单元测试

测试覆盖全部核心功能，共 **125 个测试用例、540 项断言**（PHPUnit 10.5/11，PHP 8.3），全绿。

```bash
# 运行全部
composer test            # 等价于 ./vendor/bin/phpunit --no-coverage
./vendor/bin/phpunit

# 静态分析（PHPStan level 6）
composer analyze

# 详细输出
./vendor/bin/phpunit --testdox

# 单文件
./vendor/bin/phpunit tests/CompositeLimiterTest.php

# 覆盖率（需 xdebug / pcov）
./vendor/bin/phpunit --coverage-html coverage
```

## 自定义存储

实现 `StoreInterface` 即可：

```php
use Kode\Limiting\Store\StoreInterface;

class MyStore implements StoreInterface
{
    public function get(string $key): ?string { /* ... */ }
    public function set(string $key, string $value, int $ttl = 0): void { /* ... */ }
    public function delete(string $key): void { /* ... */ }
    public function incr(string $key, int $step = 1, int $ttl = 0): int { /* ... */ }
    public function decr(string $key, int $step = 1): int { /* ... */ }
    public function ttl(string $key): int { /* ... */ }
    public function has(string $key): bool { /* ... */ }
}
```

## 开发

```bash
composer install
composer test        # 运行测试
composer lint        # 语法检查（find src tests -name '*.php' -exec php -l）
composer analyze     # PHPStan 静态分析
```

## 从 v1.x 升级到 v2.0

- **PHP**：最低要求 `>= 8.3`（原为 8.2）。
- **并发限流器签名变更**：`TaskLimiter::create(int $maxConcurrency, ?StoreInterface $store = null, string $prefix = 'task:')`、`ProcessLimiter::getInstance(int $maxProcesses = 10, string $prefix = 'process:')`、`new FiberLimiter(int $maxFibers, StoreInterface $store = new MemoryStore(), string $prefix = 'fiber:')`。并发上限现在真实生效。
- **`LimiterResult`**：旧版 `$result->waitTime` 更名为 `retryAfter`；构造时不再二次赋值 readonly 属性。
- **`RedisStore::eval()`**：已重命名为 `evalScript(string $script, array $keys, array $args)`，分布式限流现在真正生效（v1.x 的 ARGV 被丢弃从未生效）。
- **`Limiter::leakyBucket()` / `counter()`**：现在分别返回真正的漏桶与计数器（v1.x 一律返回令牌桶）。

## 从 v2.0 升级到 v2.1

v2.1.0 为**向后兼容的增量版本**（纯新增能力，未改动既有 API 行为）：

- **新增 `LimiterManager`**：`LimiterManager::fromConfig($array)` 批量注册命名限流器，按名取用。
- **新增容灾降级**：`ResilientStore` 装饰任意存储，底层故障时默认 fail-open 放行并标记 `LimiterResult::$degraded`，响应头增加 `X-RateLimit-Degraded`。`FailurePolicy` 提供 `ALLOW` / `RETHROW` 两种策略。
- **新增事件回调**：`LimiterEventHandler::onResult()` / `onError()`，可通过 `Limiter::withEventHandler()` 与 `LimiterMiddleware::withEventHandler()` 绑定。
- **新增 `RateLimiterInterface::getStore()`**：所有算法均实现（组合限流器返回首个子规则的存储），供中间件统一检测降级。
- **新增静态分析**：内置 PHPStan（level 6），`composer analyze` 检查。

既有调用方式（v2.0）无需任何改动即可升级到 v2.1.0。

## 从 v2.1 升级到 v2.2

v2.2.0 为**向后兼容的增量版本**（新增可选参数，未改动既有 API 行为）：

- **`Limiter::redis()` 新增 `$prefix` 参数**：此前 STANDALONE 硬编码 `'kode:limiting:'`、SENTINEL/CLUSTER 省略 prefix（落回默认值）；现三种模式统一透传到 `RedisStore`。
- **`Limiter::memcached()` 新增 `$prefix` 参数**：此前签名无 prefix，`MemcachedStore::create()` 不传 prefix；现与 Redis/APCu 一致。
- **`storeFromArray()` Redis 分支支持 sentinel/cluster**：此前只走 standalone；现按 `config['mode']` 路由三模式，各分支从 config 取 `sentinels`/`masterName`/`clusterNodes`/`prefix` 等键。

既有调用方式（v2.1）无需任何改动即可升级到 v2.2.0——所有新参数均带默认值 `'kode:limiting:'`。

## 许可证

Apache License 2.0 - 参见 [LICENSE](LICENSE) 文件
