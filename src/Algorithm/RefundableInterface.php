<?php

declare(strict_types=1);

namespace Kode\Limiting\Algorithm;

/**
 * 可回退（补偿）限流器契约
 *
 * 组合限流器在"提交阶段"某条规则失败时，需要把已提交规则本次扣掉的额度
 * 精确回退回去（decr / 回补令牌），而不是 reset 掉整段窗口计数——
 * 后者会让攻击者通过间歇触发慢规则清空快规则的累计用量，绕过突发限制。
 *
 * 仅计数类算法（Counter / SlidingWindow / SlidingWindowCounter / TokenBucket /
 * LeakyBucket）实现本接口；未实现者由 CompositeLimiter 回退到 reset 旧行为。
 */
interface RefundableInterface
{
    /**
     * 归还此前已扣减的额度（用于组合限流回滚），不会低于 0
     */
    public function refund(string $key, #[\SensitiveParameter] int $tokens = 1): void;
}
