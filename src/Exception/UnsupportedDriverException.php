<?php

declare(strict_types=1);

namespace Kode\Limiting\Exception;

/**
 * 驱动（PHP 扩展）不可用异常
 */
final class UnsupportedDriverException extends \RuntimeException implements LimiterException
{
    public static function extensionMissing(string $extension, string $installHint, string $docUrl): self
    {
        return new self(sprintf(
            '%s 扩展未安装或未启用。安装方式：%s；文档：%s',
            $extension,
            $installHint,
            $docUrl
        ));
    }
}
