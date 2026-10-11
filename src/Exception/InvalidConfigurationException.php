<?php

declare(strict_types=1);

namespace Kode\Limiting\Exception;

/**
 * 配置非法异常
 *
 * 例如容量 <= 0、速率 <= 0、窗口大小 <= 0 等。
 */
final class InvalidConfigurationException extends \InvalidArgumentException implements LimiterException
{
    public static function positiveRequired(string $field, int|float $given): self
    {
        return new self(sprintf('配置项 %s 必须大于 0，当前值：%s', $field, (string) $given));
    }

    public static function emptyKey(): self
    {
        return new self('限流键（key）不能为空字符串');
    }

    public static function negativeTokens(#[\SensitiveParameter] int $tokens): self
    {
        return new self(sprintf('请求令牌数不能为负数，当前值：%d', $tokens));
    }
}
