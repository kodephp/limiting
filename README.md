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

## 系统要求

- PHP >= 8.1
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

$limiter = new TaskLimiter(10, 10, 1.0);

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

### 分布式并发控制

```php
use Kode\Limiting\Distributed\DistributedTaskLimiter;

$limiter = DistributedTaskLimiter::create(
    '127.0.0.1', 6379,
    10  // 最大并发数
);

$limiter->run('task:distributed:1', function () {
    echo "任务执行中...";
});
```

## 架构设计

```
src/
├── Store/                      # 存储层
│   ├── StoreInterface.php       # 存储接口
│   ├── MemoryStore.php          # 内存存储
│   └── RedisStore.php           # Redis 存储（支持 Sentinel/Cluster）
├── Algorithm/                  # 限流算法
│   ├── RateLimiterInterface.php # 限流器接口
│   ├── TokenBucket.php          # 令牌桶
│   └── SlidingWindow.php        # 滑动窗口
├── Distributed/                 # 分布式限流
│   ├── DistributedLimiter.php          # 分布式令牌桶
│   ├── DistributedTaskLimiter.php      # 分布式任务限流
│   ├── DistributedProcessLimiter.php   # 分布式进程限流
│   └── DistributedFiberLimiter.php     # 分布式 Fiber 限流
└── Concurrency/                 # 本地并发控制
    ├── TaskLimiter.php          # 任务限流
    ├── ProcessLimiter.php       # 进程限流
    └── FiberLimiter.php         # Fiber 限流
```

## API 文档

### StoreInterface

存储接口，所有存储实现必须实现此接口。

```php
public function get(string $key): ?string;
public function set(string $key, string $value, int $ttl = 0): void;
public function delete(string $key): void;
public function incr(string $key, int $step = 1): int;
public function ttl(string $key): int;
```

### RateLimiterInterface

限流器接口。

```php
public function allow(string $key, int $tokens = 1): bool;
public function getRemaining(string $key): float;
public function getWaitTime(string $key): float;
public function reset(string $key): void;
```

### TokenBucket

令牌桶限流算法。

```php
$bucket = new TokenBucket($store, $capacity, $refillRate, $ttl = 3600);

$bucket->allow('key', 1);           // 检查是否允许
$bucket->getRemaining('key');       // 获取剩余令牌数
$bucket->getWaitTime('key');        // 获取等待时间
$bucket->reset('key');              // 重置
$bucket->getCapacity();              // 获取容量
$bucket->getRefillRate();           // 获取补充速率
```

### SlidingWindow

滑动窗口限流算法。

```php
$window = new SlidingWindow($store, $capacity, $windowSize = 1.0, $ttl = 3600);

$window->allow('key', 1);           // 检查是否允许
$window->getRemaining('key');       // 获取剩余请求数
$window->getWaitTime('key');        // 获取等待时间
$window->reset('key');              // 重置
$window->getCapacity();              // 获取容量
$window->getWindowSize();           // 获取窗口大小
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
$limiter->getRemaining('key');                // 获取剩余令牌数
$limiter->getWaitTime('key');                 // 获取等待时间
$limiter->reset('key');                       // 重置
$limiter->allowBatch(['key1', 'key2'], 1);    // 批量检查
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
$taskLimiter->release('task:1');              // 释放
$taskLimiter->getActiveCount();               // 当前活跃数
```

### 本地并发控制器

```php
use Kode\Limiting\Concurrency\TaskLimiter;
use Kode\Limiting\Concurrency\ProcessLimiter;
use Kode\Limiting\Concurrency\FiberLimiter;

$taskLimiter = new TaskLimiter($maxConcurrency, $capacity, $refillRate);
$processLimiter = ProcessLimiter::getInstance($maxProcesses, $capacity, $refillRate);
$fiberLimiter = new FiberLimiter($maxFibers, $capacity, $refillRate);

$taskLimiter->tryAcquire('task:1');
$taskLimiter->acquire('task:1', 30.0);
$taskLimiter->run('task:1', fn() => null);
$taskLimiter->release('task:1');
$taskLimiter->getActiveCount();
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
│   ├── Store/                      # 存储层
│   ├── Algorithm/                  # 限流算法
│   ├── Distributed/                # 分布式限流
│   └── Concurrency/                # 并发控制
├── tests/                          # 单元测试
├── composer.json
├── phpunit.xml
├── LICENSE                         # Apache 2.0
└── README.md
```

## 许可证

Apache License 2.0 - 参见 [LICENSE](LICENSE) 文件