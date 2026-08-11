<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\DTO\LimiterResult;
use PHPUnit\Framework\TestCase;

/**
 * LimiterResult 降级字段序列化测试
 */
final class LimiterResultDegradedTest extends TestCase
{
    public function testDegradedFlagInArrayAndJson(): void
    {
        $result = LimiterResult::allowed(5, 10)->withDegraded(true, 'redis down');

        self::assertTrue($result->degraded);
        self::assertSame('redis down', $result->degradedReason);

        $array = $result->toArray();
        self::assertTrue($array['degraded']);
        self::assertSame('redis down', $array['degraded_reason']);

        self::assertSame('1', $result->toHeaders()['X-RateLimit-Degraded']);

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"degraded":true', $json);
    }

    public function testFactoryAcceptsDegraded(): void
    {
        $denied = LimiterResult::denied(2.0, 10, 0.0, true, 'store error');

        self::assertTrue($denied->degraded);
        self::assertTrue($denied->isDenied());
    }

    public function testNotDegradedByDefault(): void
    {
        $ok = LimiterResult::allowed(9, 10);

        self::assertFalse($ok->degraded);
        self::assertArrayNotHasKey('X-RateLimit-Degraded', $ok->toHeaders());
    }
}
