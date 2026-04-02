# kode/limiting

高性能 PHP 限流器，支持令牌桶、滑动窗口算法，可用于本地和分布式限流。

## 功能特性

- **令牌桶算法**：支持突发流量，按固定速率补充令牌
- **滑动窗口算法**：基于时间窗口的精确限流
- **本地限流**：使用内存存储，适用于单进程
- **分布式限流**：使用 Redis，支持单机/Sentinel/Cluster 模式
- **并发控制**：支持任务、进程、Fiber 协程的本地和分布式限流
- **原子操作**：Lua 脚本保证分布式环境下的原子性
- **高可用**：支持 Redis Sentinel 和 Cluster 自动故障转移
- **PHP 8.2+**：使用 readonly、enum 等新特性优化性能

## 系统要求

- PHP >= 8.2
- 可选：Redis 扩展（用于分布式部署）

## 安装

```bash
composer require kode/limiting
```

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

### 滑动窗口限流

```php
use Kode\Limiting\Algorithm\SlidingWindow;
use Kode\Limiting\Store\MemoryStore;

$window = new SlidingWindow($store, 100, 1.0);

if ($window->allow('api:v1', 1)) {
    echo "请求通过";
}
```

### 分布式限流（跨机器）

```php
use Kode\Limiting\Distributed\DistributedLimiter;

// 单机模式
$limiter = DistributedLimiter::create(
    '127.0.0.1', 6379,
    1000,    // 容量
    100.0    // 每秒补充100个令牌
);

// Sentinel 高可用模式
$limiter = DistributedLimiter::createSentinel(
    ['192.168.1.1:26379', '192.168.1.2:26379'],
    'mymaster',
    1000,
    100.0
);

// Cluster 分片模式
$limiter = DistributedLimiter::createCluster(
    ['192.168.1.1:6379', '192.168.1.2:6379', '192.168.1.3:6379'],
    1000,
    100.0
);

if ($limiter->allow('global:api', 1)) {
    echo "请求通过";
}
```

### 并发任务控制

```php
use Kode\Limiting\Concurrency\TaskLimiter;

$limiter = TaskLimiter::create(10, 10, 1.0);

// 手动获取和释放
if ($limiter->tryAcquire('task:1')) {
    try {
        do_something();
    } finally {
        $limiter->release('task:1');
    }
}

// 自动释放
$result = $limiter->run('task:2', fn() => do_something());

// 阻塞等待
$limiter->acquire('task:3', 30.0);
```

## 架构设计

```
src/
├── DTO/                          # 数据传输对象
│   ├── LimiterConfig.php        # 限流器配置（readonly）
│   └── LimiterResult.php        # 限流结果（readonly）
├── Enum/                         # 枚举类型
│   ├── LimiterType.php          # 限流器类型
│   ├── StoreType.php            # 存储类型
│   └── RedisMode.php           # Redis 模式
├── Store/                        # 存储层
│   ├── StoreInterface.php       # 存储接口
│   ├── MemoryStore.php          # 内存存储
│   └── RedisStore.php           # Redis 存储（支持 Sentinel/Cluster）
├── Algorithm/                    # 限流算法
│   ├── RateLimiterInterface.php # 限流器接口
│   ├── TokenBucket.php          # 令牌桶
│   └── SlidingWindow.php        # 滑动窗口
├── Distributed/                   # 分布式限流
│   ├── DistributedLimiter.php           # 分布式令牌桶
│   ├── DistributedTaskLimiter.php       # 分布式任务限流
│   ├── DistributedProcessLimiter.php    # 分布式进程限流
│   └── DistributedFiberLimiter.php      # 分布式 Fiber 限流
└── Concurrency/                   # 本地并发控制
    ├── TaskLimiter.php           # 任务限流
    ├── ProcessLimiter.php        # 进程限流
    └── FiberLimiter.php         # Fiber 限流
```

## API 文档

### StoreInterface

存储接口，所有存储实现必须实现此接口。

```php
public function get(string $key): ?string;              // 获取值
public function set(string $key, string $value, int $ttl = 0): void;  // 设置值
public function delete(string $key): void;             // 删除键
public function incr(string $key, int $step = 1): int; // 原子递增
public function ttl(string $key): int;                 // 获取 TTL
```

### RateLimiterInterface

限流器接口。

```php
public function allow(string $key, int $tokens = 1): bool;  // 检查是否允许
public function getRemaining(string $key): float;            // 获取剩余数量
public function getWaitTime(string $key): float;             // 获取等待时间
public function reset(string $key): void;                    // 重置
```

### TokenBucket

令牌桶限流算法。

```php
$bucket = new TokenBucket($store, $capacity, $refillRate, $ttl = 3600, $prefix = 'bucket:');

$bucket->allow('key', 1);           // 检查是否允许
$bucket->getRemaining('key');        // 获取剩余令牌数
$bucket->getWaitTime('key');         // 获取等待时间
$bucket->reset('key');               // 重置
$bucket->getCapacity();              // 获取容量
$bucket->getRefillRate();           // 获取补充速率
$bucket->getTtl();                  // 获取 TTL
$bucket->check('key', 1);           // 检查并返回 LimiterResult
```

