<?php

declare(strict_types=1);

namespace BugHQ;

/**
 * A single step in the trail leading up to an event.
 *
 * `type` is the coarse kind (`navigation` | `http` | `query` | `log` |
 * `error` | `default`); `category` is the fine-grained source
 * (`sql.query`, `log.warning`, `route`, ...).
 */
final class Breadcrumb
{
    public function __construct(
        public readonly ?string $message = null,
        public readonly string $type = 'default',
        public readonly ?string $category = null,
        public readonly string $level = 'info',
        /** @var array<string, mixed>|null */
        public readonly ?array $data = null,
        public readonly ?string $timestamp = null,
    ) {
    }

    /**
     * @param array<string, mixed> $crumb
     */
    public static function fromArray(array $crumb): self
    {
        return new self(
            message: isset($crumb['message']) ? (string) $crumb['message'] : null,
            type: isset($crumb['type']) ? (string) $crumb['type'] : 'default',
            category: isset($crumb['category']) ? (string) $crumb['category'] : null,
            level: isset($crumb['level']) ? (string) $crumb['level'] : 'info',
            data: isset($crumb['data']) && \is_array($crumb['data']) ? $crumb['data'] : null,
            timestamp: isset($crumb['timestamp']) ? (string) $crumb['timestamp'] : null,
        );
    }

    /** UTC ISO-8601 with real milliseconds (gmdate's `v` always renders 000). */
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'timestamp' => $this->timestamp ?? self::now(),
            'type' => $this->type,
            'level' => $this->level,
        ];
        if ($this->category !== null) {
            $out['category'] = $this->category;
        }
        if ($this->message !== null) {
            $out['message'] = $this->message;
        }
        if ($this->data !== null) {
            $out['data'] = $this->data;
        }

        return $out;
    }
}
