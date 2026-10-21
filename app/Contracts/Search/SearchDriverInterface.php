<?php

namespace App\Contracts\Search;

use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Support\Collection;

interface SearchDriverInterface
{
    public function indexContent(Content $content, Space $space): void;

    public function removeContent(Content $content, Space $space): void;

    /**
     * Bring the index in line with each entry's published state: index the
     * published ones, drop the rest. For bulk paths, so a backend can batch
     * the writes. One entry failing does not stop the others.
     *
     * @param  Collection<int, Content>  $contents
     * @return array<string, string> error message keyed by content id, for the entries that failed
     */
    public function syncContents(Collection $contents, Space $space): array;

    public function createIndex(Space $space): void;

    public function deleteIndex(Space $space): void;

    public function reindexSpace(Space $space): void;

    /**
     * Execute a search against the configured backend and return normalized hits.
     *
     * Implementations must return the same structure across drivers so the service layer
     * can hydrate the primary models in a single batched query without backend-specific branching.
     *
     * Expected return shape:
     * - `total` => int
     * - `results` => list<array{
     *     id: string,
     *     relevance_score: float|int|null
     *   }>
     *
     * The `id` must always be the primary `contents.id` value from the space database.
     * The `relevance_score` should be the backend-native score normalized as a numeric value.
     */
    public function search(Space $space, string $query, string $language, int $limit = 20, int $offset = 0): array;
}
