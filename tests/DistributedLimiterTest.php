<?php

declare(strict_types=1);

namespace Kode\Limiting\Tests;

use Kode\Limiting\Distributed\DistributedLimiter;
use Kode\Limiting\Exception\UnsupportedDriverException;
use PHPUnit\Framework\TestCase;

/**
 * 分布式令牌桶限流器测试。
 *
 * 真实限流需要 Redis 服务，这里只做无依赖的部分：
 *  - 校验 Lua 脚本结构（v1.x 的关键修复点：KEYS/ARGV 必须正确分离）；
 *  - 在没有 redis 扩展时确认工厂会因依赖缺失而抛异常。
 */
class DistributedLimiterTest extends TestCase
{
    public function testLuaScriptIsWellFormed(): void
    {
        $lua = (new \ReflectionClass(DistributedLimiter::class))->getConstant('LUA_ALLOW');

        $this->assertIsString($lua);
        $this->assertStringContainsString('KEYS[1]', $lua);
        $this->assertStringContainsString('ARGV[1]', $lua);
        $this->assertStringContainsString('cjson', $lua);
        // v1.x 的 eval() 调用把 ARGV 全部丢弃，这里必须正确使用
        $this->assertStringContainsString('tonumber(ARGV[2])', $lua);
    }

    public function testCreateRequiresRedis(): void
    {
        if (!extension_loaded('redis')) {
            $this->expectException(UnsupportedDriverException::class);
            DistributedLimiter::create();

            return;
        }

        $this->markTestSkipped('ext-redis 已安装，跳过无依赖分支的断言');
    }
}
