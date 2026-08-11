<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\DTO\LimiterResult;
use Kode\Limiting\Enum\FailurePolicy;
use Kode\Limiting\EventHandler\LimiterEventHandler;
use Kode\Limiting\Exception\StoreException;
use Kode\Limiting\Limiter;
use Kode\Limiting\Middleware\LimiterMiddleware;
use Kode\Limiting\Store\ResilientStore;
use Kode\Limiting\Tests\Fixture\FailingStore;
use PHPUnit\Framework\TestCase;

/**
 * 容灾降级 + 事件回调集成测试（Limiter / Middleware 层面）
 */
final class RobustnessTest extends TestCase
{
    private function failingStore(): ResilientStore
    {
        return new ResilientStore(new FailingStore(), FailurePolicy::ALLOW);
    }

    public function testFailOpenConsumeMarksDegraded(): void
    {
        $limiter = Limiter::tokenBucket(100, 10.0, $this->failingStore());

        self::assertTrue($limiter->allow('user:1'));

        $result = $limiter->consume('user:1');
        self::assertTrue($result->isAllowed());
        self::assertTrue($result->degraded);
        self::assertNotNull($result->degradedReason);

        // 结果头应标注降级
        self::assertSame('1', $result->toHeaders()['X-RateLimit-Degraded']);
    }

    public function testHealthyStoreNotDegraded(): void
    {
        $limiter = Limiter::tokenBucket(100, 10.0, new \Kode\Limiting\Store\MemoryStore());

        $result = $limiter->consume('user:1');
        self::assertFalse($result->degraded);
        self::assertArrayNotHasKey('X-RateLimit-Degraded', $result->toHeaders());
    }

    public function testRethrowPolicyPropagates(): void
    {
        $store = new ResilientStore(new FailingStore(), FailurePolicy::RETHROW);
        $limiter = Limiter::tokenBucket(100, 10.0, $store);

        $this->expectException(StoreException::class);
        $limiter->consume('user:1');
    }

    public function testEventHandlerReceivesResultAndError(): void
    {
        $handler = new class implements LimiterEventHandler {
            /** @var list<LimiterResult> */
            public array $results = [];
            public ?\Throwable $error = null;

            public function onResult(string $key, LimiterResult $result): void
            {
                $this->results[] = $result;
            }

            public function onError(string $key, \Throwable $error): void
            {
                $this->error = $error;
            }
        };

        // fail-open：应收到 onResult，且 degraded=true
        $limiter = Limiter::tokenBucket(100, 10.0, $this->failingStore())
            ->withEventHandler($handler);

        $limiter->consume('user:1');
        self::assertCount(1, $handler->results);
        self::assertTrue($handler->results[0]->degraded);
        self::assertNull($handler->error);

        // rethrow：应收到 onError
        $handler2 = new class implements LimiterEventHandler {
            public ?\Throwable $error = null;
            public function onResult(string $key, LimiterResult $result): void {}
            public function onError(string $key, \Throwable $error): void
            {
                $this->error = $error;
            }
        };

        $rethrow = new ResilientStore(new FailingStore(), FailurePolicy::RETHROW);
        $limiter2 = Limiter::tokenBucket(100, 10.0, $rethrow)->withEventHandler($handler2);

        try {
            $limiter2->consume('user:1');
        } catch (StoreException) {
        }

        self::assertInstanceOf(StoreException::class, $handler2->error);
    }

    public function testMiddlewareSurfacesDegradation(): void
    {
        $middleware = Limiter::tokenBucket(100, 10.0, $this->failingStore())->toMiddleware('api');

        self::assertInstanceOf(LimiterMiddleware::class, $middleware);

        $result = $middleware->consume('user:1');
        self::assertTrue($result->degraded);
        self::assertSame('1', $result->toHeaders()['X-RateLimit-Degraded']);
    }
}
