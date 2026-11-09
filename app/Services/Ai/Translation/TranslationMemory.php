<?php

namespace App\Services\Ai\Translation;

use App\Enums\AiChangesetItemStatus;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\Content;
use App\Models\Space\TranslationSource;
use App\Models\Space\TranslationUnit;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Knowledge\GlossaryConcept;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The space's translation memory. Units pair the published value of a
 * translated field with the source text that value was written against (its
 * lineage), which can be older than the canonical's text; runs use exact
 * matches instead of a provider call and fuzzy matches as examples. Runs read both
 * under the terminology of their policy, so a pair a later term change invalidated
 * is not reused.
 *
 * Publication by a person is what makes a pair verified, so only a translation's
 * publish records units, from its published version. An AI draft is never read
 * here, and a draft a person edited before publishing yields their text. `origin`
 * says who wrote the text, `ai_accepted` being AI text a person published. A field
 * with no known source text is left out rather than guessed from the canonical as
 * it stands now.
 */
class TranslationMemory
{
    /** Skills whose context carries a flat `key => source text` map, and its key. */
    public const SKILL_FIELDS = ['translate-fields' => 'fields', 'translate-data-entries' => 'entries'];

    private const FUZZY_CANDIDATES = 100;

    public function __construct(
        private readonly TranslationUnits $units,
        private readonly TranslationLineage $lineage,
        private readonly GlossaryChecker $checker,
    ) {}

    public static function hash(string $text): string
    {
        return hash('sha256', self::normalize($text));
    }

    /**
     * Store the units of a published translation. Upserts per field, so
     * republishing unchanged text keeps its pair, also after the source moved
     * on; fields that are no longer translated are removed.
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
        // Empty overlay fields fall back to the canonical value: not a translation.
        $targets = array_filter(
            $this->units->texts($translation->published_version->content ?? [], $schema),
            fn (string $text) => ! TranslationUnits::isBlank($text),
        );
        $lineage = $this->lineage->of([[
            'source' => $canonical,
            'translation' => $translation,
            'targets' => $targets,
            'version' => $translation->published_version,
        ]])[$translation->id] ?? [];
        $aiWritten = $this->acceptedAiValues($translation->id);
        $now = now();
        $rows = [];

        foreach ($lineage as $path => $known) {
            $source = self::normalize($known['source_text']);
            $target = self::normalize($targets[$path]);

            if (TranslationUnits::isBlank($source) || $source === $target) {
                continue;
            }

            $byAi = $known['origin'] !== TranslationSource::ORIGIN_HISTORY || isset($aiWritten[$target]);

            $rows[] = [
                'id' => (string) Str::ulid(),
                'source_hash' => self::hash($source),
                'source_locale' => strtolower((string) $canonical->language_iso),
                'target_locale' => strtolower((string) $translation->language_iso),
                'source_text' => $source,
                'target_text' => $target,
                'origin' => $byAi ? TranslationUnit::ORIGIN_AI_ACCEPTED : TranslationUnit::ORIGIN_HUMAN,
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
     * A person's correction of a stored pair: their text, verified as human.
     * The newest unit wins an exact match, so it replaces older ones at once.
     * The entry itself is not changed, so its next publish records what it
     * holds then.
     */
    public function correct(TranslationUnit $unit, string $target): TranslationUnit
    {
        $unit->update(['target_text' => self::normalize($target), 'origin' => TranslationUnit::ORIGIN_HUMAN]);

        return $unit;
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
     * Stored translations free of deterministic glossary violations, newest
     * compliant one first, human wording included.
     *
     * @param  array<string, string>  $sources  key => source text
     * @param  list<GlossaryConcept>  $terms
     * @return array{hits: array<string, string>, rejected: array<string, list<array<string, string>>>}
     */
    public function exactUnderPolicy(array $sources, string $sourceLocale, string $targetLocale, array $terms): array
    {
        $hashes = [];
        foreach ($sources as $key => $text) {
            if (self::normalize($text) !== '') {
                $hashes[$key] = self::hash($text);
            }
        }

        $result = ['hits' => [], 'rejected' => []];
        if ($hashes === []) {
            return $result;
        }

        $rows = TranslationUnit::query()
            ->whereIn('source_hash', array_values(array_unique($hashes)))
            ->where('source_locale', strtolower($sourceLocale))
            ->where('target_locale', strtolower($targetLocale))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['source_hash', 'source_text', 'target_text'])
            ->groupBy('source_hash');

        foreach ($hashes as $key => $hash) {
            $findings = [];

            foreach (($rows[$hash] ?? collect())->unique('target_text') as $unit) {
                $problems = GlossaryChecker::blocking($this->checker->check($unit->source_text, $unit->target_text, $sourceLocale, $targetLocale, $terms));

                if ($problems === []) {
                    $result['hits'][$key] = $unit->target_text;
                    $findings = [];

                    break;
                }

                $findings = $findings === [] ? $problems : $findings;
            }

            if ($findings !== []) {
                $result['rejected'][$key] = $findings;
            }
        }

        return $result;
    }

