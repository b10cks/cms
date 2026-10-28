<?php

namespace App\Actions\Ai;

use App\Enums\AiThreadKind;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryTerm;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\Space\AiConversation;
use App\Models\Space\AiGlossarySuggestion;
use App\Models\User;
use App\Services\Ai\Threads\AiThreads;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Turns a learning suggestion into a glossary term of the space or its team,
 * or dismisses it. The glossary's owner decides who may add to it.
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

    public function accept(AiGlossarySuggestion $suggestion, Space $space, User $user, string $glossaryId): AiGlossaryTerm
    {
        $glossary = $this->glossariesFor($space, $user)->firstWhere('id', $glossaryId)
            ?? throw ValidationException::withMessages(['glossary_id' => ['You cannot add terms to this glossary.']]);

        $term = $glossary->terms()->create([
            'source_locale' => $suggestion->source_locale,
            'term' => $suggestion->term,
            'translations' => $suggestion->translations ?? [],
            'forbidden' => $suggestion->forbidden ?? [],
            'note' => $suggestion->note,
        ]);

        $this->decide($suggestion, $user, AiGlossarySuggestion::STATUS_ACCEPTED, $glossary->id);

        return $term;
    }

    public function dismiss(AiGlossarySuggestion $suggestion, User $user): void
    {
        $this->decide($suggestion, $user, AiGlossarySuggestion::STATUS_DISMISSED);
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
