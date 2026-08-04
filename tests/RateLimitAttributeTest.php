<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Attribute\RateLimit;
use Kode\Limiting\Enum\LimiterType;
use PHPUnit\Framework\TestCase;

/**
 * 声明式 #[RateLimit] 注解测试。
 */
class RateLimitAttributeTest extends TestCase
{
    public function testDefaults(): void
    {
        $a = new RateLimit();
        $this->assertEquals(60, $a->capacity);
        $this->assertEquals(LimiterType::TOKEN_BUCKET, $a->type);
    }

    public function testResolveKeyTemplate(): void
    {
        $a = new RateLimit(capacity: 100, rate: 1.0, key: 'api:{user_id}:{scope}');
        $key = $a->resolveKey(['user_id' => 42, 'scope' => 'read']);

        $this->assertEquals('api:42:read', $key);
    }

    public function testResolveKeyFallbackWhenNoTemplate(): void
    {
        $a = new RateLimit();
        $this->assertEquals('global', $a->resolveKey([], 'global'));
    }

    public function testToConfig(): void
    {
        $a = new RateLimit(capacity: 50, rate: 2.0, key: 'x');
        $cfg = $a->toConfig('limiter:');

        $this->assertEquals(50, $cfg->capacity);
        $this->assertEquals(2.0, $cfg->refillRate);
    }

    public function testRejectsInvalidCapacity(): void
    {
        $this->expectException(\Kode\Limiting\Exception\InvalidConfigurationException::class);
        new RateLimit(capacity: 0);
    }

    public function testReflectionTarget(): void
    {
        $ref = new \ReflectionClass(RateLimit::class);
        $this->assertNotEmpty($ref->getAttributes(\Attribute::class));
    }
}
