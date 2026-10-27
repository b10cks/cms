<?php

namespace App\Services\Ai\Run;

use App\Models\Management\Space;
use App\Models\Management\SpaceAiConfig;
use App\Models\User;
use App\Services\Ai\Skills\ResolvedSkill;

/** Everything needed to start a run. */
final readonly class StartRun
{
    /** Instructions for a run over a confirmed selection of entries. */
    public const TARGET_INSTRUCTIONS = 'The user selected the entries listed in the context under `targets`. Work through each of them: read it with get_entry '
        .'(pass target_type), then propose your changes with propose_update. Touch no other entries. Finish with a short summary of what you proposed.';

    /**
     * @param  array<string, mixed>  $context  screen context; untrusted, fenced in the prompt
     * @param  string  $instructions  trusted, server-built additions to the skill instructions
     * @param  list<array<string, mixed>>  $parts  extra user content parts (images), server-side only
     * @param  array{type: string, ids: list<string>}|null  $targets  confirmed bulk selection; write tools may touch only these
     * @param  float|null  $maxUsd  USD cap for this run, replacing the configured one (schedules)
     * @param  bool  $opensThread  a user-started run without `conversationId` opens a new chat thread
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
        public ?array $targets = null,
        public ?float $maxUsd = null,
        public bool $opensThread = false,
    ) {}
}
