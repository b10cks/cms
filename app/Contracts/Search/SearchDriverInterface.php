<?php

namespace App\Contracts\Search;

use App\Models\Management\Space;
use App\Models\Space\Content;

interface SearchDriverInterface
{
    public function indexContent(Content $content, Space $space): void;

    public function removeContent(Content $content, Space $space): void;

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
