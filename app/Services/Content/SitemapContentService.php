<?php

namespace App\Services\Content;

use App\Models\Management\Space;
use Illuminate\Support\Collection;

class SitemapContentService
{
    /** @var array<string, Collection<string, string>> */
    private array $configuredMetaPathsCache = [];

    /**
     * @return Collection<string, string>
     */
    public function configuredMetaPathsByBlock(Space $space): Collection
    {
        return $this->configuredMetaPathsCache[$space->id] ??= collect($space->settings->getSitemapTypes())
            ->filter(fn (array $type): bool => filled($type['block'] ?? null) && filled($type['path'] ?? null))
            ->mapWithKeys(fn (array $type): array => [(string) $type['block'] => (string) $type['path']]);
    }

    public function metaPathForBlock(Space $space, string $blockSlug): ?string
    {
        return $this->configuredMetaPathsByBlock($space)->get($blockSlug);
    }

    /**
     * @return array{robots: ?string, canonical: ?string}
     */
    public function extractNormalizedMeta(
        Space $space,
        ResolvedContent $resolved,
        array $effectiveContent,
    ): array {
        $blockSlug = $resolved->canonicalContent->block?->slug
            ?? $resolved->resolvedRow?->block?->slug
            ?? $resolved->targetContent?->block?->slug
            ?? $resolved->fallbackContent?->block?->slug;

        if (! $blockSlug) {
            return [
                'robots' => null,
                'canonical' => null,
            ];
        }

        $path = $this->metaPathForBlock($space, $blockSlug);

        if (! $path) {
            return [
                'robots' => null,
                'canonical' => null,
            ];
        }

        $meta = data_get($effectiveContent, $path);

        if (! is_array($meta)) {
            return [
                'robots' => null,
                'canonical' => null,
            ];
        }

        return [
            'robots' => $this->normalizeRobots($meta['robots'] ?? null),
            'canonical' => $this->normalizeCanonical($meta['canonical'] ?? null),
        ];
    }

    /**
     * @param  array{robots: ?string, canonical: ?string}  $meta
     */
    public function isIndexable(array $meta): bool
    {
        $tokens = $this->normalizeRobotTokens($meta['robots'] ?? null);

        return ! in_array('noindex', $tokens, true) && ! in_array('none', $tokens, true);
    }

    public function normalizeRobots(mixed $robots): ?string
    {
        $tokens = $this->normalizeRobotTokens($robots);

        return $tokens === [] ? null : implode(',', $tokens);
    }

    public function normalizeCanonical(mixed $canonical): ?string
    {
        if (is_string($canonical)) {
            $value = trim($canonical);

            return $value !== '' ? $value : null;
        }

        if (! is_array($canonical)) {
            return null;
        }

        if (($canonical['type'] ?? null) === 'url' && filled($canonical['url'] ?? null)) {
            return trim((string) $canonical['url']);
        }

        if (($canonical['type'] ?? null) === 'internal' && filled($canonical['url'] ?? null)) {
            return trim((string) $canonical['url']);
        }

        if (filled($canonical['url'] ?? null)) {
            return trim((string) $canonical['url']);
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeRobotTokens(mixed $robots): array
    {
        if (! is_string($robots) || trim($robots) === '') {
            return [];
        }

        $tokens = [];
        $families = [
            ['all', 'none'],
            ['index', 'noindex'],
            ['follow', 'nofollow'],
        ];
        $order = [
            'all',
            'index',
            'noindex',
            'follow',
            'nofollow',
            'none',
            'noarchive',
            'nosnippet',
            'noimageindex',
            'notranslate',
            'nositelinkssearchbox',
            'indexifembedded',
        ];

        foreach (explode(',', $robots) as $rawToken) {
            $token = strtolower(trim($rawToken));

            if ($token === '') {
                continue;
            }

            foreach ($families as $family) {
                if (in_array($token, $family, true)) {
                    $tokens = array_values(array_filter(
                        $tokens,
                        fn (string $existing): bool => ! in_array($existing, $family, true),
                    ));
                }
            }

            $tokens = array_values(array_filter(
                $tokens,
                fn (string $existing): bool => $existing !== $token,
            ));
            $tokens[] = $token;
        }

        usort($tokens, function (string $left, string $right) use ($order): int {
            $leftIndex = array_search($left, $order, true);
            $rightIndex = array_search($right, $order, true);

            if ($leftIndex !== false || $rightIndex !== false) {
                if ($leftIndex === false) {
                    return 1;
                }

                if ($rightIndex === false) {
                    return -1;
                }

                return $leftIndex <=> $rightIndex;
            }

            return $left <=> $right;
        });

        return $tokens;
    }
}
