<?php

namespace App\Services\Ai\Knowledge;

use App\Models\Management\AiKnowledgeChunk;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\AiKnowledgeRevision;
use Illuminate\Support\Facades\DB;

/**
 * Retrieval over published reference chunks. MySQL FULLTEXT finds candidates
 * (LIKE elsewhere); ranking adds a boost for hits in the title, slug and
 * heading. Only the given revision IDs are searched, which is how a run stays
 * on the revisions it started with.
 *
 * A pinned run passes its frozen descriptors, so results name the document as
 * the policy froze it. The text always comes from the immutable revision.
 *
 * @phpstan-type Source array{document_id: string, slug: string, title: string, locale?: string|null, revision?: int, hash?: string}
 */
class KnowledgeSearch
{
    public const EXCERPT_CHARS = 1200;

    /**
     * @param  array<string, string>  $revisions  revision_id => document_id
     * @param  array<string, Source>|null  $sources  frozen descriptors by revision; null names documents by the current row
     * @return list<array{chunk_id: int, document_id: string, revision_id: string, slug: string, title: string, heading: string, excerpt: string}>
     */
    public function search(string $query, array $revisions, int $limit = 5, ?array $sources = null): array
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

        $sources = $this->sources($revisions, $sources);

        $scored = $candidates->map(function (AiKnowledgeChunk $chunk) use ($words, $sources) {
            $source = $sources[$chunk->revision_id] ?? null;
            $haystack = mb_strtolower($chunk->text);
            $heading = mb_strtolower($chunk->heading_path.' '.($source['title'] ?? '').' '.($source['slug'] ?? ''));
            $score = 0;

            foreach ($words as $word) {
                $score += substr_count($haystack, $word) + 3 * substr_count($heading, $word);
            }

            return ['chunk' => $chunk, 'source' => $source, 'score' => $score];
        })->filter(fn (array $hit) => $hit['score'] > 0 && $hit['source'] !== null)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return $scored->map(fn (array $hit) => [
            'chunk_id' => $hit['chunk']->id,
            'document_id' => $hit['source']['document_id'],
            'revision_id' => $hit['chunk']->revision_id,
            'slug' => $hit['source']['slug'],
            'title' => $hit['source']['title'],
            'heading' => $hit['chunk']->heading_path,
            'excerpt' => mb_substr($hit['chunk']->text, 0, self::EXCERPT_CHARS),
        ])->all();
    }

    /**
     * Revisions of the given set that no longer exist, deleted before revisions
     * were retained. A search cannot speak for these, so the caller must say so
     * rather than report a clean empty result.
     *
     * @param  array<string, string>  $revisions  revision_id => document_id
     * @param  array<string, Source>|null  $sources
     * @return list<array{document_id: string, revision_id: string, title: string|null}>
     */
    public function unavailable(array $revisions, ?array $sources = null): array
    {
        if ($revisions === []) {
            return [];
        }

        $present = array_flip(AiKnowledgeRevision::query()->whereIn('id', array_keys($revisions))->pluck('id')->all());
        $missing = [];

        foreach (array_diff_key($revisions, $present) as $revisionId => $documentId) {
            $missing[] = ['document_id' => $documentId, 'revision_id' => (string) $revisionId, 'title' => $sources[$revisionId]['title'] ?? null];
        }

        return $missing;
    }

    /**
     * @param  array<string, string>  $revisions
     * @param  array<string, Source>|null  $sources
     * @return array<string, Source>
     */
    private function sources(array $revisions, ?array $sources): array
    {
        if ($sources !== null) {
            return $sources;
        }

        $documents = AiKnowledgeDocument::query()
            ->withTrashed()
            ->whereIn('id', array_unique(array_values($revisions)))
            ->get(['id', 'slug', 'title'])
            ->keyBy('id');

        $live = [];
        foreach ($revisions as $revisionId => $documentId) {
            if ($document = $documents->get($documentId)) {
                $live[$revisionId] = ['document_id' => $document->id, 'slug' => $document->slug, 'title' => $document->title];
            }
        }

        return $live;
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
