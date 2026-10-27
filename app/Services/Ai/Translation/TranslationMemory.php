<?php

namespace App\Services\Ai\Translation;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\AiChangesetItem;
use App\Models\Space\Content;
use App\Models\Space\TranslationUnit;
use App\Services\ContentData\ContentTranslationExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The space's translation memory. Units come from published translations
 * compared field by field with their canonical entry; runs use exact matches
 * instead of a provider call and fuzzy matches as examples.
 *
 * Only a translation's publish records units: publishing the canonical alone
 * would pair new source text with translations that have not caught up yet.
 */
class TranslationMemory
{
    /** Skills whose context carries a flat `key => source text` map, and its key. */
    public const SKILL_FIELDS = ['translate-fields' => 'fields', 'translate-data-entries' => 'entries'];

    private const FUZZY_CANDIDATES = 100;

    public function __construct(private readonly ContentTranslationExtractor $extractor) {}

    public static function hash(string $text): string
    {
        return hash('sha256', self::normalize($text));
    }

    /**
     * Store the units of a published translation. Upserts per field, so
     * republishing unchanged text changes nothing; fields that are no longer
     * translated are removed.
     *
     * @return int units stored
     */
    public function record(Content $translation): int
    {
        $canonical = $translation->i18n_parent_id ? Content::query()->with(['block', 'published_version'])->find($translation->i18n_parent_id) : null;
        $translation->loadMissing('published_version');

        if (! $canonical?->published_version || ! $translation->published_version || ! $canonical->block) {
            return 0;
        }

        $schema = $canonical->block->schema?->toArray() ?? [];
        $sources = $this->extractor->collectUnits($canonical->published_version->content ?? [], $schema);
        $targets = $this->extractor->collectUnits($translation->published_version->content ?? [], $schema);
        $aiWritten = $this->acceptedAiValues($translation->id);
        $now = now();
        $rows = [];

        foreach ($sources as $path => $unit) {
            $source = self::normalize($unit['value']);
            $target = self::normalize($targets[$path]['value'] ?? '');

            // Empty overlay fields fall back to the canonical value: not a translation.
            if ($source === '' || $target === '' || $source === $target) {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::ulid(),
                'source_hash' => self::hash($source),
                'source_locale' => strtolower((string) $canonical->language_iso),
                'target_locale' => strtolower((string) $translation->language_iso),
                'source_text' => $source,
                'target_text' => $target,
                'origin' => isset($aiWritten[$target]) ? TranslationUnit::ORIGIN_AI_ACCEPTED : TranslationUnit::ORIGIN_HUMAN,
                'content_id' => $translation->id,
                'field_path' => (string) $path,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        TranslationUnit::query()
            ->where('content_id', $translation->id)
            ->whereNotIn('field_path', array_column($rows, 'field_path'))
            ->delete();

        foreach (array_chunk($rows, 200) as $chunk) {
            TranslationUnit::query()->upsert(
                $chunk,
                ['content_id', 'field_path'],
                ['source_hash', 'source_locale', 'target_locale', 'source_text', 'target_text', 'origin', 'updated_at'],
            );
        }

        return \count($rows);
    }

    /**
     * Stored translations for texts seen before, newest first wins.
     *
     * @param  array<string, string>  $sources  key => source text
     * @return array<string, string> key => target text, only for exact matches
     */
    public function exact(array $sources, string $sourceLocale, string $targetLocale): array
    {
        $hashes = [];
        foreach ($sources as $key => $text) {
            if (self::normalize($text) !== '') {
                $hashes[$key] = self::hash($text);
            }
        }

        if ($hashes === []) {
            return [];
        }

        $targets = TranslationUnit::query()
            ->whereIn('source_hash', array_values(array_unique($hashes)))
            ->where('source_locale', strtolower($sourceLocale))
            ->where('target_locale', strtolower($targetLocale))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->pluck('target_text', 'source_hash')
            ->all();

        $found = [];
        foreach ($hashes as $key => $hash) {
            if (isset($targets[$hash])) {
                $found[$key] = $targets[$hash];
            }
        }

        return $found;
    }

    /**
     * Similar earlier translations to show the model as examples. MySQL
     * FULLTEXT finds candidates (LIKE elsewhere); word overlap ranks them.
     *
     * @param  list<string>  $sources
     * @return list<array{source: string, target: string}>
     */
    public function fuzzy(array $sources, string $sourceLocale, string $targetLocale, int $limit = 8): array
    {
        $text = mb_substr(implode("\n", $sources), 0, 2000);
        $words = $this->words($text);

        if ($words === []) {
            return [];
        }

        $query = TranslationUnit::query()
            ->where('source_locale', strtolower($sourceLocale))
            ->where('target_locale', strtolower($targetLocale));

        if (DB::connection((new TranslationUnit)->getConnectionName())->getDriverName() === 'mysql') {
            $query->whereRaw('MATCH(source_text) AGAINST(? IN NATURAL LANGUAGE MODE)', [$text]);
        } else {
            $query->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('source_text', 'like', '%'.addcslashes($word, '%_\\').'%');
                }
            });
        }

        $exact = array_flip(array_map(self::hash(...), $sources));

        return $query->limit(self::FUZZY_CANDIDATES)->get(['source_hash', 'source_text', 'target_text'])
            ->reject(fn (TranslationUnit $unit) => isset($exact[$unit->source_hash]))
            ->unique('source_hash')
            ->map(function (TranslationUnit $unit) use ($words) {
                $haystack = mb_strtolower($unit->source_text);
                $score = \count(array_filter($words, fn (string $word) => str_contains($haystack, $word)));

                return ['unit' => $unit, 'score' => $score];
            })
            ->filter(fn (array $hit) => $hit['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $hit) => ['source' => $hit['unit']->source_text, 'target' => $hit['unit']->target_text])
            ->values()
            ->all();
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * String values an accepted AI changeset item wrote into this entry.
     *
     * @return array<string, true>
     */
    private function acceptedAiValues(string $contentId): array
    {
        return AiChangesetItem::query()
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->where('target_id', $contentId)
            ->where('status', AiChangesetItemStatus::Accepted->value)
            ->get(['after', 'final'])
            ->map(fn (AiChangesetItem $item) => $item->final ?? $item->after)
            ->filter(fn (mixed $value) => \is_string($value))
            ->mapWithKeys(fn (string $value) => [self::normalize($value) => true])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return \array_slice(array_values(array_unique(array_filter($words, fn (string $word) => mb_strlen($word) >= 3))), 0, 12);
    }
}
