<?php

namespace App\Services\Ai\Operations;

use App\Enums\AiOperationKind;
use App\Models\Management\Space;
use App\Models\Management\SpaceAiConfig;
use App\Models\User;
use App\Services\Ai\Skills\ResolvedSkill;

/** Everything needed to freeze a new operation. */
final readonly class CreateOperation
{
    /**
     * @param  array{type: string, ids?: list<string>|null, root_id?: string|null, recursive?: bool, filters?: array<string, mixed>}  $scope
     * @param  string|null  $scheduleId  set when a schedule starts it; its saved settings are the approval
     */
    public function __construct(
        public Space $space,
        public User $actor,
        public AiOperationKind $kind,
        public string $prompt,
        public array $scope,
        public ?ResolvedSkill $skill = null,
        public ?SpaceAiConfig $config = null,
        public ?string $conversationId = null,
        public ?string $scheduleId = null,
    ) {}
}
