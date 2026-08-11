<?php

declare(strict_types=1);

namespace Kode\Limiting\Enum;

/**
 * 存储故障时的兜底策略
 *
 * - ALLOW  ：fail-open（默认），存储不可用时放行，并标记结果为降级态，
 *            适用于"限流只是锦上添花、不能因限流器故障阻断业务"的场景；
 * - RETHROW：将异常原样抛出，适用于"必须严格限流"的场景。
 */
enum FailurePolicy: string
{
    case ALLOW = 'allow';
    case RETHROW = 'rethrow';

    public function label(): string
    {
        return match ($this) {
            self::ALLOW => '故障放行（fail-open）',
            self::RETHROW => '故障抛出异常',
        };
    }

    public function isFailOpen(): bool
    {
        return $this === self::ALLOW;
    }
}
