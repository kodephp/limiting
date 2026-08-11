<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Limiter;
use Kode\Limiting\LimiterManager;
use Kode\Limiting\Store\MemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * LimiterManager 配置驱动注册表测试
 */
final class LimiterManagerTest extends TestCase
{
    private function config(): array
    {
        return [
            'limiters' => [
                'api' => ['type' => 'token_bucket', 'capacity' => 100, 'refillRate' => 10],
                'login' => ['type' => 'counter', 'limit' => 5, 'window' => 60],
            ],
        ];
    }

    public function testFromConfigRegistersNamedLimiters(): void
    {
        $manager = LimiterManager::fromConfig($this->config());

        self::assertSame(['api', 'login'], $manager->names());
        self::assertTrue($manager->has('api'));
        self::assertFalse($manager->has('missing'));
    }

    public function testAllowAndConsumeViaManager(): void
    {
        $manager = LimiterManager::fromConfig($this->config());

        self::assertTrue($manager->allow('api', 'user:1'));

        $result = $manager->consume('login', 'user:1');
        self::assertInstanceOf(LimiterResult::class, $result);
        self::assertTrue($result->isAllowed());
    }

    public function testUnknownLimiterThrows(): void
    {
        $manager = LimiterManager::fromConfig($this->config());

        $this->expectException(InvalidConfigurationException::class);
        $manager->limiter('nope');
    }

    public function testRegisterRemoveAndEmptyName(): void
    {
        $manager = new LimiterManager();

        $manager->register('x', Limiter::tokenBucket(10, 1.0, new MemoryStore()));
        self::assertTrue($manager->has('x'));

        $manager->remove('x');
        self::assertFalse($manager->has('x'));

        $this->expectException(InvalidConfigurationException::class);
        $manager->register('', Limiter::tokenBucket(1, 1.0, new MemoryStore()));
    }

    public function testStoreReuseByName(): void
    {
        $manager = LimiterManager::fromConfig([
            'stores' => [
                'shared' => ['type' => 'memory'],
            ],
            'limiters' => [
                'a' => ['type' => 'token_bucket', 'capacity' => 10, 'refillRate' => 1, 'store' => 'shared'],
                'b' => ['type' => 'counter', 'limit' => 5, 'window' => 60, 'store' => 'shared'],
            ],
        ]);

        // 共享同一 MemoryStore：a 取走的额度会影响 b 看到的剩余（这里仅确认可运行）
        self::assertTrue($manager->allow('a', 'k'));
        self::assertTrue($manager->allow('b', 'k'));
    }
}
