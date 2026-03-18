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
        $tableName = $content->getTable();

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
        $tableName = $content->getTable();

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
            ->chunk(100, function ($contents) use ($space) {
                foreach ($contents as $content) {
                    $this->indexContent($content, $space);
                }
            });
    }

    public function search(Space $space, string $query, string $language, int $limit = 20, int $offset = 0): array
    {
        if (empty(trim($query))) {
            return [
                'total' => 0,
                'results' => [],
            ];
        }

        $content = new Content();
        $connection = $content->getConnection();
        $tableName = $content->getTable();

        $searchQuery = $this->prepareSearchQuery($query);

        try {
            $totalQuery = Content::whereNotNull('published_at')
                ->whereNotNull('searchable_content')
                ->where('language_iso', $language)
                ->whereRaw("MATCH(searchable_content) AGAINST(? IN NATURAL LANGUAGE MODE)", [$searchQuery]);

            $total = $totalQuery->count();

            $results = Content::whereNotNull('published_at')
                ->whereNotNull('searchable_content')
                ->where('language_iso', $language)
                ->whereRaw("MATCH(searchable_content) AGAINST(? IN NATURAL LANGUAGE MODE)", [$searchQuery])
                ->selectRaw("contents.*, MATCH(searchable_content) AGAINST(? IN NATURAL LANGUAGE MODE) as relevance_score", [$searchQuery])
                ->orderByDesc('relevance_score')
                ->skip($offset)
                ->take($limit)
                ->get()
                ->map(fn($content) => [
                    'id' => $content->id,
                    'name' => $content->name,
                    'slug' => $content->slug,
                    'full_slug' => $content->full_slug,
                    'language_iso' => $content->language_iso,
                    'block_id' => $content->block_id,
                    'published_at' => $content->published_at?->toIso8601String(),
                    'relevance_score' => $content->relevance_score,
                ])
                ->toArray();

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

    protected function prepareSearchQuery(string $query): string
    {
        return trim($query);
    }
}
