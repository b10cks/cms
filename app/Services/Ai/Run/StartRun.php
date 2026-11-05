<?php

namespace App\Services\Ai\Run;

use App\Enums\AiReasoningEffort;
use App\Models\Management\Space;
use App\Models\Management\SpaceAiConfig;
use App\Models\Space\AiAttachment;
use App\Models\User;
use App\Services\Ai\Skills\ResolvedSkill;

/** Everything needed to start a run. */
final readonly class StartRun
{
    /** Instructions for a run over a confirmed selection of entries; the skill's write tools decide how changes are proposed. */
    public const TARGET_INSTRUCTIONS = 'The user selected the entries listed in the context under `targets`. Work through each of them: read it with the read tools you have '
        .'(get_entry takes target_type), then propose your changes with the write tools you have. Touch no other entries. Finish with a short summary of what you proposed.';

    /**
     * @param  array<string, mixed>  $context  screen context; untrusted, fenced in the prompt
     * @param  string  $instructions  trusted, server-built additions to the skill instructions
     * @param  list<array<string, mixed>>  $parts  extra user content parts (images), server-side only
     * @param  list<AiAttachment>  $attachments  the actor's unsent files; images become image parts, documents fenced text
     * @param  array{type: string, ids: list<string>}|null  $targets  confirmed bulk selection; write tools may touch only these
     * @param  float|null  $maxUsd  USD cap for this run, replacing the configured one (schedules)
     * @param  bool  $opensThread  a user-started run without `conversationId` opens a new chat thread
     * @param  AiReasoningEffort|null  $reasoningEffort  the user's pick; null uses the config's level
     * @param  array{id: string, phase: string, batch?: int, plan_hash?: string}|null  $operation  the operation this run plans or works a batch of
     * @param  array{locales: list<string>, include: list<string>, metadata?: array<string, list<string>>}|null  $translation  approved translation plan, frozen as `setup.translation`; the translation tools enforce it
     * @param  array<string, mixed>|null  $assets  approved asset plan (AssetPlan::toArray()), frozen as `setup.plan.assets`; the asset tools enforce it
     * @param  string|null  $policySnapshotId  work under this frozen policy instead of the operation's or the current one
     * @param  array<string, string>|null  $outputLocales  output key prefix => locale, for one JSON answer across languages: the prompt carries wording per language, and the caller checks each value in its own
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
        public array $attachments = [],
        public ?array $targets = null,
        public ?float $maxUsd = null,
        public bool $opensThread = false,
        public ?AiReasoningEffort $reasoningEffort = null,
        public ?array $operation = null,
        public ?array $translation = null,
        public ?array $assets = null,
        public ?string $policySnapshotId = null,
        public ?array $outputLocales = null,
    ) {}
}
