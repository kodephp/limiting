<?php

declare(strict_types=1);

namespace Kode\Limiting\EventHandler;

use Kode\Limiting\DTO\LimiterResult;

/**
 * 限流器事件回调契约
 *
 * 用于把限流决策、降级与异常上报到外部（日志、指标、告警等），
 * 而不耦合具体框架。默认实现为 {@see NullLimiterEventHandler}（空操作）。
 */
interface LimiterEventHandler
{
    /**
     * 每次 allow / check / consume 产出结果后触发
     *
     * @param string        $key    业务键
     * @param LimiterResult $result 本次限流结果（可能标记降级）
     */
    public function onResult(string $key, LimiterResult $result): void;

    /**
     * 当限流操作抛出异常时触发（如 RETHROW 策略下的存储故障）
     *
     * @param string    $key   业务键
     * @param \Throwable $error 原始异常
     */
    public function onError(string $key, \Throwable $error): void;
}
