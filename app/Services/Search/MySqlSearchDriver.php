<?php

namespace App\Services\Search;

use App\Actions\Content\TransformContentToSearchable;
use App\Contracts\Search\SearchDriverInterface;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Support\Facades\DB;

class MySqlSearchDriver implements SearchDriverInterface
{
    public function __construct(
        protected TransformContentToSearchable $transformer
    ) {
    }

    public function indexContent(Content $content, Space $space): void
    {
        if (!$content->published_at) {
            return;
        }

        $searchableText = $this->transformer->execute($content, $space);
        try {
            $content->searchable_content = $searchableText;
            $content->saveQuietly();
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'searchable_content')) {
                return;
            }
            throw $e;
        }
    }

    public function removeContent(Content $content, Space $space): void
    {
        try {
            $content->searchable_content = null;
            $content->saveQuietly();
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'searchable_content')) {
                return;
            }
            throw $e;
        }
    }

    public function createIndex(Space $space): void
    {
        $content = new Content();
        $connection = $content->getConnection();

        // sqlite space databases have no FULLTEXT indexes; search() falls
        // back to LIKE there.
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        // Raw DDL bypasses the query grammar, so the table prefix (shared
        // profile) must be applied by hand.
        $tableName = $connection->getTablePrefix() . $content->getTable();

        $hasFullTextIndex = DB::connection($connection->getName())
            ->select("SHOW INDEX FROM {$tableName} WHERE Index_type = 'FULLTEXT' AND Key_name = 'idx_searchable_content'");

        if (empty($hasFullTextIndex)) {
            DB::connection($connection->getName())
                ->statement("ALTER TABLE {$tableName} ADD FULLTEXT INDEX idx_searchable_content (searchable_content)");
        }
    }

    public function deleteIndex(Space $space): void
    {
        $content = new Content();
        $connection = $content->getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            return;
        }

        $tableName = $connection->getTablePrefix() . $content->getTable();

        $hasFullTextIndex = DB::connection($connection->getName())
            ->select("SHOW INDEX FROM {$tableName} WHERE Index_type = 'FULLTEXT' AND Key_name = 'idx_searchable_content'");

        if (!empty($hasFullTextIndex)) {
            DB::connection($connection->getName())
                ->statement("ALTER TABLE {$tableName} DROP INDEX idx_searchable_content");
        }
    }

    public function reindexSpace(Space $space): void
    {
        Content::whereNotNull('contents.published_at')
            ->with(['i18n_parent', 'i18n_children', 'i18n_siblings', 'block', 'relations', 'assets', 'links'])
            ->select([
                'contents.*',
                'content_versions.content',
                'content_versions.relation_ids',
                'content_versions.asset_ids',
                'content_versions.link_ids'
            ])
            ->leftJoin('content_versions', 'contents.published_version_id', '=', 'content_versions.id')
            ->orderBy('contents.id')
            ->chunkById(100, function ($contents) use ($space) {
                foreach ($contents as $content) {
                    /** @var Content $content */
                    $this->indexContent($content, $space);
                }
            }, 'contents.id', 'id');
    }

    public function search(Space $space, string $query, string $language, int $limit = 20, int $offset = 0): array
    {
        if (empty(trim($query))) {
            return [
                'total' => 0,
                'results' => [],
            ];
        }

        $searchQuery = $this->prepareSearchQuery($query);

        // sqlite space databases (shared install profile) have no MATCH …
        // AGAINST — degrade to a LIKE scan over the searchable text.
        if (Content::query()->getConnection()->getDriverName() === 'sqlite') {
            return $this->searchWithLike($searchQuery, $language, $limit, $offset);
        }

        try {
            $baseQuery = Content::query()
                ->whereNotNull('published_at')
                ->whereNotNull('searchable_content')
                ->where('language_iso', $language)
                ->whereRaw("MATCH(searchable_content) AGAINST(? IN NATURAL LANGUAGE MODE)", [$searchQuery]);

            $total = (clone $baseQuery)->count();

            $results = (clone $baseQuery)
                ->selectRaw('id, MATCH(searchable_content) AGAINST(? IN NATURAL LANGUAGE MODE) as relevance_score', [$searchQuery])
                ->orderByDesc('relevance_score')
                ->orderBy('id')
                ->skip($offset)
                ->take($limit)
                ->get()
                ->map(fn($content) => $this->formatSearchHit(
                    id: $content->id,
                    relevanceScore: (float) $content->relevance_score,
                ))
                ->all();

            return [
                'total' => $total,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'searchable_content')) {
                return [
                    'total' => 0,
                    'results' => [],
                ];
            }

            throw $e;
        }
    }

    protected function searchWithLike(string $query, string $language, int $limit, int $offset): array
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);

        $baseQuery = Content::query()
            ->whereNotNull('published_at')
            ->whereNotNull('searchable_content')
            ->where('language_iso', $language)
            // sqlite's LIKE has no default escape character — declare one so
            // %/_ in the user's query match literally.
            ->whereRaw("searchable_content LIKE ? ESCAPE '\\'", ["%{$escaped}%"]);

        $total = (clone $baseQuery)->count();

        $results = (clone $baseQuery)
            ->select('id')
            ->orderBy('id')
            ->skip($offset)
            ->take($limit)
            ->get()
            ->map(fn ($content) => $this->formatSearchHit(
                id: $content->id,
                relevanceScore: 1.0,
            ))
            ->all();

        return [
            'total' => $total,
            'results' => $results,
        ];
    }

    protected function prepareSearchQuery(string $query): string
    {
        return trim($query);
    }

    protected function formatSearchHit(string $id, float $relevanceScore): array
    {
        return [
            'id' => $id,
            'relevance_score' => $relevanceScore,
        ];
    }
}
