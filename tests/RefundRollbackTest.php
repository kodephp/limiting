<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Algorithm\CompositeLimiter;
use Kode\Limiting\Algorithm\Counter;
use Kode\Limiting\Algorithm\RateLimiterInterface;
use Kode\Limiting\Algorithm\RefundableInterface;
use Kode\Limiting\Algorithm\SlidingWindowCounter;
use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\StoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * 回滚语义回归：组合提交失败只扣回本次增量（refund），不再 reset 清空活窗口；
 * 各算法 refund 数学正确；MemoryStore 机会性清理防无界增长。
 */
class RefundRollbackTest extends TestCase
{
    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
    }

    public function testCompositeRollbackUsesRefundNotReset(): void
    {
        $committed = new class implements RateLimiterInterface, RefundableInterface {
            /** @var list<array{string, int}> */
            public array $refunds = [];

            /** @var list<string> */
            public array $resets = [];

            public function allow(string $key, int $tokens = 1): bool
            {
                return true;
            }

            public function check(string $key, int $tokens = 1): LimiterResult
            {
                return LimiterResult::allowed();
            }

            public function consume(string $key, int $tokens = 1): LimiterResult
            {
                return LimiterResult::allowed();
            }

            public function getRemaining(string $key): float
            {
                return 0.0;
            }

            public function getWaitTime(string $key): float
            {
                return 0.0;
            }

            public function reset(string $key): void
            {
                $this->resets[] = $key;
            }

            public function getCapacity(): int
            {
                return 100;
            }

            public function getStore(): StoreInterface
            {
                return new MemoryStore();
            }

            public function refund(string $key, int $tokens = 1): void
            {
                $this->refunds[] = [$key, $tokens];
            }
        };

        // check 永远放行、allow 永远失败 → 必然走到阶段二回滚分支（模拟并发竞争）
        $denier = new class implements RateLimiterInterface {
            public function allow(string $key, int $tokens = 1): bool
            {
                return false;
            }

            public function check(string $key, int $tokens = 1): LimiterResult
            {
                return LimiterResult::allowed();
            }

            public function consume(string $key, int $tokens = 1): LimiterResult
            {
                return LimiterResult::denied(1.0);
            }

            public function getRemaining(string $key): float
            {
                return 0.0;
            }

            public function getWaitTime(string $key): float
            {
                return 1.0;
            }

            public function reset(string $key): void
            {
            }

            public function getCapacity(): int
            {
                return 10;
            }

            public function getStore(): StoreInterface
            {
                return new MemoryStore();
            }
        };

        $composite = new CompositeLimiter(['a' => $committed, 'b' => $denier]);

        $this->assertFalse($composite->allow('user:1', 3));
        $this->assertSame([['user:1', 3]], $committed->refunds);
        $this->assertSame([], $committed->resets);
    }

    public function testCounterRefundReturnsCurrentWindow(): void
    {
        $counter = new Counter($this->store, 10, 60, 'rf:');

        $this->assertTrue($counter->allow('k', 3));
        $this->assertEqualsWithDelta(7.0, $counter->getRemaining('k'), 0.001);

        $counter->refund('k', 2);
        $this->assertEqualsWithDelta(9.0, $counter->getRemaining('k'), 0.001);
    }

    public function testTokenBucketRefundRestoresTokens(): void
    {
        $bucket = new TokenBucket($this->store, 5, 0.0001, 3600, 'rf:');

        $this->assertTrue($bucket->allow('k', 5));
        $this->assertLessThan(0.1, $bucket->getRemaining('k'));

        $bucket->refund('k', 2);
        $this->assertEqualsWithDelta(2.0, $bucket->getRemaining('k'), 0.5);
    }

    public function testSlidingWindowCounterAtomicDenyAndRefund(): void
    {
        $limiter = new SlidingWindowCounter($this->store, 2, 60, 'rf:');

        $this->assertTrue($limiter->allow('k'));
        $this->assertTrue($limiter->allow('k'));
        // 第三次被拒后当前窗口计数必须回滚，不得残留 3
        $this->assertFalse($limiter->allow('k'));
        $this->assertLessThan(0.001, $limiter->getRemaining('k'));

        $limiter->refund('k', 1);
        $this->assertTrue($limiter->allow('k'));
    }

    public function testMemoryStoreOpportunisticPurge(): void
    {
        $reflection = new \ReflectionClass(MemoryStore::class);
        $threshold = (int) $reflection->getConstant('PURGE_THRESHOLD');

        for ($i = 0; $i < $threshold; $i++) {
            $this->store->set('key:' . $i, '1');
        }

        // 半数键置为已过期（直接改写内部过期表，避免 sleep）
        $expires = $reflection->getProperty('expires');
        $past = microtime(true) - 10.0;
        foreach (array_slice(array_keys($this->store->getData()), 0, (int) ($threshold / 2)) as $key) {
            $expires->setValue($this->store, [...$this->readExpires($expires), $key => $past]);
        }

        $this->assertSame($threshold, count($this->store->getData()));

        // 越过阈值后的下一次写入触发清理：过期键被回收
        $this->store->set('trigger', '1');
        $this->assertSame((int) ($threshold / 2) + 1, count($this->store->getData()));
        $this->assertArrayNotHasKey('key:0', $this->store->getData());
    }

    /**
     * @return array<string, float>
     */
    private function readExpires(\ReflectionProperty $prop): array
    {
        $prop->setAccessible(true);

        /** @var array<string, float> $value */
        $value = $prop->getValue($this->store);

        return $value;
    }
}
