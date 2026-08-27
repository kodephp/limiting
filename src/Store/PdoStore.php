<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

use Kode\Limiting\Exception\StoreException;
use Kode\Limiting\Exception\UnsupportedDriverException;

/**
 * PDO 数据库存储实现（分布式）
 *
 * 支持 MySQL / MariaDB、PostgreSQL、SQLite。
 *
 * v2.0 修复：
 *  - set() 此前固定使用 SQLite 专有的 "INSERT OR REPLACE"，在 MySQL /
 *    PostgreSQL 下必然抛语法错误；现按驱动生成对应 UPSERT 语句。
 *  - incr() 改为事务 + 行锁，避免并发下丢失更新。
 */
class PdoStore implements StoreInterface
{
    private readonly string $prefix;
    private readonly string $driver;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table = 'limiting',
        string $prefix = 'kode:limiting:'
    ) {
        if (!extension_loaded('pdo')) {
            throw UnsupportedDriverException::extensionMissing(
                'PDO',
                '在 php.ini 中启用 extension=pdo',
                'https://www.php.net/manual/zh/pdo.installation.php'
            );
        }

        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        /** @var string $driverName */
        $driverName = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driverName) ? $driverName : (string) $driverName;
        $this->prefix = $prefix;

        $this->initTable();
    }

    public static function createMysql(
        string $host = '127.0.0.1',
        int $port = 3306,
        string $database = 'limiting',
        string $username = 'root',
        string $password = '',
        string $table = 'limiting'
    ): self {
        self::assertDriver('pdo_mysql', 'PDO MySQL', 'https://www.php.net/manual/zh/ref.pdo-mysql.php');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        return new self(self::connect($dsn, $username, $password), $table);
    }

    public static function createSqlite(string $path = ':memory:', string $table = 'limiting'): self
    {
        self::assertDriver('pdo_sqlite', 'PDO SQLite', 'https://www.php.net/manual/zh/ref.pdo-sqlite.php');

        return new self(self::connect("sqlite:{$path}", null, null), $table);
    }

    public static function createPostgres(
        string $host = '127.0.0.1',
        int $port = 5432,
        string $database = 'limiting',
        string $username = 'postgres',
        string $password = '',
        string $table = 'limiting'
    ): self {
        self::assertDriver('pdo_pgsql', 'PDO PostgreSQL', 'https://www.php.net/manual/zh/ref.pdo-pgsql.php');

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        return new self(self::connect($dsn, $username, $password), $table);
    }

    private static function assertDriver(string $extension, string $label, string $docUrl): void
    {
        if (!extension_loaded($extension)) {
            throw UnsupportedDriverException::extensionMissing($label, "启用 extension={$extension}", $docUrl);
        }
    }

    private static function connect(string $dsn, ?string $username, ?string $password): \PDO
    {
        try {
            return new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            throw StoreException::connectionFailed('PDO', $e->getMessage());
        }
    }

    /**
     * 初始化数据表
     */
    private function initTable(): void
    {
        $sql = match ($this->driver) {
            'mysql' => "CREATE TABLE IF NOT EXISTS {$this->table} (
                key_name VARCHAR(191) NOT NULL PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at BIGINT NOT NULL DEFAULT 0,
                INDEX idx_{$this->table}_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            default => "CREATE TABLE IF NOT EXISTS {$this->table} (
                key_name VARCHAR(191) NOT NULL PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at BIGINT NOT NULL DEFAULT 0
            )",
        };

        $this->pdo->exec($sql);

        if ($this->driver !== 'mysql') {
            $this->pdo->exec(
                "CREATE INDEX IF NOT EXISTS idx_{$this->table}_expires ON {$this->table} (expires_at)"
            );
        }
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * 按驱动生成 UPSERT 语句
     */
    private function upsertSql(): string
    {
        return match ($this->driver) {
            'mysql' => "INSERT INTO {$this->table} (key_name, value, expires_at) VALUES (:k, :v, :e)
                        ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at)",
            default => "INSERT INTO {$this->table} (key_name, value, expires_at) VALUES (:k, :v, :e)
                        ON CONFLICT (key_name) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at",
        };
    }

    #[\Override]
    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT value, expires_at FROM {$this->table} WHERE key_name = :k"
        );
        $stmt->execute(['k' => $this->key($key)]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        /** @var int|string $expiresAtRaw */
        $expiresAtRaw = $row['expires_at'] ?? 0;
        $expiresAt = is_int($expiresAtRaw) ? $expiresAtRaw : (int) $expiresAtRaw;

        if ($expiresAt > 0 && $expiresAt <= time()) {
            $this->delete($key);
            return null;
        }

        /** @var string|int $valueRaw */
        $valueRaw = $row['value'] ?? '';
        return is_string($valueRaw) ? $valueRaw : (string) $valueRaw;
    }

    #[\Override]
    public function set(string $key, string $value, int $ttl = 0): void
    {
        $stmt = $this->pdo->prepare($this->upsertSql());
        $stmt->execute([
            'k' => $this->key($key),
            'v' => $value,
            'e' => $ttl > 0 ? time() + $ttl : 0,
        ]);
    }

    #[\Override]
    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE key_name = :k");
        $stmt->execute(['k' => $this->key($key)]);
    }

    #[\Override]
    public function incr(string $key, int $step = 1, int $ttl = 0): int
    {
        $ownTransaction = !$this->pdo->inTransaction();

        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $sql = "SELECT value, expires_at FROM {$this->table} WHERE key_name = :k";

            if ($this->driver === 'mysql' || $this->driver === 'pgsql') {
                $sql .= ' FOR UPDATE';
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['k' => $this->key($key)]);
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            /** @var int|string $expiresAtRaw */
            $expiresAtRaw = $row !== false ? ($row['expires_at'] ?? 0) : 0;
            $expiresAtInt = is_int($expiresAtRaw) ? $expiresAtRaw : (int) $expiresAtRaw;

            $expired = $row !== false
                && $expiresAtInt > 0
                && $expiresAtInt <= time();

            /** @var int|string $valueRaw */
            $valueRaw = $row !== false ? ($row['value'] ?? 0) : 0;
            $current = ($row === false || $expired) ? 0 : (is_int($valueRaw) ? $valueRaw : (int) $valueRaw);
            $newValue = $current + $step;

            $expiresAt = match (true) {
                $row !== false && !$expired => $expiresAtInt,
                $ttl > 0 => time() + $ttl,
                default => 0,
            };

            $write = $this->pdo->prepare($this->upsertSql());
            $write->execute([
                'k' => $this->key($key),
                'v' => (string) $newValue,
                'e' => $expiresAt,
            ]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return $newValue;
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw StoreException::operationFailed('PDO', 'incr', $e);
        }
    }

    #[\Override]
    public function decr(string $key, int $step = 1): int
    {
        return $this->incr($key, -$step);
    }

    #[\Override]
    public function ttl(string $key): int
    {
        $stmt = $this->pdo->prepare("SELECT expires_at FROM {$this->table} WHERE key_name = :k");
        $stmt->execute(['k' => $this->key($key)]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return -2;
        }

        /** @var int|string $expiresAtRaw */
        $expiresAtRaw = $row['expires_at'] ?? 0;
        $expiresAt = is_int($expiresAtRaw) ? $expiresAtRaw : (int) $expiresAtRaw;

        if ($expiresAt <= 0) {
            return -1;
        }

        $remaining = $expiresAt - time();

        return $remaining > 0 ? $remaining : -2;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 清理已过期记录，返回删除行数
     */
    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE expires_at > 0 AND expires_at <= :now"
        );
        $stmt->execute(['now' => time()]);

        return $stmt->rowCount();
    }

    public function getClient(): \PDO
    {
        return $this->pdo;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function isHealthy(): bool
    {
        try {
            $this->pdo->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
