<?php

namespace App\Services\Content;

use App\Models\Space\Block;
use App\Models\Space\Content;
use Illuminate\Support\Collection;

class ChildContentRuleService
{
    private const array ELIGIBLE_TYPES = ['root', 'universal'];

    protected ?Collection $eligibleBlocks = null;

    public function __construct(
        private readonly ContentI18nService $contentI18nService,
    ) {
    }

    public function getEligibleTypes(): array
    {
        return self::ELIGIBLE_TYPES;
    }

    public function getEligibleBlocks(): Collection
    {
        if ($this->eligibleBlocks !== null) {
            return $this->eligibleBlocks;
        }

        $request = ! app()->runningInConsole() && app()->bound('request') ? request() : null;
        $requestCacheKey = 'content.child_content_rule_service.eligible_blocks';

        if ($request?->attributes->has($requestCacheKey)) {
            /** @var Collection $cached */
            $cached = $request->attributes->get($requestCacheKey);
            $this->eligibleBlocks = $cached;

            return $cached;
        }

        $eligibleBlocks = Block::query()
            ->whereNull('deleted_at')
            ->whereIn('type', self::ELIGIBLE_TYPES)
            ->get();

        $this->eligibleBlocks = $eligibleBlocks;
        $request?->attributes->set($requestCacheKey, $eligibleBlocks);

        return $eligibleBlocks;
    }

    public function resolveAllowedBlocks(array $settings): Collection
    {
        return $this->filterAllowedBlocks($this->getEligibleBlocks(), $settings);
    }

    public function filterAllowedBlocks(Collection $eligibleBlocks, array $settings): Collection
    {
        if (! $this->shouldRestrict($settings)) {
            return $eligibleBlocks->values();
        }

        $activeBlockWhitelist = $this->normalizeStringArray($settings['child_block_whitelist'] ?? []);
        $activeTagWhitelist = $this->normalizeStringArray($settings['child_tag_whitelist'] ?? []);

        if ($activeBlockWhitelist === [] && $activeTagWhitelist === []) {
            return $eligibleBlocks->values();
        }

        return $eligibleBlocks
            ->filter(function (Block $block) use ($activeBlockWhitelist, $activeTagWhitelist): bool {
                $matchesBlockWhitelist = \in_array($block->slug, $activeBlockWhitelist, true);
                $matchesTagWhitelist = collect($block->tags ?? [])->contains(
                    fn (string $tag): bool => \in_array($tag, $activeTagWhitelist, true)
                );

                return $matchesBlockWhitelist || $matchesTagWhitelist;
            })
            ->values();
    }

    public function isBlockAllowedUnderParent(Block $block, ?Content $parent): bool
    {
        if ($parent === null) {
            return true;
        }

        $settings = $this->getCanonicalParentSettings($parent);
        $allowedBlocks = $this->resolveAllowedBlocks($settings);

        return $allowedBlocks->contains(fn (Block $allowedBlock): bool => $allowedBlock->id === $block->id);
    }

    public function getCanonicalParentSettings(Content $parent): array
    {
        $canonicalParent = $this->contentI18nService->getCanonicalContent($parent);

        return $canonicalParent->settings?->toArray() ?? [];
    }

    public function validateSettings(array $settings): array
    {
        $errors = [];
        $eligibleBlocks = $this->getEligibleBlocks();
        $eligibleBlockIds = $eligibleBlocks->pluck('id')->all();
        $eligibleBlockSlugs = $eligibleBlocks->pluck('slug')->all();
        $eligibleTags = $eligibleBlocks
            ->flatMap(fn (Block $block): array => $block->tags ?? [])
            ->filter(fn (mixed $tag): bool => \is_string($tag) && $tag !== '')
            ->unique()
            ->values()
            ->all();

        foreach ($this->normalizeStringArray($settings['child_block_whitelist'] ?? []) as $index => $slug) {
            if (! \in_array($slug, $eligibleBlockSlugs, true)) {
                $errors["settings.child_block_whitelist.{$index}"] =
                    'Selected child block types must be root or universal blocks that exist in this space.';
            }
        }

        foreach ($this->normalizeStringArray($settings['child_tag_whitelist'] ?? []) as $index => $tag) {
            if (! \in_array($tag, $eligibleTags, true)) {
                $errors["settings.child_tag_whitelist.{$index}"] =
                    'Selected child block tags must belong to at least one root or universal block in this space.';
            }
        }

        $defaultChildBlockId = $settings['default_child_block'] ?? null;
        if ($defaultChildBlockId !== null && ! \in_array($defaultChildBlockId, $eligibleBlockIds, true)) {
            $errors['settings.default_child_block'] =
                'The default child content type must be a root or universal block in this space.';
        }

        if ($defaultChildBlockId !== null && ! isset($errors['settings.default_child_block'])) {
            $hasExplicitAllowlists =
                $this->normalizeStringArray($settings['child_block_whitelist'] ?? []) !== []
                || $this->normalizeStringArray($settings['child_tag_whitelist'] ?? []) !== [];

            if ($this->shouldRestrict($settings) && $hasExplicitAllowlists) {
                $allowedBlockIds = $this->resolveAllowedBlocks($settings)->pluck('id')->all();

                if (! \in_array($defaultChildBlockId, $allowedBlockIds, true)) {
                    $errors['settings.default_child_block'] =
                        'The default child content type must match the configured child content restrictions.';
                }
            }
        }

        return $errors;
    }

    public function shouldRestrict(array $settings): bool
    {
        return (bool) ($settings['restrict_child_blocks'] ?? false);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeStringArray(array $values): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $value): ?string => \is_string($value) && trim($value) !== '' ? trim($value) : null,
                $values
            )
        ));
    }
}
