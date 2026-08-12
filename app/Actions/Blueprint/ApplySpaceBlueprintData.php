<?php

namespace App\Actions\Blueprint;

use App\Models\Management\Space;
use App\Models\Management\SpaceBlueprint;
use App\Models\Settings;
use App\Models\Space\AssetFolder;
use App\Models\Space\AssetTag;
use App\Models\Space\Block;
use App\Models\Space\BlockFolder;
use App\Models\Space\BlockTag;
use App\Models\Space\BlockTemplate;
use App\Models\Space\DataSource;
use App\Support\SpaceContext;
use Illuminate\Database\Eloquent\Model;

class ApplySpaceBlueprintData
{
    private const TABLE_ORDER = [
        'block_folders',
        'block_tags',
        'blocks',
        'block_templates',
        'asset_folders',
        'asset_tags',
        'data_sources',
    ];

    private const TABLE_MODEL_MAP = [
        'blocks' => Block::class,
        'block_folders' => BlockFolder::class,
        'block_tags' => BlockTag::class,
        'asset_folders' => AssetFolder::class,
        'asset_tags' => AssetTag::class,
        'data_sources' => DataSource::class,
        'block_templates' => BlockTemplate::class,
    ];

    public function execute(SpaceBlueprint $blueprint, Space $space): void
    {
        if (empty($blueprint->data)) {
            return;
        }

        $restore = SpaceContext::enter($space);

        try {
            Model::unguarded(function () use ($blueprint) {
                foreach (self::TABLE_ORDER as $table) {
                    $records = $blueprint->data[$table] ?? [];
                    if (empty($records)) {
                        continue;
                    }

                    $modelClass = self::TABLE_MODEL_MAP[$table] ?? null;
                    if (!$modelClass) {
                        continue;
                    }

                    $modelClass::withoutEvents(function () use ($modelClass, $records) {
                        foreach ($records as $record) {
                            $modelClass::create($this->normalizeRecord($modelClass, $record));
                        }
                    });
                }
            });
        } finally {
            $restore();
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function normalizeRecord(string $modelClass, array $record): array
    {
        /** @var Model $model */
        $model = new $modelClass();

        foreach ($model->getCasts() as $attribute => $cast) {
            if (!array_key_exists($attribute, $record)) {
                continue;
            }

            $record[$attribute] = $this->normalizeCastValue($cast, $record[$attribute]);
        }

        return $record;
    }

    private function normalizeCastValue(mixed $cast, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($cast) && is_subclass_of($cast, Settings::class)) {
            if ($value instanceof Settings) {
                return $value->toArray();
            }

            if (is_array($value)) {
                return $value;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);

                return is_array($decoded) ? $decoded : [];
            }

            return [];
        }

        return $value;
    }
}
