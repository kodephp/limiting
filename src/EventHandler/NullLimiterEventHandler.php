<?php

declare(strict_types=1);

namespace Kode\Limiting\EventHandler;

use Kode\Limiting\DTO\LimiterResult;

/**
 * 空实现：不做什么，用作默认事件处理器
 */
final class NullLimiterEventHandler implements LimiterEventHandler
{
    #[\Override]
    public function onResult(string $key, LimiterResult $result): void {}

    #[\Override]
    public function onError(string $key, \Throwable $error): void {}
}
