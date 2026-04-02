<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

/**
 * PDO 存储实现
 *
 * 使用数据库进行分布式存储
 * 支持 MySQL、PostgreSQL、SQLite 等
 */
class PdoStore implements StoreInterface
{
    private readonly string $prefix;

    /**
     * 构造函数
     *
     * @param \PDO $pdo PDO 客户端实例
     * @param string $table 表名
     * @param string $prefix 键前缀
     * @throws \RuntimeException 当 PDO 扩展未安装时
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table = 'limiting',
        string $prefix = 'kode:limiting:'
    ) {
        if (!extension_loaded('pdo')) {
            throw new \RuntimeException(
                'PDO 扩展未安装，请先启用 PDO 扩展或参见 https://www.php.net/manual/zh/pdo.installation.php'
            );
        }
        $this->prefix = $prefix;
        $this->initTable();
    }

    /**
     * 创建 MySQL PDO 存储实例
     *
     * @param string $host 数据库地址
     * @param int $port 端口
     * @param string $database 数据库名
     * @param string $username 用户名
     * @param string $password 密码
     * @param string $table 表名
     * @return self
     * @throws \RuntimeException 当 PDO 扩展未安装时
     */
    public static function createMysql(
        string $host = '127.0.0.1',
        int $port = 3306,
        string $database = 'limiting',
        string $username = 'root',
        string $password = '',
        string $table = 'limiting'
    ): self {
        if (!extension_loaded('pdo_mysql')) {
            throw new \RuntimeException(
                'PDO MySQL 扩展未安装，请先执行：pecl install pdo_mysql 或参见 https://www.php.net/manual/zh/ref.pdo-mysql.php'
            );
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return new self($pdo, $table);
    }

    /**
     * 创建 SQLite PDO 存储实例
     *
     * @param string $path 数据库文件路径
     * @param string $table 表名
     * @return self
     * @throws \RuntimeException 当 PDO 扩展未安装时
     */
    public static function createSqlite(
        string $path = ':memory:',
        string $table = 'limiting'
    ): self {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException(
                'PDO SQLite 扩展未安装，请先启用 pdo_sqlite 扩展或参见 https://www.php.net/manual/zh/ref.pdo-sqlite.php'
            );
        }

        $dsn = "sqlite:{$path}";
        $pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        return new self($pdo, $table);
    }

    /**
     * 创建 PostgreSQL PDO 存储实例
     *
     * @param string $host 数据库地址
     * @param int $port 端口
     * @param string $database 数据库名
     * @param string $username 用户名
     * @param string $password 密码
     * @param string $table 表名
     * @return self
     * @throws \RuntimeException 当 PDO 扩展未安装时
     */
    public static function createPostgres(
        string $host = '127.0.0.1',
        int $port = 5432,
        string $database = 'limiting',
        string $username = 'postgres',
        string $password = '',
        string $table = 'limiting'
    ): self {
        if (!extension_loaded('pdo_pgsql')) {
            throw new \RuntimeException(
                'PDO PostgreSQL 扩展未安装，请先执行：pecl install pdo_pgsql 或参见 https://www.php.net/manual/zh/ref.pdo-pgsql.php'
            );
        }

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
        $pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return new self($pdo, $table);
    }

    /**
     * 初始化数据库表
     */
    private function initTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                key_name VARCHAR(255) PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            )";
        } elseif ($driver === 'pgsql') {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                key_name VARCHAR(255) PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                key_name VARCHAR(255) PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INT NOT NULL,
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }

        $this->pdo->exec($sql);
    }

    /**
     * 生成带前缀的键名
     */
    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * 获取值
     */
    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT value, expires_at FROM {$this->table} WHERE key_name = ?"
        );
        $stmt->execute([$this->key($key)]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        if ((int) $row['expires_at'] > 0 && (int) $row['expires_at'] < time()) {
            $this->delete($key);
            return null;
        }

        return $row['value'];
    }

    /**
     * 设置值
     */
    public function set(string $key, string $value, int $ttl = 0): void
    {
        $expiresAt = $ttl > 0 ? time() + $ttl : 0;

        $stmt = $this->pdo->prepare(
            "INSERT OR REPLACE INTO {$this->table} (key_name, value, expires_at) VALUES (?, ?, ?)"
        );
        $stmt->execute([$this->key($key), $value, $expiresAt]);
    }

    /**
     * 删除键
     */
    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE key_name = ?"
        );
        $stmt->execute([$this->key($key)]);
    }

    /**
     * 原子递增
     */
    public function incr(string $key, int $step = 1): int
    {
        $current = $this->get($key);
        $newValue = ((int) ($current ?? 0)) + $step;
        $this->set($key, (string) $newValue);
        return $newValue;
    }

    /**
     * 获取键的剩余 TTL
     */
    public function ttl(string $key): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT expires_at FROM {$this->table} WHERE key_name = ?"
        );
        $stmt->execute([$this->key($key)]);
        $row = $stmt->fetch();

        if (!$row) {
            return -1;
        }

        $expiresAt = (int) $row['expires_at'];
        if ($expiresAt <= 0) {
            return -1;
        }

        $remaining = $expiresAt - time();
        return $remaining > 0 ? $remaining : -2;
    }

    /**
     * 获取 PDO 客户端实例
     */
    public function getClient(): \PDO
    {
        return $this->pdo;
    }

    /**
     * 健康检查
     */
    public function isHealthy(): bool
    {
        try {
            $this->pdo->query("SELECT 1");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
