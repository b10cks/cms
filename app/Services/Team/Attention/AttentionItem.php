<?php

namespace App\Services\Team\Attention;

/**
 * A single overview item. The frontend picks icon, title and action label by
 * `type`; `details` are short lines shown under the title (names, emails).
 * `target` is the route the action button opens.
 */
final readonly class AttentionItem
{
    /**
     * @param  array<int, string>  $details
     * @param  array{name: string, params?: array<string, string>, query?: array<string, string>}|null  $target
     */
    public function __construct(
        public string $type,
        public int $count,
        public array $details = [],
        public ?array $target = null,
    ) {}

    /**
     * @return array{type: string, count: int, details: array<int, string>, target: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'count' => $this->count,
            'details' => $this->details,
            'target' => $this->target,
        ];
    }
}