### SlidingWindow

滑动窗口限流算法。

```php
$window = new SlidingWindow($store, $capacity, $windowSize = 1.0, $ttl = 3600, $prefix = 'sw:');

$window->allow('key', 1);           // 检查是否允许
$window->getRemaining('key');        // 获取剩余请求数
$window->getWaitTime('key');         // 获取等待时间
$window->reset('key');               // 重置
$window->getCapacity();               // 获取容量
$window->getWindowSize();            // 获取窗口大小
$window->check('key', 1);           // 检查并返回 LimiterResult
```

### DistributedLimiter

分布式限流器（基于 Redis）。

```php
// 单机模式
$limiter = DistributedLimiter::create($host, $port, $capacity, $refillRate, $password, $database);

// Sentinel 模式
$limiter = DistributedLimiter::createSentinel($sentinels, $masterName, $capacity, $refillRate);

// Cluster 模式
$limiter = DistributedLimiter::createCluster($nodes, $capacity, $refillRate);

// 方法
$limiter->allow('key', 1);                    // 检查是否允许（原子操作）
$limiter->tryAcquire('key', 1);               // 尝试获取（别名）
$limiter->getRemaining('key');                 // 获取剩余令牌数
$limiter->getWaitTime('key');                  // 获取等待时间
$limiter->reset('key');                        // 重置
$limiter->allowBatch(['key1', 'key2'], 1);     // 批量检查
$limiter->check('key', 1);                     // 检查并返回 LimiterResult
```

### 分布式并发控制器

```php
use Kode\Limiting\Distributed\DistributedTaskLimiter;
use Kode\Limiting\Distributed\DistributedProcessLimiter;
use Kode\Limiting\Distributed\DistributedFiberLimiter;

$taskLimiter = DistributedTaskLimiter::create('127.0.0.1', 6379, 10);
$processLimiter = DistributedProcessLimiter::create('127.0.0.1', 6379, 10);
$fiberLimiter = DistributedFiberLimiter::create('127.0.0.1', 6379, 100);

$taskLimiter->tryAcquire('task:1');           // 非阻塞获取
$taskLimiter->acquire('task:1', 30.0);        // 阻塞等待
$taskLimiter->run('task:1', fn() => null);   // 执行任务
$taskLimiter->release('task:1');               // 释放
$taskLimiter->getActiveCount();                // 当前活跃数
```

### 本地并发控制器

```php
use Kode\Limiting\Concurrency\TaskLimiter;
use Kode\Limiting\Concurrency\ProcessLimiter;
use Kode\Limiting\Concurrency\FiberLimiter;

$taskLimiter = TaskLimiter::create($maxConcurrency, $capacity, $refillRate);
$processLimiter = ProcessLimiter::getInstance($maxProcesses, $capacity, $refillRate);
$fiberLimiter = new FiberLimiter($maxFibers, $capacity, $refillRate, $store);

$taskLimiter->tryAcquire('task:1');
$taskLimiter->acquire('task:1', 30.0);
$taskLimiter->run('task:1', fn() => null);
$taskLimiter->release('task:1');
$taskLimiter->getMaxConcurrency();
```

### DTO 对象

#### LimiterConfig

限流器配置（不可变对象）。

```php
$config = new LimiterConfig($capacity, $refillRate, $ttl = 3600, $prefix = 'limiter:');

$config->capacity;      // 容量
$config->refillRate;    // 补充速率
$config->ttl;           // TTL
$config->prefix;        // 前缀

// 链式调用创建新实例
$newConfig = $config->withCapacity(200)->withRefillRate(20.0);
```

#### LimiterResult

限流结果（不可变对象）。

```php
$result = $bucket->check('key', 1);

$result->allowed;       // 是否允许
$result->remaining;    // 剩余数量
$result->waitTime;     // 等待时间
$result->timestamp;    // 时间戳

$result->isAllowed();   // 是否允许（布尔值）
$result->toArray();     // 转换为数组
```

### 枚举类型

```php
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Enum\StoreType;
use Kode\Limiting\Enum\RedisMode;

LimiterType::TOKEN_BUCKET->label();      // 令牌桶
LimiterType::SLIDING_WINDOW->label();    // 滑动窗口

StoreType::MEMORY->label();              // 内存存储
StoreType::REDIS->label();               // Redis 存储

RedisMode::STANDALONE->label();          // 单机模式
RedisMode::SENTINEL->label();            // Sentinel 高可用
RedisMode::CLUSTER->label();             // Cluster 分片
```

## 单元测试

测试覆盖所有核心功能，共计 35 个测试用例。

### 测试用例列表

