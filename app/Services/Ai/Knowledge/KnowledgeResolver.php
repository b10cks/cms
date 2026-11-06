<?php

namespace App\Services\Ai\Knowledge;

use App\Enums\AiKnowledgeType;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryConcept;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\AiKnowledgeRevision;
use App\Models\Management\Space;
use App\Models\Space\AiOperation;
use App\Models\Space\AiPolicySnapshot;
use App\Models\Space\AiRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves knowledge for a space through space, owning team and ancestor teams
 * (nearest first). Only enabled, published documents count; the nearest document
 * with a slug and the nearest wording per locale shadow the rest.
 */
class KnowledgeResolver
{
    /** @var array<string, PolicySnapshot> immutable, so safe to reuse within this resolver's life */
    private array $loaded = [];

    public function __construct(private readonly AiLayers $layers) {}

    /**
     * @return Collection<int, AiKnowledgeDocument>
     */
    public function documents(Space $space, ?AiKnowledgeType $type = null): Collection
    {
        $teamIds = $this->layers->teamIds($space);
        $rank = $this->layers->rank($teamIds);

        return AiKnowledgeDocument::query()
            ->inLayers($space->id, $teamIds)
            ->where('enabled', true)
            ->whereNotNull('published_revision_id')
            ->when($type, fn ($query) => $query->where('type', $type->value))
            ->get()
            ->sortBy(fn (AiKnowledgeDocument $doc) => $rank($doc->scope, $doc->space_id ?? $doc->team_id))
            ->unique('slug')
            ->sortBy('title')
            ->values();
    }

    /**
     * The rows of every enabled glossary a space uses. A language whose wording a glossary
     * in a nearer layer already holds drops out of this row, and a row with no language
     * left drops out whole.
     *
     * @return list<GlossaryConcept>
     */
    public function glossaryConcepts(Space $space): array
    {
        $glossaries = $this->glossaries($space);

        if ($glossaries->isEmpty()) {
            return [];
        }

        $order = $glossaries->pluck('id')->flip();
        $seen = [];
        $concepts = [];

        AiGlossaryConcept::query()
            ->whereIn('glossary_id', $glossaries->pluck('id'))
            ->with('terms')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (AiGlossaryConcept $concept) => $order[$concept->glossary_id])
            ->each(function (AiGlossaryConcept $model) use (&$seen, &$concepts) {
                $concept = GlossaryConcept::fromModel($model);
                $kept = array_filter($concept->languages, static function (GlossaryWording $language) use (&$seen, $concept) {
                    $key = $language->locale.'|'.mb_strtolower($language->name());
                    $seen[$key] ??= $concept->glossaryId;

                    return $seen[$key] === $concept->glossaryId;
                });

                if ($kept !== []) {
                    $concepts[] = new GlossaryConcept($kept, $concept->keepExact, $concept->note, $concept->id, $concept->glossaryId, $concept->caseSensitive);
                }
            });

