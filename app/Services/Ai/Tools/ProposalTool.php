<?php

namespace App\Services\Ai\Tools;

use App\Actions\Ai\AcceptAiChangesetItems;
use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Ai\Changesets\ChangesetRecorder;
use App\Services\Ai\Changesets\ChangeTarget;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\FieldPath;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Contracts\ProposesChanges;
use App\Services\Ai\Run\ToolError;
use App\Services\Auth\AuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Base of the write tools. Every change lands in the run's changeset. Content
 * has a draft layer, so a content change goes straight into the entry's draft
 * ({@see writeContent()}) and a person publishes it. Every other target goes live
 * when written, so its change waits as a proposal. A run started on a confirmed
 * selection may only touch those entries; any other run stops at
 * `ai.inline_entry_limit` entries.
 */
abstract class ProposalTool implements AiToolInterface, ProposesChanges
{
    /** What an agent reads when its change was not written, in every content tool's result. */
    protected const CONFLICT_NOTE = 'A person changed these fields after you read them. They stay as the person left them and are open for that person, so do not write them again, and say so in your summary. Everything else was handled as usual.';

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

    /**
     * Content changes are written right away, so the actor needs the rights a save needs.
     *
     * @throws ToolError
     */
    protected function ensureCanWriteContent(): void
    {
        if (! $this->canEdit($this->target(AiChangesetItem::TARGET_CONTENT))) {
            throw new ToolError('Whoever started this task may not edit entries in this space, so nothing was changed. Tell them.');
        }
    }

    /** @throws ToolError when the entry is outside what this run may touch */
    protected function ensureInScope(string $type, ?string $id): void
    {
        $frozen = $this->run->setup['targets'] ?? null;

        if (\is_array($frozen)) {
            if ($id === null || $type !== $frozen['type'] || ! \in_array($id, $frozen['ids'], true)) {
                throw new ToolError('This run works on the selected entries only. Change those entries and nothing else.');
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

    /**
     * Record content changes and write them to their drafts in one step, through
     * the path a person's accept takes, under the run's actor. A field someone
     * changed since the agent read it stays open as a conflict; the rest is
     * written. Anything refused leaves nothing behind, so the agent can fix it and
     * call again. Called inside the tool's fence: a crash rolls back the write with
     * its record and result, and the next owner repeats the call once.
     *
     * @param  callable(): list<AiChangesetItem>  $record
     * @return array{written: Collection<int, AiChangesetItem>, conflicts: Collection<int, AiChangesetItem>, version_id: string|null}
     *
     * @throws ToolError
     */
    protected function writeContent(callable $record): array
    {
        try {
            return (new AiChangesetItem)->getConnection()->transaction(function () use ($record) {
                $items = $record();
                $changeset = AiChangeset::query()->findOrFail($items[0]->changeset_id);
                app(AcceptAiChangesetItems::class)->handle($changeset, $this->space, $this->actor, array_map(fn (AiChangesetItem $item) => $item->id, $items));

                $fresh = AiChangesetItem::query()->whereKey(array_map(fn (AiChangesetItem $item) => $item->id, $items))->orderBy('id')->get();

                return [
                    'written' => $fresh->where('status', AiChangesetItemStatus::Accepted)->values(),
                    'conflicts' => $fresh->where('status', AiChangesetItemStatus::Conflict)->values(),
                    'version_id' => Content::query()->whereKey($fresh->first()?->target_id)->value('current_version_id'),
                ];
            });
        } catch (AuthorizationException) {
            $this->proposals = [];

            throw new ToolError('Whoever started this task may not edit entries in this space, so nothing was changed. Tell them.');
        } catch (ValidationException $e) {
            $this->proposals = [];

            throw new ToolError("Nothing was written:\n- ".implode("\n- ", Arr::flatten($e->errors())));
        }
    }

    /**
     * Which of these fields hold an open conflict, from any task. Such a field
     * stays open for a person: an agent's further write is refused here and
     * reported, while the other fields go on. Once a person decides, tasks may
     * write it again.
     *
     * @param  list<string>  $paths
     * @return array<string, string> requested path => the conflict's item ID
     */
    protected function leftForPerson(string $contentId, array $paths): array
    {
        $open = AiChangesetItem::query()
            ->where('target_type', AiChangesetItem::TARGET_CONTENT)
            ->where('target_id', $contentId)
            ->where('status', AiChangesetItemStatus::Conflict->value)
            ->get(['id', 'path']);

        $left = [];
        foreach ($paths as $path) {
            $conflict = $open->first(fn (AiChangesetItem $item) => $item->path !== null && FieldPath::overlap((string) $item->path, $path));
            if ($conflict !== null) {
                $left[$path] = $conflict->id;
            }
        }

        return $left;
    }

    /**
     * What the result of a write tells the agent.
     *
     * @param  array{written: Collection<int, AiChangesetItem>, conflicts: Collection<int, AiChangesetItem>, version_id: string|null}  $write
     * @param  array<string, string>  $left  fields refused before writing, from {@see leftForPerson()}
     * @return array<string, mixed>
     */
    protected function writeResult(array $write, array $left = []): array
    {
        $conflicts = [
            ...$write['conflicts']->map(fn (AiChangesetItem $item) => ['item_id' => $item->id, 'path' => $item->path])->all(),
            ...array_map(fn (string $path, string $itemId) => ['item_id' => $itemId, 'path' => $path], array_keys($left), $left),
        ];

        return [
            'written' => $write['written']->count(),
            'item_ids' => $write['written']->pluck('id')->all(),
            'version_id' => $write['version_id'],
            ...($conflicts !== [] ? ['conflicts' => $conflicts, 'conflict_note' => self::CONFLICT_NOTE] : []),
        ];
    }
}
