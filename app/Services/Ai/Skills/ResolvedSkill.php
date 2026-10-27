<?php

namespace App\Services\Ai\Skills;

use App\Enums\AiScope;

/** A skill as a space sees it, with where it came from and what it shadows. */
final readonly class ResolvedSkill
{
    /**
     * @param  list<array{source: string, owner_id: string|null}>  $shadows
     */
    public function __construct(
        public SkillManifest $manifest,
        public AiScope $source,
        public ?string $ownerId = null,
        public ?string $skillId = null,
        public ?string $revisionId = null,
        public array $shadows = [],
    ) {}

    /**
     * @return array{name: string, source: string, owner_id: string|null, skill_id: string|null, revision_id: string|null}
     */
    public function freeze(): array
    {
        return [
            'name' => $this->manifest->name,
            'source' => $this->source->value,
            'owner_id' => $this->ownerId,
            'skill_id' => $this->skillId,
            'revision_id' => $this->revisionId,
        ];
    }
}
