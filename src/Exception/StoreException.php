<?php

declare(strict_types=1);

namespace Kode\Limiting\Exception;

/**
 * 存储层异常
 *
 * 底层驱动（Redis / Memcached / PDO / APCu）读写失败时抛出。
 */
final class StoreException extends \RuntimeException implements LimiterException
{
    public static function operationFailed(string $driver, string $operation, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('%s 存储执行 %s 操作失败：%s', $driver, $operation, $previous?->getMessage() ?? '未知错误'),
            0,
            $previous
        );
    }

    public static function connectionFailed(string $driver, string $reason): self
    {
        return new self(sprintf('%s 连接失败：%s', $driver, $reason));
    }
}
