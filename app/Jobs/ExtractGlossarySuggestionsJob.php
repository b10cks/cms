<?php

namespace App\Jobs;

use App\Enums\AiRunState;
use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Learning\EditLearning;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Skills\SkillResolver;
use App\Support\SpaceContext;
use Illuminate\Support\Facades\Log;

/**
 * Runs the term extraction skill over editor corrections of AI output and
 * stores the candidates in the space's suggestions thread. Holds IDs and
 * plain text only.
 *
 * @phpstan-import-type Edit from EditLearning
 */
class ExtractGlossarySuggestionsJob extends QueuedJob
{
    public $tries = 1;

    /**
     * @param  list<Edit>  $edits
     */
    public function __construct(
        public string $spaceId,
        public string $actorId,
        public array $edits,
    ) {}

    protected function execute(): void
    {
        $space = Space::query()->find($this->spaceId);
        $actor = User::query()->find($this->actorId);
        $skill = $space ? (app(SkillResolver::class)->resolve($space)[EditLearning::SKILL] ?? null) : null;

        if (! $space || ! $actor || ! $skill) {
            return;
        }

        // The run and the suggestions live in the space's database.
        $restore = SpaceContext::enter($space);

        try {
            $run = app(AiRunService::class)->runToCompletion(new StartRun(
                space: $space,
                actor: $actor,
                surface: 'learning',
                skill: $skill,
                context: ['edits' => array_map(
                    fn (array $edit) => array_intersect_key($edit, array_flip(['ai', 'final', 'locale', 'source', 'source_locale'])),
                    $this->edits,
                )],
            ));

            $terms = $run->state === AiRunState::Completed ? ($run->result['data']['terms'] ?? []) : [];

            if (\is_array($terms) && $terms !== []) {
                app(EditLearning::class)->suggest($space, $actor->id, $run->id, array_values(array_filter($terms, 'is_array')), $this->edits);
            }
        } finally {
            $restore();
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::warning('Glossary suggestion extraction failed', ['space' => $this->spaceId, 'error' => $e->getMessage()]);
    }
}