        return $concepts;
    }

    /**
     * The policy as it stands now, unstored: for callers without a run, like MCP.
     * One read transaction, so a publication cannot land between its reads.
     */
    public function livePolicy(Space $space): PolicySnapshot
    {
        return DB::connection((new AiGlossary)->getConnectionName())->transaction(function () use ($space) {
            $documents = $this->documents($space)->load('publishedRevision:id,document_id,revision,hash');
            $styles = AiKnowledgeRevision::query()
                ->whereIn('id', $documents->where('type', AiKnowledgeType::Style)->pluck('published_revision_id'))
                ->pluck('markdown', 'id');

            return PolicySnapshot::fromParts([
                'concepts' => array_map(static fn (GlossaryConcept $concept) => $concept->toArray(), $this->glossaryConcepts($space)),
                'documents' => $documents->map(fn (AiKnowledgeDocument $document) => [
                    'id' => $document->id,
                    'slug' => $document->slug,
                    'title' => $document->title,
                    'type' => $document->type->value,
                    'locale' => $document->locale,
                    'revision_id' => $document->published_revision_id,
                    'revision' => $document->publishedRevision->revision,
                    'hash' => $document->publishedRevision->hash,
                    ...(isset($styles[$document->published_revision_id]) ? ['markdown' => $styles[$document->published_revision_id]] : []),
                ])->all(),
            ]);
        });
    }

    /** Stores the current policy, once per distinct content, and returns it pinned. */
    public function capturePolicy(Space $space): PolicySnapshot
    {
        $snapshot = $this->livePolicy($space);
        $identity = ['space_id' => $space->id, 'fingerprint' => $snapshot->fingerprint];

        $id = AiPolicySnapshot::query()->where($identity)->value('id');

        if ($id === null) {
            try {
                $id = AiPolicySnapshot::query()->create([...$identity, 'version' => PolicySnapshot::VERSION, 'payload' => $snapshot->toPayload()])->id;
            } catch (UniqueConstraintViolationException) {
                $id = AiPolicySnapshot::query()->where($identity)->value('id');
            }
        }

        return $snapshot->withId($id);
    }

    /**
     * The policy a run works under: the snapshot it pinned at start. A run from
     * before snapshots has none and reads the live state, as it always did.
     * No run means the live state too.
     */
    public function policy(Space $space, ?AiRun $run = null): PolicySnapshot
    {
        $id = $run?->setup['policy']['id'] ?? null;

        return $id !== null ? $this->loadPolicy($id) : $this->livePolicy($space);
    }

    /** One frozen policy by ID, for work that outlives the run that pinned it. */
    public function snapshot(string $id): PolicySnapshot
    {
        return $this->loadPolicy($id);
    }

    /**
     * The policy a starting run pins: its operation's, captured at approval, or the
     * current one. A planning run and an operation approved before snapshots existed
     * have none to inherit, so they capture per run.
     *
     * @param  array{id: string}|null  $operation
     */
    public function policyForStart(Space $space, ?array $operation): PolicySnapshot
    {
        $id = $operation !== null ? AiOperation::query()->whereKey($operation['id'])->value('policy_snapshot_id') : null;

        return $id !== null ? $this->loadPolicy($id) : $this->capturePolicy($space);
    }

    /**
     * Pins the operation to the current policy, once. Call at approval, in the same
     * write as the approved plan: batches and resumes only ever read this.
     */
    public function pinOperationPolicy(AiOperation $operation): PolicySnapshot
    {
        if ($operation->policy_snapshot_id !== null) {
            return $this->loadPolicy($operation->policy_snapshot_id);
        }

        $snapshot = $this->capturePolicy($operation->space);
        $operation->forceFill(['policy_snapshot_id' => $snapshot->id]);

        return $snapshot;
    }

    /**
     * The frozen descriptors a run's reference tools answer with. Null for a run
     * without a pinned policy and for callers without a run: they name documents
     * by the live row, as they always did.
     *
     * @return array<string, array{document_id: string, slug: string, title: string, locale: string|null, revision: int, hash: string}>|null
     */
    public function referenceSources(?AiRun $run): ?array
    {
        $id = $run?->setup['policy']['id'] ?? null;

        return $id !== null ? $this->loadPolicy($id)->referenceSources() : null;
    }

    private function loadPolicy(string $id): PolicySnapshot
    {
        if (isset($this->loaded[$id])) {
            return $this->loaded[$id];
        }

        // A missing row must stop the work, not fall back to the live state.
        $row = AiPolicySnapshot::query()->findOrFail($id);

        return $this->loaded[$id] = PolicySnapshot::fromPayload($row->payload, $row->id, $row->fingerprint);
    }

    /**
     * @return Collection<int, AiGlossary> nearest layer first
     */
    private function glossaries(Space $space): Collection
    {
        $teamIds = $this->layers->teamIds($space);
        $rank = $this->layers->rank($teamIds);

        return AiGlossary::query()
            ->inLayers($space->id, $teamIds)
            ->where('enabled', true)
            ->get()
            ->sortBy(fn (AiGlossary $glossary) => $rank($glossary->scope, $glossary->space_id ?? $glossary->team_id))
            ->values();
    }
}
