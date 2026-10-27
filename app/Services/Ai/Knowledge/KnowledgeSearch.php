<?php

namespace App\Services\Ai\Knowledge;

use App\Models\Management\AiKnowledgeChunk;
use App\Models\Management\AiKnowledgeDocument;
use Illuminate\Support\Facades\DB;

/**
 * Retrieval over published reference chunks. MySQL FULLTEXT finds candidates
 * (LIKE elsewhere); ranking adds a boost for hits in the title, slug and
 * heading. Only the given revision IDs are searched, which is how a run stays
 * on the revisions it started with.
 */
class KnowledgeSearch
{
    public const EXCERPT_CHARS = 1200;

    /**
     * @param  array<string, string>  $revisions  revision_id => document_id
     * @return list<array{chunk_id: int, document_id: string, revision_id: string, slug: string, title: string, heading: string, excerpt: string}>
     */
    public function search(string $query, array $revisions, int $limit = 5): array
    {
        $words = $this->words($query);

        if ($words === [] || $revisions === []) {
            return [];
        }

        $candidates = AiKnowledgeChunk::query()
            ->whereIn('revision_id', array_keys($revisions))
            ->when(
                DB::connection((new AiKnowledgeChunk)->getConnectionName())->getDriverName() === 'mysql',
                fn ($q) => $q->whereRaw('MATCH(heading_path, text) AGAINST(? IN NATURAL LANGUAGE MODE)', [$query]),
                fn ($q) => $q->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $q->orWhere('text', 'like', '%'.$word.'%')->orWhere('heading_path', 'like', '%'.$word.'%');
                    }
                }),
            )
            ->limit(200)
            ->get();

        $documents = AiKnowledgeDocument::query()
            ->whereIn('id', array_unique(array_values($revisions)))
            ->get(['id', 'slug', 'title'])
            ->keyBy('id');

        $scored = $candidates->map(function (AiKnowledgeChunk $chunk) use ($words, $revisions, $documents) {
            $document = $documents[$revisions[$chunk->revision_id]] ?? null;
            $haystack = mb_strtolower($chunk->text);
            $heading = mb_strtolower($chunk->heading_path.' '.($document?->title ?? '').' '.($document?->slug ?? ''));
            $score = 0;

            foreach ($words as $word) {
                $score += substr_count($haystack, $word) + 3 * substr_count($heading, $word);
            }

            return ['chunk' => $chunk, 'document' => $document, 'score' => $score];
        })->filter(fn (array $hit) => $hit['score'] > 0 && $hit['document'] !== null)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return $scored->map(fn (array $hit) => [
            'chunk_id' => $hit['chunk']->id,
            'document_id' => $hit['document']->id,
            'revision_id' => $hit['chunk']->revision_id,
            'slug' => $hit['document']->slug,
            'title' => $hit['document']->title,
            'heading' => $hit['chunk']->heading_path,
            'excerpt' => mb_substr($hit['chunk']->text, 0, self::EXCERPT_CHARS),
        ])->all();
    }

    /**
     * @return list<string>
     */
    private function words(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return \array_slice(array_values(array_unique(array_filter($words, fn (string $word) => mb_strlen($word) >= 3))), 0, 8);
    }
}
