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
use App\Support\SpaceContext;

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

    /**
     * The caller is responsible for authorizing both the team and the source
     * space.
     */
    public function execute(array $data, Team $team, User $creator, ?Space $sourceSpace = null): SpaceBlueprint
    {
        $tables = $data['tables'] ?? [];
        $settings = $data['settings'] ?? [];

        $blueprintData = [];

        if ($sourceSpace) {
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

        $restore = SpaceContext::enter($sourceSpace);

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
            $restore();
        }
    }
}
