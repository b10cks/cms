<?php

namespace App\Services\Ai\Learning;

use App\Enums\AiChangesetItemStatus;
use App\Jobs\ExtractGlossarySuggestionsJob;
use App\Models\Management\AiChangesetItem;
use App\Models\Management\AiGlossarySuggestion;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Threads\AiThreads;
use Illuminate\Support\Collection;

/**
 * The learning loop. When an editor changes AI output, before accepting it or
 * after accepting and before publishing, the AI text and the kept text go to
 * a small model call that extracts candidate glossary terms. Candidates the
 * space's glossaries already know are dropped; the rest wait in the space's
 * suggestions thread.
 *
 * @phpstan-type Edit array{ai: string, final: string, locale: string, source?: string, source_locale?: string, target_type: string, target_id: string|null, label: string|null}
 */
class EditLearning
{
    public const SKILL = 'extract-glossary-terms';

    private const MAX_EDITS = 20;

    private const MAX_TEXT = 4000;

    public function __construct(
        private readonly KnowledgeResolver $knowledge,
        private readonly AiThreads $threads,
    ) {}

    /**
     * Items a reviewer edited before accepting them (`final` set).
     *
     * @param  Collection<int, AiChangesetItem>  $items
     */
    public function fromAcceptedEdits(Space $space, User $user, Collection $items): void
    {
        $edits = $items
            ->filter(fn (AiChangesetItem $item) => \is_string($item->after) && \is_string($item->final))
            ->map(fn (AiChangesetItem $item) => $this->edit($space, $item, $item->final))
            ->values()
            ->all();

        AiChangesetItem::query()->whereKey($items->pluck('id')->all())->update(['learned_at' => now()]);
        $this->dispatch($space, $user, $edits);
    }

    /**
     * Accepted AI items of a just-published entry whose value an editor
     * changed in between. Each item is compared once.
     */
    public function fromPublish(Space $space, Content $content, ?User $user): void
    {
        $items = AiChangesetItem::query()
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->where('target_id', $content->id)
            ->where('operation', AiChangesetItem::OPERATION_UPDATE)
            ->where('status', AiChangesetItemStatus::Accepted->value)
            ->whereNull('learned_at')
            ->whereHas('changeset', fn ($query) => $query->where('space_id', $space->id))
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $content->loadMissing('published_version');
        $document = $content->published_version->content ?? [];
        $edits = [];

        foreach ($items as $item) {
            $kept = FieldPath::get($document, (string) $item->path);

            if (\is_string($item->after) && \is_string($kept) && trim($kept) !== '' && trim($kept) !== trim($item->after)) {
                $edits[] = $this->edit($space, $item, $kept, $content);
            }
        }

        AiChangesetItem::query()->whereKey($items->modelKeys())->update(['learned_at' => now()]);

        // A scheduled publish has no user; the reviewer who accepted acts instead.
        $user ??= User::query()->find($items->first()->decided_by_id);
        if ($user) {
            $this->dispatch($space, $user, $edits);
        }
    }

