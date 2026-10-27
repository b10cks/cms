<?php

namespace App\Services\Ai\Tools;

use App\Models\Management\Space;
use App\Models\User;
use App\Services\Ai\Changesets\ChangeTargets;
use App\Services\Ai\Changesets\EntryScope;
use App\Services\Ai\Contracts\AiToolInterface;
use App\Services\Ai\Run\ToolError;
use App\Services\Auth\AuthorizationService;

/** Resolves a scope to entry IDs for bulk work. */
class FindEntriesTool implements AiToolInterface
{
    public function __construct(
        private readonly EntryScope $scope,
        private readonly ChangeTargets $targets,
        private readonly AuthorizationService $auth,
        private readonly Space $space,
        private readonly User $actor,
    ) {}

    public function name(): string
    {
        return 'find_entries';
    }

    public function description(): string
    {
        return 'Finds entries to work on: content by block slug, parent folder, language or a locale with no translation yet; '
            .'data entries of a data source, optionally missing a dimension; assets in a folder, optionally missing a field (`<locale>.<field>`). Returns IDs and names.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_type' => ['type' => 'string', 'enum' => ChangeTargets::TYPES],
                'query' => ['type' => 'string', 'description' => 'Part of the name, key or filename.'],
                'block' => ['type' => 'string', 'description' => 'Content: block slug.'],
                'parent_id' => ['type' => 'string', 'description' => 'Content: parent folder ID.'],
                'language' => ['type' => 'string', 'description' => 'Content: language of the rows.'],
                'missing_locale' => ['type' => 'string', 'description' => 'Content: canonical entries without a translation in this locale.'],
                'data_source' => ['type' => 'string', 'description' => 'Data entries: data source ID or slug.'],
                'missing_dimension' => ['type' => 'string', 'description' => 'Data entries: dimension key with no value.'],
                'folder_id' => ['type' => 'string', 'description' => 'Assets: folder ID.'],
                'missing_field' => ['type' => 'string', 'description' => 'Assets: `<locale>.<field>` with no value.'],
                'limit' => ['type' => 'integer', 'description' => 'At most this many entries (default 50, max 500).'],
            ],
            'required' => ['target_type'],
        ];
    }

    public function execute(array $input): mixed
    {
        $type = (string) ($input['target_type'] ?? '');

        if (! $this->auth->canInSpace($this->actor, $this->space, $this->targets->for($type)->viewAbility())) {
            throw new ToolError('You do not have access to these entries.');
        }

        $entries = $this->scope->resolve($type, $input, (int) ($input['limit'] ?? 50));

        return [
            'count' => \count($entries),
            'entries' => array_map(fn (array $entry) => ['id' => $entry['id'], 'name' => $entry['name']], $entries),
        ];
    }
}
