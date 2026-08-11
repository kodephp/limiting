<?php

declare(strict_types=1);

namespace Kode\Limiting\Concurrency;

use Kode\Limiting\Exception\InvalidConfigurationException;
use Kode\Limiting\Store\MemoryStore;
use Kode\Limiting\Store\StoreInterface;

/**
 * 计数信号量（v2.0 新增，并发限流器的公共内核）
 *
 * 背景：v1.x 的 TaskLimiter / ProcessLimiter / FiberLimiter 都是给每个
 * 业务 ID 单独建一个令牌桶（key 里带着 taskId），意味着 1000 个不同的
 * taskId 就有 1000 个互不相干的桶 —— maxConcurrency 从来没有真正生效过。
 *
 * 现改为标准信号量语义：所有持有者共享固定数量的槽位，
 * 槽位带 TTL，进程异常退出时自动回收，不会永久泄漏。
 */
class Semaphore
{
    private readonly string $stateKey;

    public function __construct(
        private readonly int $maxSlots,
        private readonly StoreInterface $store = new MemoryStore(),
        string $prefix = 'sem:',
        private readonly int $slotTtl = 3600
    ) {
        if ($maxSlots <= 0) {
            throw InvalidConfigurationException::positiveRequired('maxSlots', $maxSlots);
        }

        if ($slotTtl <= 0) {
            throw InvalidConfigurationException::positiveRequired('slotTtl', $slotTtl);
        }

        $this->stateKey = $prefix . 'holders';
    }

    /**
     * 尝试占用一个槽位（非阻塞）
     *
     * 同一个 holderId 重复获取视为幂等成功，仅续期不额外占位。
     */
    public function tryAcquire(string $holderId, ?int $ttl = null): bool
    {
        if (trim($holderId) === '') {
            throw InvalidConfigurationException::emptyKey();
        }

        $now = time();
        $holders = $this->activeHolders($now);
        $expireAt = $now + ($ttl ?? $this->slotTtl);

        if (isset($holders[$holderId])) {
            $holders[$holderId] = $expireAt;
            $this->persist($holders);

            return true;
        }

        if (count($holders) >= $this->maxSlots) {
            $this->persist($holders);

            return false;
        }

        $holders[$holderId] = $expireAt;
        $this->persist($holders);

        return true;
    }

    /**
     * 阻塞等待槽位
     *
     * @param float $timeout       最长等待秒数
     * @param int   $intervalUsec  轮询间隔（微秒）
     */
    public function acquire(
        string $holderId,
        float $timeout = 30.0,
        ?int $ttl = null,
        int $intervalUsec = 10_000
    ): bool {
        $start = microtime(true);

        while (!$this->tryAcquire($holderId, $ttl)) {
            if (microtime(true) - $start >= $timeout) {
                return false;
            }

            usleep(max(1_000, $intervalUsec));
        }

        return true;
    }

    /**
     * 释放槽位
     */
    public function release(string $holderId): void
    {
        $holders = $this->activeHolders(time());
        unset($holders[$holderId]);
        $this->persist($holders);
    }

    /**
     * 在槽位保护下执行回调，无论成功失败都会释放
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(string $holderId, callable $callback, float $timeout = 30.0, ?int $ttl = null): mixed
    {
        if (!$this->acquire($holderId, $timeout, $ttl)) {
            throw new \RuntimeException("获取并发槽位超时：{$holderId}");
        }

        try {
            return $callback();
        } finally {
            $this->release($holderId);
        }
    }

    public function isHeldBy(string $holderId): bool
    {
        return isset($this->activeHolders(time())[$holderId]);
    }

    public function getActiveCount(): int
    {
        return count($this->activeHolders(time()));
    }

    public function getAvailable(): int
    {
        return max(0, $this->maxSlots - $this->getActiveCount());
    }

    public function getMaxSlots(): int
    {
        return $this->maxSlots;
    }

    /**
     * @return list<string>
     */
    public function getHolders(): array
    {
        return array_keys($this->activeHolders(time()));
    }

    /**
     * 释放全部槽位
     */
    public function reset(): void
    {
        $this->store->delete($this->stateKey);
    }

    /**
     * 读取并顺带清理已过期的持有者
     *
     * @return array<string, int>
     */
    private function activeHolders(int $now): array
    {
        $raw = $this->store->get($this->stateKey);

        if ($raw === null || !json_validate($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $active = [];

        foreach ($decoded as $holderId => $expireAt) {
            if ((int) $expireAt > $now) {
                $active[(string) $holderId] = (int) $expireAt;
            }
        }

        return $active;
    }

    /**
     * @param array<string, int> $holders
     */
    private function persist(array $holders): void
    {
        if ($holders === []) {
            $this->store->delete($this->stateKey);

            return;
        }

        $this->store->set(
            $this->stateKey,
            json_encode($holders, JSON_THROW_ON_ERROR),
            max($this->slotTtl, max($holders) - time() + 1)
        );
    }
}
