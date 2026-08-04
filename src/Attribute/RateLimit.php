<?php

declare(strict_types=1);

namespace Kode\Limiting\Attribute;

use Kode\Limiting\DTO\LimiterConfig;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Exception\InvalidConfigurationException;

/**
 * 声明式限流注解（v2.0 新增）
 *
 * 标注在控制器类或方法上，由框架侧的中间件反射读取后应用限流规则，
 * 让"限流策略"与"业务代码"解耦。
 *
 * @example
 * ```php
 * #[RateLimit(capacity: 60, rate: 1.0, key: 'api:{user_id}')]
 * public function index(): Response { ... }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class RateLimit
{
    /**
     * @param int         $capacity 额度上限
     * @param float       $rate     令牌补充速率（个/秒）或窗口秒数，取决于 $type
     * @param string|null $key      限流键模板，支持 {占位符}，null 表示由中间件推导
     * @param LimiterType $type     限流算法
     * @param int         $tokens   单次请求消耗额度
     * @param string      $group    规则分组名，用于同一入口叠加多条规则
     */
    public function __construct(
        public int $capacity = 60,
        public float $rate = 1.0,
        public ?string $key = null,
        public LimiterType $type = LimiterType::TOKEN_BUCKET,
        public int $tokens = 1,
        public string $group = 'default'
    ) {
        if ($capacity <= 0) {
            throw InvalidConfigurationException::positiveRequired('capacity', $capacity);
        }

        if ($rate <= 0) {
            throw InvalidConfigurationException::positiveRequired('rate', $rate);
        }

        if ($tokens < 0) {
            throw InvalidConfigurationException::negativeTokens($tokens);
        }
    }

    public function toConfig(string $prefix = 'limiter:'): LimiterConfig
    {
        return new LimiterConfig($this->capacity, $this->rate, 3600, $prefix);
    }

    /**
     * 用运行期上下文渲染 key 模板
     *
     * @param array<string, string|int> $context
     */
    public function resolveKey(array $context = [], string $fallback = 'global'): string
    {
        $template = $this->key ?? $fallback;

        foreach ($context as $name => $value) {
            $template = str_replace('{' . $name . '}', (string) $value, $template);
        }

        return $template;
    }
}
