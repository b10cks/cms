<?php

namespace App\Services\Ai\Knowledge;

use App\Enums\AiKnowledgeType;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryTerm;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\Space;
use Illuminate\Support\Collection;

/**
 * Resolves knowledge for a space through space, owning team and ancestor teams
 * (nearest first). Only enabled, published documents count; the nearest
 * document with a slug and the nearest term per locale shadow the rest.
 */
class KnowledgeResolver
{
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
     * @return list<GlossaryTerm>
     */
    public function glossaryTerms(Space $space): array
    {
        $teamIds = $this->layers->teamIds($space);
        $rank = $this->layers->rank($teamIds);

        $glossaries = AiGlossary::query()
            ->inLayers($space->id, $teamIds)
            ->where('enabled', true)
            ->get()
            ->sortBy(fn (AiGlossary $glossary) => $rank($glossary->scope, $glossary->space_id ?? $glossary->team_id))
            ->values();

        if ($glossaries->isEmpty()) {
            return [];
        }

        $order = $glossaries->pluck('id')->flip();
        $terms = [];

        AiGlossaryTerm::query()
            ->whereIn('glossary_id', $glossaries->pluck('id'))
            ->get()
            ->sortBy(fn (AiGlossaryTerm $term) => $order[$term->glossary_id])
            ->each(function (AiGlossaryTerm $model) use (&$terms) {
                $term = GlossaryTerm::fromModel($model);
                $terms[$term->key()] ??= $term;
            });

        return array_values($terms);
    }
}
