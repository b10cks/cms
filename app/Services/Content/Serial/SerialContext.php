<?php

namespace App\Services\Content\Serial;

use App\Models\Space\Block;
use App\Models\Space\Content;
use Illuminate\Support\Carbon;

/**
 * Everything a token resolver is allowed to see while rendering a serial or a
 * slug pattern. Deterministic by construction: the same context and the same
 * number always render the same value, which is what makes previewing and
 * re-issuing possible.
 */
class SerialContext
{
    /**
     * @var array<int, Content>|null
     */
    protected ?array $ancestors = null;

    /**
     * @param  array<string, mixed>  $values  the entry's own field values
     */
    public function __construct(
        public readonly Block $block,
        public readonly ?Content $parent,
        public readonly string $languageIso,
        public readonly array $values = [],
        public readonly ?int $number = null,
        public readonly ?Carbon $createdAt = null,
    ) {}

    public function withNumber(int $number): self
    {
        $clone = new self(
            $this->block,
            $this->parent,
            $this->languageIso,
            $this->values,
            $number,
            $this->createdAt,
        );
        $clone->ancestors = $this->ancestors;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function withValues(array $values): self
    {
        $clone = new self(
            $this->block,
            $this->parent,
            $this->languageIso,
            $values,
            $this->number,
            $this->createdAt,
        );
        $clone->ancestors = $this->ancestors;

        return $clone;
    }

    public function now(): Carbon
    {
        return $this->createdAt ?? Carbon::now();
    }

    /**
     * The parent chain, nearest first. Walked once and cached: a serial format
     * with several ancestor tokens must not cost several queries.
     *
     * @return array<int, Content>
     */
    public function ancestors(): array
    {
        if ($this->ancestors !== null) {
            return $this->ancestors;
        }

        $chain = [];
        $current = $this->parent;
        $guard = 0;

        while ($current !== null && $guard++ < 64) {
            $chain[] = $current;

            if ($current->parent_id === null) {
                break;
            }

            $current = Content::query()
                ->with('current_version')
                ->whereNull('deleted_at')
                ->find($current->parent_id);
        }

        return $this->ancestors = $chain;
    }

    /**
     * @return array<string, mixed>
     */
    public function contentValues(Content $content): array
    {
        $values = $content->getCurrentContent();

        return $values !== [] ? $values : $content->getContent();
    }
}
