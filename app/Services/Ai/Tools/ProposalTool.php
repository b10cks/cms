<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\User;
use App\Services\Ai\Changesets\ChangesetRecorder;
use App\Services\Ai\Changesets\ChangeTarget;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Contracts\ProposesChanges;
use App\Services\Ai\Run\ToolError;
use App\Services\Auth\AuthorizationService;

/**
 * Base of the write tools. Proposals land in the run's changeset, never in
 * content. A run started on a confirmed selection may only touch those
 * entries; any other run stops at `ai.inline_entry_limit` entries.
 */
abstract class ProposalTool implements AiToolInterface, ProposesChanges
{
    /** @var list<array{changeset_id: string, item_id: string, target_type: string, target_id: string|null, path: string|null}> */
    private array $proposals = [];

    public function __construct(
        protected readonly AiRun $run,
        protected readonly Space $space,
        protected readonly User $actor,
        protected readonly ChangeTargets $targets,
        protected readonly ChangesetRecorder $recorder,
        private readonly AuthorizationService $auth,
    ) {}

    public function takeProposals(): array
    {
        [$proposals, $this->proposals] = [$this->proposals, []];

        return $proposals;
    }

    /** The target kind, when the actor may read it. */
    protected function target(string $type): ChangeTarget
    {
        $target = $this->targets->for($type);

        if (! $this->auth->canInSpace($this->actor, $this->space, $target->viewAbility())) {
            throw new ToolError('You do not have access to these entries.');
        }

        return $target;
    }

    protected function canEdit(ChangeTarget $target): bool
    {
        return $this->auth->canInSpace($this->actor, $this->space, $target->editAbility());
    }

    /** @throws ToolError when the entry is outside what this run may touch */
    protected function ensureInScope(string $type, ?string $id): void
    {
        $frozen = $this->run->setup['targets'] ?? null;

        if (\is_array($frozen)) {
            if ($id === null || $type !== $frozen['type'] || ! \in_array($id, $frozen['ids'], true)) {
                throw new ToolError('This run works on the selected entries only. Propose changes to those entries and nothing else.');
            }

            return;
        }

        $limit = (int) config('ai.inline_entry_limit');
        $touched = $this->recorder->touchedTargets($this->run);

        if (($id === null || ! \in_array("{$type}:{$id}", $touched, true)) && \count($touched) >= $limit) {
            throw new ToolError("This task already changes {$limit} entries, the most a single request may. Stop here and tell the user to select the entries and start a bulk action for the rest.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<AiChangesetItem>
     */
    protected function record(array $items): array
    {
        $created = $this->recorder->record($this->run, $items);

        foreach ($created as $item) {
            $this->proposals[] = [
                'changeset_id' => $item->changeset_id,
                'item_id' => $item->id,
                'target_type' => $item->target_type,
                'target_id' => $item->target_id,
                'path' => $item->path,
            ];
        }

        return $created;
    }
}
