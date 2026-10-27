<?php

namespace App\Services\Ai\Run;

use App\Models\Management\Space;
use App\Models\Management\SpaceAiConfig;
use App\Models\User;
use App\Services\Ai\Skills\ResolvedSkill;

/** Everything needed to start a run. */
final readonly class StartRun
{
    /**
     * @param  array<string, mixed>  $context  screen context; untrusted, fenced in the prompt
     * @param  string  $instructions  trusted, server-built additions to the skill instructions
     * @param  list<array<string, mixed>>  $parts  extra user content parts (images), server-side only
     */
    public function __construct(
        public Space $space,
        public User $actor,
        public string $surface,
        public ?ResolvedSkill $skill = null,
        public string $prompt = '',
        public array $context = [],
        public ?SpaceAiConfig $config = null,
        public ?string $idempotencyKey = null,
        public ?string $conversationId = null,
        public string $instructions = '',
        public array $parts = [],
    ) {}
}