    /**
     * Store the model's candidates that no resolved glossary term and no open
     * suggestion already covers.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<Edit>  $edits
     * @return int suggestions created
     */
    public function suggest(Space $space, ?string $actorId, ?string $runId, array $candidates, array $edits): int
    {
        $known = [
            ...array_map(fn ($term) => [$term->term, $term->sourceLocale], $this->knowledge->glossaryTerms($space)),
            ...AiGlossarySuggestion::query()
                ->where('space_id', $space->id)
                ->where('status', AiGlossarySuggestion::STATUS_PENDING)
                ->get(['term', 'source_locale'])
                ->map(fn (AiGlossarySuggestion $suggestion) => [$suggestion->term, $suggestion->source_locale])
                ->all(),
        ];

        $created = 0;

        foreach ($candidates as $candidate) {
            $term = trim((string) ($candidate['term'] ?? ''));
            $locale = trim((string) ($candidate['source_locale'] ?? ''));

            if ($term === '' || $locale === '' || mb_strlen($term) > 255 || $this->isKnown($known, $term, $locale)) {
                continue;
            }

            AiGlossarySuggestion::create([
                'space_id' => $space->id,
                'run_id' => $runId,
                'actor_id' => $actorId,
                'source_locale' => $locale,
                'term' => $term,
                'translations' => array_filter((array) ($candidate['translations'] ?? []), fn ($value) => \is_string($value) && trim($value) !== '') ?: null,
                'forbidden' => array_values(array_filter((array) ($candidate['forbidden'] ?? []), fn ($value) => \is_string($value) && trim($value) !== '')) ?: null,
                'note' => \is_string($candidate['note'] ?? null) ? $candidate['note'] : null,
                'evidence' => $this->evidenceFor($term, $candidate, $edits),
                'status' => AiGlossarySuggestion::STATUS_PENDING,
            ]);
            $known[] = [$term, $locale];
            $created++;
        }

        if ($created > 0) {
            $this->threads->changed($space->id, $this->threads->suggestions($space)->id);
        }

        return $created;
    }

    /**
     * @param  list<Edit>  $edits
     */
    private function dispatch(Space $space, User $user, array $edits): void
    {
        if ($edits === []) {
            return;
        }

        ExtractGlossarySuggestionsJob::dispatch($space->id, $user->id, \array_slice($edits, 0, self::MAX_EDITS));
    }

    /**
     * @return Edit
     */
    private function edit(Space $space, AiChangesetItem $item, string $kept, ?Content $content = null): array
    {
        if ($content === null && $item->target_type === AiChangesetItem::TARGET_CONTENT) {
            $content = Content::query()->find($item->target_id);
        }

        $edit = [
            'ai' => mb_substr((string) $item->after, 0, self::MAX_TEXT),
            'final' => mb_substr($kept, 0, self::MAX_TEXT),
            'locale' => (string) ($content?->language_iso ?? $space->settings->getDefaultLanguage()),
            'target_type' => $item->target_type,
            'target_id' => $item->target_id,
            'label' => $item->target_label,
        ];

        // A translation's source text tells the model which phrase was translated.
        $canonical = $content?->i18n_parent_id ? Content::query()->with('published_version')->find($content->i18n_parent_id) : null;
        $source = $canonical ? FieldPath::get($canonical->published_version->content ?? [], (string) $item->path) : null;
        if (\is_string($source) && trim($source) !== '') {
            $edit['source'] = mb_substr($source, 0, self::MAX_TEXT);
            $edit['source_locale'] = (string) $canonical->language_iso;
        }

        return $edit;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $known
     */
    private function isKnown(array $known, string $term, string $locale): bool
    {
        foreach ($known as [$knownTerm, $knownLocale]) {
            if (mb_strtolower($knownTerm) === mb_strtolower($term) && GlossaryMatcher::sameLanguage($knownLocale, $locale)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The edit the term came from, for the reviewer.
     *
     * @param  array<string, mixed>  $candidate
     * @param  list<Edit>  $edits
     * @return array<string, mixed>|null
     */
    private function evidenceFor(string $term, array $candidate, array $edits): ?array
    {
        $needles = array_filter([$term, ...array_values((array) ($candidate['translations'] ?? []))], 'is_string');

        foreach ($edits as $edit) {
            foreach ($needles as $needle) {
                if (GlossaryMatcher::contains($edit['final'], $needle, false) || GlossaryMatcher::contains($edit['source'] ?? '', $needle, false)) {
                    return array_intersect_key($edit, array_flip(['ai', 'final', 'target_type', 'target_id', 'label']));
                }
            }
        }

        return isset($edits[0]) ? array_intersect_key($edits[0], array_flip(['ai', 'final', 'target_type', 'target_id', 'label'])) : null;
    }
}
