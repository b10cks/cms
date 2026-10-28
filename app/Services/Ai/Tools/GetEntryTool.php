<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\User;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\ToolError;
use App\Services\Auth\AuthorizationService;

/** Reads one entry's current (draft) state, with the version to pin proposals to. */
class GetEntryTool implements AiToolInterface
{
    public function __construct(
        private readonly ChangeTargets $targets,
        private readonly AuthorizationService $auth,
        private readonly Space $space,
        private readonly User $actor,
    ) {}

    public function name(): string
    {
        return 'get_entry';
    }

    public function description(): string
    {
        return 'Reads one entry by ID. Content returns its name, slug, block, version_id and the field values of its current draft; '
            .'data entries return `value` and `dimensions`; assets return their metadata `fields` per locale; '
            .'blocks (by ID or slug) return their name, slug, type, description, icon, color, `schema` (field definitions by key) and `editor` (the page layout).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The entry ID (ULID).'],
                'target_type' => ['type' => 'string', 'enum' => ChangeTargets::PROPOSAL_TYPES, 'description' => 'Defaults to content.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $input): mixed
    {
        $type = (string) ($input['target_type'] ?? AiChangesetItem::TARGET_CONTENT);
        $target = $this->targets->for($type);

        if (! $this->auth->canInSpace($this->actor, $this->space, $target->viewAbility())) {
            throw new ToolError('You do not have access to this information.');
        }

        $snapshot = $target->find((string) ($input['id'] ?? '')) ?? throw new ToolError('No entry with this ID.');
        $model = $snapshot->model;

        if ($model instanceof Content) {
            return [
                'id' => $model->id,
                'name' => $model->name,
                'slug' => $model->slug,
                'full_slug' => $model->full_slug,
                'language' => $model->language_iso,
                'block' => $model->block?->slug,
                'version_id' => $snapshot->versionId,
                'content' => $snapshot->document,
            ];
        }

        if ($model instanceof Block) {
            return ['id' => $model->id, 'target_type' => $type, 'slug' => $model->slug, ...$snapshot->document];
        }

        return ['id' => $model->getKey(), 'target_type' => $type, 'name' => $snapshot->label, ...$snapshot->document];
    }
}
