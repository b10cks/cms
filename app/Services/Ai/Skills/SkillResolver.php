<?php

namespace App\Services\Ai\Skills;

use App\Enums\AiScope;
use App\Models\Management\AiSkill;
use App\Models\Management\AiSkillRevision;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Ai\Knowledge\AiLayers;

/**
 * Resolves skills for a space: space, owning team, ancestor teams (nearest
 * first), then built-ins. The nearest enabled, published skill with a name
 * shadows the rest; a disabled one falls through to the next layer.
 */
class SkillResolver
{
    public function __construct(
        private readonly BuiltinSkills $builtins,
        private readonly SkillParser $parser,
        private readonly AiLayers $layers,
    ) {}

    /**
     * Resolution for a team (its own and ancestor layers) works the same way
     * without the space layer; settings pages use it to show shadowing.
     *
     * @return array<string, ResolvedSkill>
     */
    public function resolve(Space|Team $owner): array
    {
        $teamIds = $this->layers->teamIds($owner);
        $rank = $this->layers->rank($teamIds);

        $rows = AiSkill::query()
            ->inLayers($owner instanceof Space ? $owner->id : null, $teamIds)
            ->where('enabled', true)
            ->whereNotNull('published_revision_id')
            ->with('publishedRevision')
            ->get()
            ->sortBy(fn (AiSkill $skill) => $rank($skill->scope, $skill->space_id ?? $skill->team_id));

        /** @var array<string, ResolvedSkill> $resolved */
        $resolved = [];
        $shadows = [];

        foreach ($rows as $skill) {
            $revision = $skill->publishedRevision;
            if (! $revision) {
                continue;
            }

            $owner = $skill->space_id ?? $skill->team_id;
            if (isset($resolved[$skill->name])) {
                $shadows[$skill->name][] = ['source' => $skill->scope->value, 'owner_id' => $owner];

                continue;
            }

            $resolved[$skill->name] = new ResolvedSkill(
                $this->parser->parse($revision->manifest['files']),
                $skill->scope,
                $owner,
                $skill->id,
                $revision->id,
            );
        }

        foreach ($this->builtins->all() as $name => $manifest) {
            if (isset($resolved[$name])) {
                $shadows[$name][] = ['source' => AiScope::System->value, 'owner_id' => null];

                continue;
            }

            $resolved[$name] = new ResolvedSkill($manifest, AiScope::System);
        }

        foreach ($shadows as $name => $list) {
            $skill = $resolved[$name];
            $resolved[$name] = new ResolvedSkill($skill->manifest, $skill->source, $skill->ownerId, $skill->skillId, $skill->revisionId, $list);
        }

        ksort($resolved);

        return $resolved;
    }

    /**
     * Skills offered on a surface, optionally filtered by block slug.
     *
     * @return list<ResolvedSkill>
     */
    public function forSurface(Space $space, string $surface, ?string $block = null): array
    {
        return array_values(array_filter(
            $this->resolve($space),
            static fn (ResolvedSkill $skill) => $skill->manifest->matches($surface, $block),
        ));
    }

    /**
     * Load a frozen skill reference exactly as a run saw it.
     *
     * @param  array{name: string, source: string, revision_id: string|null}  $frozen
     */
    public function load(array $frozen): ?SkillManifest
    {
        if ($frozen['source'] === AiScope::System->value) {
            return $this->builtins->get($frozen['name']);
        }

        $revision = $frozen['revision_id'] ? AiSkillRevision::query()->find($frozen['revision_id']) : null;

        return $revision ? $this->parser->parse($revision->manifest['files']) : null;
    }
}
