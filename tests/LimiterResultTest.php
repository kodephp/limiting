<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\DTO\LimiterResult;
use PHPUnit\Framework\TestCase;

/**
 * LimiterResult DTO 测试，重点覆盖 v2.0 修复的 readonly 二次赋值致命错误。
 */
class LimiterResultTest extends TestCase
{
    public function testAllowedFactory(): void
    {
        $r = LimiterResult::allowed(9.0, 10, 1.0);

        $this->assertTrue($r->isAllowed());
        $this->assertFalse($r->isDenied());
        $this->assertEquals(9.0, $r->remaining);
        $this->assertEquals(10, $r->limit);
        $this->assertEquals(0.0, $r->retryAfter);
    }

    public function testDeniedFactory(): void
    {
        $r = LimiterResult::denied(5.0, 10, 2.0);

        $this->assertFalse($r->isAllowed());
        $this->assertTrue($r->isDenied());
        $this->assertEquals(5.0, $r->retryAfter);
    }

    public function testReadonlyTimestampSetOnce(): void
    {
        // v1.x 实例化即抛 "Cannot modify readonly property" 已修复
        $r = new LimiterResult(true, 5.0, 0.0, 10);
        $this->assertIsInt($r->timestamp);
        $this->assertGreaterThan(0, $r->timestamp);
    }

    public function testToHeadersForAllowed(): void
    {
        $r = LimiterResult::allowed(5.0, 10, 60.0);
        $h = $r->toHeaders();

        $this->assertEquals('10', $h['X-RateLimit-Limit']);
        $this->assertEquals('5', $h['X-RateLimit-Remaining']);
        $this->assertArrayNotHasKey('Retry-After', $h);
    }

    public function testToHeadersForDenied(): void
    {
        $r = LimiterResult::denied(3.0, 10, 0.0);
        $h = $r->toHeaders();

        $this->assertArrayHasKey('Retry-After', $h);
        $this->assertEquals('10', $h['X-RateLimit-Limit']);
    }

    public function testJsonSerialize(): void
    {
        $r = LimiterResult::allowed(5.0, 10);
        $decoded = json_decode(json_encode($r), true);

        $this->assertEquals(10, $decoded['limit']);
        $this->assertArrayHasKey('timestamp', $decoded);
    }

    public function testToArrayKeys(): void
    {
        $r = new LimiterResult(true, 5.0, 0.0, 10, 60.0);
        $arr = $r->toArray();

        foreach (['allowed', 'remaining', 'retry_after', 'limit', 'reset_after', 'timestamp'] as $k) {
            $this->assertArrayHasKey($k, $arr);
        }
    }
}
