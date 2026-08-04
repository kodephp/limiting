<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Algorithm\CompositeLimiter;
use Kode\Limiting\Algorithm\Counter;
use Kode\Limiting\Algorithm\TokenBucket;
use Kode\Limiting\Store\MemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * 组合限流器：多条规则串联，全部通过才放行（两阶段：预检 + 提交回滚）。
 */
class CompositeLimiterTest extends TestCase
{
    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
    }

    public function testAllRulesMustPass(): void
    {
        $composite = new CompositeLimiter([
            'per_second' => new TokenBucket($this->store, 2, 10.0, 3600, 'c:'),
            'per_minute' => new Counter($this->store, 100, 60, 'c:'),
        ]);

        $this->assertTrue($composite->allow('user:1'));
        $this->assertTrue($composite->allow('user:1'));
        // 令牌桶容量 2 耗尽，第 3 次整体拒绝
        $this->assertFalse($composite->allow('user:1'));
    }

    public function testFirstDeniedRuleDetection(): void
    {
        $composite = new CompositeLimiter([
            'per_second' => new TokenBucket($this->store, 1, 10.0, 3600, 'c2:'),
            'per_minute' => new Counter($this->store, 100, 60, 'c2:'),
        ]);

        $composite->allow('user:2');
        $composite->allow('user:2'); // 令牌桶第 2 次被拒

        $this->assertEquals('per_second', $composite->firstDeniedRule('user:2'));
    }

    public function testRollbackOnPartialCommit(): void
    {
        // 计数器是瓶颈：被拒时令牌桶不应被错误扣减
        $composite = new CompositeLimiter([
            'tb' => new TokenBucket($this->store, 100, 100.0, 3600, 'c3:'),
            'counter' => new Counter($this->store, 2, 60, 'c3:'),
        ]);

        $this->assertTrue($composite->allow('user:3'));
        $this->assertTrue($composite->allow('user:3'));
        $this->assertFalse($composite->allow('user:3')); // 计数器满

        $tb = $composite->getLimiters()['tb'];
        $this->assertGreaterThan(0, $tb->getRemaining('user:3'));
    }

    public function testRejectsEmptyRules(): void
    {
        $this->expectException(\Kode\Limiting\Exception\InvalidConfigurationException::class);
        new CompositeLimiter([]);
    }

    public function testInspectReturnsPerRuleResults(): void
    {
        $composite = new CompositeLimiter([
            'a' => new TokenBucket($this->store, 10, 10.0, 3600, 'c4:'),
            'b' => new Counter($this->store, 10, 60, 'c4:'),
        ]);

        $results = $composite->inspect('user:4');
        $this->assertArrayHasKey('a', $results);
        $this->assertArrayHasKey('b', $results);
    }
}
