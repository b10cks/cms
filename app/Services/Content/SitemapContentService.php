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
        return $this->configuredMetaPathsCache[$space->id] ??= $this->metaPathsFromTypes(
            $space->settings->getSitemapTypes(),
        );
    }

    /**
     * Block-to-meta-path mappings for a named sitemap, or null when no sitemap
     * with the given slug is configured.
     *
     * @return ?Collection<string, string>
     */
    public function metaPathsForSitemap(Space $space, string $slug): ?Collection
    {
        $definition = $space->settings->getSitemapDefinition($slug);

        return $definition === null ? null : $this->metaPathsFromTypes($definition['types']);
    }

    /**
     * @param  array<int, array{block: string, path: string}>  $types
     * @return Collection<string, string>
     */
    private function metaPathsFromTypes(array $types): Collection
    {
        return collect($types)
            ->filter(fn (array $type): bool => filled($type['block'] ?? null) && filled($type['path'] ?? null))
            ->mapWithKeys(fn (array $type): array => [(string) $type['block'] => (string) $type['path']]);
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