    /**
     * Similar earlier translations to show the model as examples. MySQL
     * FULLTEXT finds candidates (LIKE elsewhere); word overlap ranks them.
     *
     * @param  list<string>  $sources
     * @param  list<GlossaryConcept>|null  $terms  policy every example must pass; null trusts stored pairs
     * @return list<array{source: string, target: string}>
     */
    public function fuzzy(array $sources, string $sourceLocale, string $targetLocale, int $limit = 8, ?array $terms = null): array
    {
        $exact = array_flip(array_map(self::hash(...), $sources));

        return array_map(
            fn (array $hit) => ['source' => $hit['unit']->source_text, 'target' => $hit['unit']->target_text],
            $this->ranked(implode("\n", $sources), $sourceLocale, $targetLocale, $limit, $terms, $exact),
        );
    }

    /**
     * Stored pairs whose source resembles one text, best first, for a person
     * translating it: an exact match scores 1, others the shared words over
     * the longer of the two texts, so a short field never scores high against
     * a long paragraph that happens to hold its words.
     *
     * @param  list<GlossaryConcept>|null  $terms  policy every pair must pass; null trusts stored pairs
     * @return list<array{score: float, source: string, target: string, origin: string, content_id: string|null, field_path: string|null}>
     */
    public function similar(string $source, string $sourceLocale, string $targetLocale, int $limit = 5, ?array $terms = null): array
    {
        $hash = self::hash($source);
        $words = \count($this->words(mb_substr($source, 0, 2000)));

        $hits = array_map(fn (array $hit) => [
            'score' => $hit['unit']->source_hash === $hash
                ? 1.0
                : round(min(0.99, $hit['score'] / max(1, $words, \count($this->words(mb_substr($hit['unit']->source_text, 0, 2000))))), 2),
            'source' => $hit['unit']->source_text,
            'target' => $hit['unit']->target_text,
            'origin' => $hit['unit']->origin,
            'content_id' => $hit['unit']->content_id,
            'field_path' => $hit['unit']->field_path,
        ], $this->ranked($source, $sourceLocale, $targetLocale, $limit, $terms));
        usort($hits, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $hits;
    }

    /**
     * Candidates for a text ranked by word overlap, an exact match first.
     *
     * @param  list<GlossaryConcept>|null  $terms
     * @param  array<string, mixed>  $exclude  source hashes to leave out
     * @return list<array{unit: TranslationUnit, score: int}>
     */
    private function ranked(string $text, string $sourceLocale, string $targetLocale, int $limit, ?array $terms, array $exclude = []): array
    {
        $text = mb_substr($text, 0, 2000);
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

        $hash = self::hash($text);

        return $query->limit(self::FUZZY_CANDIDATES)->get(['source_hash', 'source_text', 'target_text', 'origin', 'content_id', 'field_path'])
            ->reject(fn (TranslationUnit $unit) => isset($exclude[$unit->source_hash]))
            ->unique('source_hash')
            ->map(function (TranslationUnit $unit) use ($words, $hash) {
                $haystack = mb_strtolower($unit->source_text);
                $score = \count(array_filter($words, fn (string $word) => str_contains($haystack, $word)));

                return ['unit' => $unit, 'score' => $unit->source_hash === $hash ? PHP_INT_MAX : $score];
            })
            ->filter(fn (array $hit) => $hit['score'] > 0)
            ->sortByDesc('score')
            ->lazy()
            ->filter(fn (array $hit) => $terms === null || GlossaryChecker::blocking($this->checker->check($hit['unit']->source_text, $hit['unit']->target_text, $sourceLocale, $targetLocale, $terms)) === [])
            ->take($limit)
            ->values()
            ->all();
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * String values an AI changeset item wrote into this entry. Covers
     * proposals from before lineage was stored, which have no lineage rows.
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
