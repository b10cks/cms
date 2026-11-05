<?php

namespace App\Actions\Ai;

use App\Enums\AiThreadKind;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\Space\AiConversation;
use App\Models\Space\AiGlossarySuggestion;
use App\Models\User;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use App\Services\Ai\Threads\AiThreads;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Turns a learning suggestion into a glossary row of the space or its team, through
 * the same writer as every other change, or dismisses it. The glossary's owner decides
 * who may add to it. A wording the glossary already holds, a wording over the limit or a
 * note over the writer's limit is refused and the suggestion stays open as it is.
 */
class AcceptAiGlossarySuggestion
{
    public function __construct(private readonly AiThreads $threads) {}

    /**
     * Glossaries of the space and its owning team the user may add terms to.
     *
     * @return Collection<int, AiGlossary>
     */
    public function glossariesFor(Space $space, User $user): Collection
    {
        $team = $space->team_id ? Team::query()->find($space->team_id) : null;
        $owners = array_filter([$space, $team], fn (Space|Team|null $owner) => $owner !== null && Gate::forUser($user)->allows('manage', [AiGlossary::class, $owner]));

        return collect($owners)
            ->flatMap(fn (Space|Team $owner) => AiGlossary::query()->ownedBy($owner)->orderBy('name')->get())
            ->values();
    }

    /**
     * @return array{glossary: AiGlossary, row_id: string}
     */
    public function accept(AiGlossarySuggestion $suggestion, Space $space, User $user, string $glossaryId): array
    {
        $glossary = $this->glossariesFor($space, $user)->firstWhere('id', $glossaryId)
            ?? throw ValidationException::withMessages(['glossary_id' => ['You cannot add terms to this glossary.']]);

        if (AiGlossarySuggestion::hasOverlongWording([$suggestion->term, ...array_values($suggestion->translations ?? []), ...$suggestion->forbidden ?? []])) {
            throw ValidationException::withMessages(['suggestion' => ['A wording in this suggestion is longer than '.AiGlossarySuggestion::WORDING_LIMIT.' characters. Nothing was added.']]);
        }

        $rowId = GlossaryConcepts::transaction($glossary, fn (GlossaryConcepts $concepts) => $concepts->create($this->rowInput($suggestion)));

        $this->decide($suggestion, $user, AiGlossarySuggestion::STATUS_ACCEPTED, $glossary->id);

        return ['glossary' => $glossary, 'row_id' => $rowId];
    }

    public function dismiss(AiGlossarySuggestion $suggestion, User $user): void
    {
        $this->decide($suggestion, $user, AiGlossarySuggestion::STATUS_DISMISSED);
    }

    /**
     * What it says in its own language, with the wording it learned as the other languages' Use.
     *
     * @return array{note?: string, languages: array<string, array{use?: string, avoid?: list<string>}>}
     */
    private function rowInput(AiGlossarySuggestion $suggestion): array
    {
        $languages = [];

        foreach ($suggestion->translations ?? [] as $locale => $wording) {
            $languages[(string) $locale] = ['use' => $wording];
        }

        $languages[$suggestion->source_locale] = ['use' => $suggestion->term, 'avoid' => array_values(array_diff($suggestion->forbidden ?? [], [$suggestion->term]))];

        return [...($suggestion->note !== null ? ['note' => $suggestion->note] : []), 'languages' => $languages];
    }

    private function decide(AiGlossarySuggestion $suggestion, User $user, string $status, ?string $glossaryId = null): void
    {
        $suggestion->update([
            'status' => $status,
            'glossary_id' => $glossaryId,
            'decided_by_id' => $user->id,
            'decided_at' => now(),
        ]);

        $thread = AiConversation::query()
            ->where('space_id', $suggestion->space_id)
            ->where('kind', AiThreadKind::Suggestions->value)
            ->value('id');
        $this->threads->changed($suggestion->space_id, $thread);
    }
}
