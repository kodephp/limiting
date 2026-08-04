<?php

declare(strict_types=1);

namespace Kode\Limiting\Exception;

/**
 * 限流组件异常标记接口
 *
 * 所有本组件抛出的异常都实现该接口，
 * 调用方可通过 catch (LimiterException $e) 一次性捕获。
 */
interface LimiterException extends \Throwable
{
}
