<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

use Kode\Limiting\Exception\UnsupportedDriverException;

/**
 * APCu 共享内存存储（v2.0 新增）
 *
 * 单机多进程（php-fpm）共享限流状态的最轻量方案，
 * 无需外部中间件，且 apcu_inc 天然原子。
 */
class ApcuStore implements StoreInterface
{
    private readonly string $prefix;

    public function __construct(string $prefix = 'kode:limiting:')
    {
        self::assertAvailable();
        $this->prefix = $prefix;
    }

    public static function create(string $prefix = 'kode:limiting:'): self
    {
        return new self($prefix);
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled();
    }

    private static function assertAvailable(): void
    {
        if (!extension_loaded('apcu')) {
            throw UnsupportedDriverException::extensionMissing(
                'APCu',
                'pecl install apcu',
                'https://www.php.net/manual/zh/book.apcu.php'
            );
        }

        if (function_exists('apcu_enabled') && !apcu_enabled()) {
            throw UnsupportedDriverException::extensionMissing(
                'APCu',
                '在 php.ini 中设置 apc.enabled=1（CLI 下另需 apc.enable_cli=1）',
                'https://www.php.net/manual/zh/apcu.configuration.php'
            );
        }
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    private function expireKey(string $key): string
    {
        return $this->prefix . '__exp:' . $key;
    }

    #[\Override]
    public function get(string $key): ?string
    {
        $success = false;
        $value = apcu_fetch($this->key($key), $success);

        if (!$success) {
            return null;
        }

        /** @var string $value */
        return is_string($value) ? $value : (string) $value;
    }

    #[\Override]
    public function set(string $key, string $value, int $ttl = 0): void
    {
        apcu_store($this->key($key), $value, max(0, $ttl));
        apcu_store($this->expireKey($key), $ttl > 0 ? time() + $ttl : 0, max(0, $ttl));
    }

    #[\Override]
    public function delete(string $key): void
    {
        apcu_delete($this->key($key));
        apcu_delete($this->expireKey($key));
    }

    #[\Override]
    public function incr(string $key, int $step = 1, int $ttl = 0): int
    {
        $success = false;
        $value = apcu_inc($this->key($key), $step, $success, max(0, $ttl));

        if (!$success) {
            throw \Kode\Limiting\Exception\StoreException::operationFailed('APCu', 'incr');
        }

        if ($ttl > 0 && !apcu_exists($this->expireKey($key))) {
            apcu_store($this->expireKey($key), time() + $ttl, $ttl);
        }

        return (int) $value;
    }

    #[\Override]
    public function decr(string $key, int $step = 1): int
    {
        $success = false;
        $value = apcu_dec($this->key($key), $step, $success);

        return $success ? (int) $value : 0;
    }

    #[\Override]
    public function ttl(string $key): int
    {
        if (!apcu_exists($this->key($key))) {
            return -2;
        }

        $success = false;
        $expiresAt = apcu_fetch($this->expireKey($key), $success);

        if (!$success) {
            return -1;
        }

        /** @var int $expiresAt */
        $expiresAtInt = is_int($expiresAt) ? $expiresAt : (int) $expiresAt;

        if ($expiresAtInt <= 0) {
            return -1;
        }

        $remaining = $expiresAtInt - time();

        return $remaining > 0 ? $remaining : -2;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return (bool) apcu_exists($this->key($key));
    }

    public function clear(): bool
    {
        return apcu_clear_cache();
    }

    public function isHealthy(): bool
    {
        return self::isAvailable();
    }
}
