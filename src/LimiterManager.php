<?php

declare(strict_types=1);

namespace Kode\Limiting;

use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\StoreInterface;

/**
 * 配置驱动的限流器注册表
 *
 * 用一份数组配置批量注册命名限流器，按名取用，适合在框架引导阶段
 * 集中声明多条限流规则，并在请求处理中按业务名取用。
 *
 * @example
 * ```php
 * $manager = LimiterManager::fromConfig([
 *     'stores' => [
 *         'redis' => ['type' => 'redis', 'host' => '127.0.0.1'],
 *     ],
 *     'limiters' => [
 *         'api'    => ['type' => 'token_bucket', 'capacity' => 100, 'refillRate' => 10, 'store' => 'redis'],
 *         'login'  => ['type' => 'counter', 'limit' => 5, 'window' => 60],
 *     ],
 * ]);
 *
 * $manager->allow('api', 'user:123');
 * $manager->consume('login', 'user:456');
 * ```
 */
final class LimiterManager
{
    /** @var array<string, RateLimiterInterface> */
    private array $limiters = [];

    /** @var array<string, StoreInterface> */
    private array $stores = [];

    /**
     * 从数组配置构建注册表
     *
     * 配置结构：
     * - stores.limiters[名称] => 存储配置（数组 / 类型字符串），同名存储仅构建一次并复用；
     * - limiters[名称]         => 限流配置，支持 type / capacity|limit / refillRate|refill_rate|window / ttl / prefix / store。
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $manager = new self();

        /** @var array<string, mixed> $storesConfig */
        $storesConfig = $config['stores'] ?? [];

        foreach ($storesConfig as $name => $storeConfig) {
            $manager->stores[$name] = Limiter::store($storeConfig);
        }

        /** @var array<string, mixed> $limitersConfig */
        $limitersConfig = $config['limiters'] ?? [];

        foreach ($limitersConfig as $name => $limiterConfig) {
            $manager->register($name, $manager->buildLimiter($limiterConfig));
        }

        return $manager;
    }

    /**
     * 注册一个命名限流器（返回 $this 以支持链式调用）
     */
    public function register(string $name, RateLimiterInterface $limiter): self
    {
        if (trim($name) === '') {
            throw InvalidConfigurationException::emptyKey();
        }

        $this->limiters[$name] = $limiter;

        return $this;
    }

    /**
     * 按名移除一个限流器（返回 $this）
     */
    public function remove(string $name): self
    {
        unset($this->limiters[$name]);

        return $this;
    }

    /**
     * 取用命名限流器
     *
     * @throws InvalidConfigurationException 名称未注册时
     */
    public function limiter(string $name): RateLimiterInterface
    {
        if (!isset($this->limiters[$name])) {
            throw new InvalidConfigurationException("未注册的限流器：{$name}");
        }

        return $this->limiters[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->limiters[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->limiters);
    }

    /**
     * 便捷放行判定
     */
    public function allow(string $name, string $key, int $tokens = 1): bool
    {
        return $this->limiter($name)->allow($key, $tokens);
    }

    /**
     * 便捷预检（不消耗额度）
     */
    public function check(string $name, string $key, int $tokens = 1): LimiterResult
    {
        return $this->limiter($name)->check($key, $tokens);
    }

    /**
     * 便捷消耗额度
     */
    public function consume(string $name, string $key, int $tokens = 1): LimiterResult
    {
        return $this->limiter($name)->consume($key, $tokens);
    }

    /**
     * 解析单个限流配置为算法实例
     *
     * @param array<string, mixed> $config
     */
    private function buildLimiter(array $config): RateLimiterInterface
    {
        $type = LimiterType::tryFrom((string) ($config['type'] ?? 'token_bucket'))
            ?? LimiterType::TOKEN_BUCKET;

        $limiterConfig = LimiterConfig::fromArray($config);
        $store = $this->resolveStoreForLimiter($config);

        return Limiter::create($type, $limiterConfig, $store);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveStoreForLimiter(array $config): StoreInterface
    {
        if (!isset($config['store'])) {
            return new MemoryStore();
        }

        $storeRef = $config['store'];

        if (is_string($storeRef) && isset($this->stores[$storeRef])) {
            return $this->stores[$storeRef];
        }

        return Limiter::store($storeRef);
    }
}
