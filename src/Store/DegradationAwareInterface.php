<?php

declare(strict_types=1);

namespace Kode\Limiting\Store;

/**
 * 可感知降级状态的存储驱动标记接口
 *
 * 当底层存储（Redis / PDO 等）不可用时，{@see ResilientStore} 会进入降级态。
 * 限流器（Limiter / Middleware）借此把"降级放行"标记到 LimiterResult 上，
 * 让调用方知情并决定是否记录告警。
 */
interface DegradationAwareInterface
{
    /**
     * 当前是否处于降级态（即最近一次操作因存储故障走了兜底策略）
     */
    public function isDegraded(): bool;

    /**
     * 触发降级的原因（通常是底层异常信息），未降级时为 null
     */
    public function getDegradationReason(): ?string;

    /**
     * 清除降级标记（取用一次结果后通常由限流器调用）
     */
    public function clearDegradation(): void;
}
