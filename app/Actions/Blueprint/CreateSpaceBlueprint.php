<?php

namespace App\Actions\Blueprint;

use App\Http\Requests\SpaceBlueprint\StoreSpaceBlueprintRequest;
use App\Models\Management\Space;
use App\Models\Management\SpaceBlueprint;
use App\Models\Management\Team;
use App\Models\Space\AssetFolder;
use App\Models\Space\AssetTag;
use App\Models\Space\Block;
use App\Models\Space\BlockFolder;
use App\Models\Space\BlockTag;
use App\Models\Space\BlockTemplate;
use App\Models\Space\DataSource;
use App\Models\User;

class CreateSpaceBlueprint
{
    private const TABLE_MODEL_MAP = [
        'blocks' => Block::class,
        'block_folders' => BlockFolder::class,
        'block_tags' => BlockTag::class,
        'asset_folders' => AssetFolder::class,
        'asset_tags' => AssetTag::class,
        'data_sources' => DataSource::class,
        'block_templates' => BlockTemplate::class,
    ];

    public function execute(array $data, Team $team, User $creator, ?Space $sourceSpace = null): SpaceBlueprint
    {
        $tables = $data['tables'] ?? [];
        $settings = $data['settings'] ?? [];
        $sourceSpaceId = $data['source_space_id'] ?? null;

        unset($data['tables'], $data['source_space_id']);

        if (!$sourceSpace && !empty($sourceSpaceId)) {
            $sourceSpace = Space::find($sourceSpaceId);
        }

        $blueprintData = [];

        if ($sourceSpace) {
            if ($sourceSpace->team_id !== $team->id) {
                abort(422, __('validation.blueprint.source_space_team_mismatch'));
            }

            $settings = array_replace_recursive($sourceSpace->settings->toArray(), $settings);
            $blueprintData = $this->collectData($sourceSpace, $tables);
        }

        return SpaceBlueprint::create([
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? null,
            'description' => $data['description'] ?? null,
            'settings' => $settings ?: null,
            'data' => $blueprintData ?: null,
            'team_id' => $team->id,
            'created_by_id' => $creator->id,
        ]);
    }

    private function collectData(Space $sourceSpace, array $tables): array
    {
        $selectedTables = array_values(array_intersect($tables, StoreSpaceBlueprintRequest::TABLES));
        if (empty($selectedTables)) {
            return [];
        }

        $previousSpace = app()->offsetExists('currentSpace') ? app()->get('currentSpace') : null;
        app()->offsetSet('currentSpace', $sourceSpace);

        try {
            $data = [];
            foreach ($selectedTables as $table) {
                $modelClass = self::TABLE_MODEL_MAP[$table] ?? null;
                if (!$modelClass) {
                    continue;
                }

                $data[$table] = $modelClass::query()
                    ->get()
                    ->map(fn($model) => $model->toArray())
                    ->all();
            }

            return $data;
        } finally {
            if ($previousSpace) {
                app()->offsetSet('currentSpace', $previousSpace);
            } else {
                app()->offsetUnset('currentSpace');
            }
        }
    }
}