```
Token Bucket (令牌桶)
 ✔ 允许首次请求
 ✔ 允许多次请求
 ✔ 超出容量拒绝
 ✔ 获取剩余令牌数
 ✔ 新键返回完整容量
 ✔ 获取等待时间
 ✔ 重置限流器
 ✔ 时间推移后补充令牌

Sliding Window (滑动窗口)
 ✔ 容量内允许通过
 ✔ 超出容量拒绝
 ✔ 获取剩余请求数
 ✔ 请求后剩余数正确
 ✔ 重置限流器
 ✔ 可用时等待时间为0
 ✔ 耗尽时等待时间大于0

Memory Store (内存存储)
 ✔ 设置和获取
 ✔ 获取不存在的键
 ✔ 删除键
 ✔ 递增操作
 ✔ 新键递增
 ✔ TTL 计算
 ✔ 清空存储

Task Limiter (任务限流)
 ✔ 尝试获取许可
 ✔ 多次获取
 ✔ 释放许可
 ✔ 执行任务
 ✔ 获取最大并发数

Process Limiter (进程限流)
 ✔ 尝试获取许可
 ✔ 释放许可
 ✔ 获取最大进程数
 ✔ 单例模式

Fiber Limiter (Fiber 限流)
 ✔ 支持检查
 ✔ 尝试获取许可
 ✔ 释放许可
 ✔ 获取最大 Fiber 数
```

### 运行测试

```bash
# 运行所有测试
./vendor/bin/phpunit

# 显示详细输出
./vendor/bin/phpunit --testdox

# 运行指定测试类
./vendor/bin/phpunit tests/TokenBucketTest.php

# 生成覆盖率报告
./vendor/bin/phpunit --coverage-html coverage
```

## 自定义存储

实现 `StoreInterface` 接口即可：

```php
use Kode\Limiting\Store\StoreInterface;

class MyStore implements StoreInterface
{
    public function get(string $key): ?string { /* ... */ }
    public function set(string $key, string $value, int $ttl = 0): void { /* ... */ }
    public function delete(string $key): void { /* ... */ }
    public function incr(string $key, int $step = 1): int { /* ... */ }
    public function ttl(string $key): int { /* ... */ }
}
```

## Redis 配置

### 单机模式

```php
$limiter = DistributedLimiter::create(
    '127.0.0.1',    // 主机
    6379,           // 端口
    1000,           // 容量
    100.0,          // 补充速率
    'password',     // 密码（可选）
    0               // 数据库编号
);
```

### Sentinel 模式

```php
$limiter = DistributedLimiter::createSentinel(
    ['192.168.1.1:26379', '192.168.1.2:26379', '192.168.1.3:26379'],
    'mymaster',     // 主节点名称
    1000,
    100.0,
    'sentinel_pass'
);
```

### Cluster 模式

```php
$limiter = DistributedLimiter::createCluster(
    ['192.168.1.1:6379', '192.168.1.2:6379', '192.168.1.3:6379'],
    1000,
    100.0,
    'cluster_pass'
);
```

## 使用场景

### API 限流

```php
$limiter = DistributedLimiter::create('127.0.0.1', 6379, 100, 10.0);

$response = $limiter->allow('api:user:' . $userId, 1)
    ? processRequest()
    : new Response('Rate limited', 429);
```

### 秒杀活动

```php
$limiter = DistributedLimiter::create('127.0.0.1', 6379, 1000, 0);

if (!$limiter->allow('seckill:' . $productId, 1)) {
    throw new Exception('活动已结束');
}
```

### 并发控制

```php
$limiter = DistributedTaskLimiter::create('127.0.0.1', 6379, 10);

foreach ($tasks as $task) {
    $limiter->run('task:' . $task['id'], function () use ($task) {
        processTask($task);
    });
}
```

## 开发

```bash
# 安装依赖
composer install

# 运行测试
./vendor/bin/phpunit

# 语法检查
find src -name "*.php" -exec php -l {} \;
```

## 目录结构

```
kode/limiting/
├── src/
│   ├── DTO/                       # 数据传输对象
│   ├── Enum/                      # 枚举类型
│   ├── Store/                     # 存储层
│   ├── Algorithm/                 # 限流算法
│   ├── Distributed/              # 分布式限流
│   └── Concurrency/               # 并发控制
├── tests/                         # 单元测试
├── composer.json
├── phpunit.xml
├── LICENSE                        # Apache 2.0
└── README.md
```

## 许可证

Apache License 2.0 - 参见 [LICENSE](LICENSE) 文件

## 版本历史

### v1.2.0

- 使用 PHP 8.2 readonly 属性优化性能
- 添加 Enums 枚举类型（LimiterType、StoreType、RedisMode）
- 添加 DTO 不可变对象（LimiterConfig、LimiterResult）
- 优化 TokenBucket 和 SlidingWindow 实现
- 优化 RedisStore 和 MemoryStore
- 优化并发控制器
- 更新 PHP 版本要求为 8.2+

### v1.1.0

- 新增 Redis Sentinel 高可用支持
- 新增 Redis Cluster 分片支持
- 新增分布式并发控制器（Task/Process/Fiber）
- 新增 Lua 脚本原子操作
- 新增 `RateLimiterInterface` 接口

### v1.0.0

- 初始版本
- 令牌桶算法
- 滑动窗口算法
- 本地/分布式存储
- 并发控制
