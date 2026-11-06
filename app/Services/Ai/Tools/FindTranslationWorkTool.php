<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Contracts\ReportsOutcomes;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Translation\TranslationDestinations;
use App\Services\Ai\Translation\TranslationPlan;
use App\Services\Ai\Translation\TranslationTask;
use App\Services\Ai\Translation\TranslationWork;
use App\Services\Auth\AuthorizationService;

/**
 * Lists translation work over a scope, a page at a time: per source entry
 * and locale whether the translation is missing, outdated (empty or stale
 * fields), current or already waiting for review. A run on a confirmed
 * selection pages through that selection.
 */
class FindTranslationWorkTool implements AiToolInterface, ReportsOutcomes
{
    public const NAME = 'find_translation_work';

    private const DEFAULT_LIMIT = 25;

    private const MAX_LIMIT = 50;

    /** @var list<array{target_type: string, target_id: string, outcome: string, reason: string|null, part: string}> */
    private array $outcomes = [];

    public function __construct(
        /** Null outside a run (MCP): no approved plan, no selection. */
        private readonly ?AiRun $run,
        private readonly Space $space,
        private readonly User $actor,
        private readonly TranslationDestinations $destinations,
        private readonly TranslationWork $work,
        private readonly AuthorizationService $auth,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Finds translation work, a page of source entries at a time. For each entry and language: status missing (no translation entry yet), outdated (empty or stale fields), '
            .'current or skipped (not part of the approved plan), with unit counts per reason. '
            .'Uses the selected entries when the run has them, else the given ids, or source entries filtered by block, parent folder and name. Pass next_cursor to get the next page.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'languages' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Target locale codes. Defaults to the approved languages, else all translation languages.'],
                'ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Entry IDs, in any language.'],
                'block' => ['type' => 'string', 'description' => 'Block slug.'],
                'parent_id' => ['type' => 'string', 'description' => 'Folder or parent entry ID, direct children only.'],
                'query' => ['type' => 'string', 'description' => 'Part of the name.'],
                'cursor' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'description' => 'Entries per page (default 25, max 50).'],
            ],
        ];
    }

    public function takeOutcomes(): array
    {
        [$outcomes, $this->outcomes] = [$this->outcomes, []];

        return $outcomes;
    }

    /** Locales code settles without a write count as done for their source. */
    private function reportSettled(TranslationTask $task): void
    {
        if ($settled = $task->settled()) {
            $this->outcomes[] = ['target_type' => AiChangesetItem::TARGET_CONTENT, 'target_id' => $task->destination->source->id, ...$settled, 'part' => $task->destination->locale];
        }
    }

    public function execute(array $input): mixed
    {
        $this->outcomes = [];
        if (! $this->auth->canInSpace($this->actor, $this->space, 'content.view')) {
            throw new ToolError('You do not have access to these entries.');
        }

        $plan = $this->run ? TranslationPlan::forRun($this->run) : new TranslationPlan;
        $languages = \is_array($input['languages'] ?? null) && $input['languages'] !== []
            ? array_values(array_filter($input['languages'], 'is_string'))
            : ($plan->locales ?? array_values(array_diff($this->space->settings->getEnabledLanguages(), [$this->space->settings->getDefaultLanguage()])));

        if ($languages === []) {
            throw new ToolError('This space has no translation languages.');
        }

        $offset = max(0, (int) ($input['cursor'] ?? 0));
        $limit = max(1, min(self::MAX_LIMIT, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)));
        [$ids, $total] = $this->page($input, $offset, $limit);

        $resolved = $this->destinations->resolveMany($this->space, $ids, $languages, $plan->locales);
        $tasks = $this->work->tasks($this->space, $resolved['destinations'], $plan, $this->run);

        $entries = [];
        foreach ($tasks as $task) {
            $this->reportSettled($task);
            $source = $task->destination->source;
            $entries[$source->id] ??= ['id' => $source->id, 'name' => $source->name, 'languages' => []];
            $entries[$source->id]['languages'][$task->destination->locale] = array_filter([
                'status' => $task->status(),
                'destination_id' => $task->destination->translation?->id,
                'units' => $task->counts() ?: null,
            ], fn ($value) => $value !== null);
        }

        return [
            'total' => $total,
            'entries' => array_values($entries),
            ...($resolved['skipped'] !== [] ? ['skipped' => $resolved['skipped']] : []),
            ...($offset + $limit < $total ? ['next_cursor' => (string) ($offset + $limit)] : []),
            'summary' => array_count_values(array_map(fn (TranslationTask $task) => $task->status(), $tasks)),
        ];
    }

    /**
     * Entry IDs of one page and the scope's size.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: list<string>, 1: int}
     */
    private function page(array $input, int $offset, int $limit): array
    {
        $frozen = $this->run?->setup['targets'] ?? null;
        $ids = \is_array($input['ids'] ?? null) ? array_values(array_filter($input['ids'], 'is_string')) : null;

        if (\is_array($frozen)) {
            if ($frozen['type'] !== AiChangesetItem::TARGET_CONTENT) {
                throw new ToolError('This run works on selected entries that are not content.');
            }
            $ids = $ids === null ? $frozen['ids'] : array_values(array_intersect($ids, $frozen['ids']));
        }

        if ($ids !== null) {
            return [\array_slice($ids, $offset, $limit), \count($ids)];
        }

        $query = Content::query()
            ->whereNull('i18n_parent_id')
            ->whereNull('deleted_at')
            ->where('language_iso', $this->space->settings->getDefaultLanguage())
            ->when(\is_string($input['block'] ?? null), fn ($q) => $q->whereHas('block', fn ($b) => $b->where('slug', $input['block'])))
            ->when(\is_string($input['parent_id'] ?? null), fn ($q) => $q->where('parent_id', $input['parent_id']))
            ->when(trim((string) ($input['query'] ?? '')) !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes(trim((string) $input['query']), '%_\\').'%'));

        return [
            (clone $query)->orderBy('name')->orderBy('id')->offset($offset)->limit($limit)->pluck('id')->all(),
            $query->count(),
        ];
    }
}
