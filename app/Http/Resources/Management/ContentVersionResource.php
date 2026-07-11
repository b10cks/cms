<?php

namespace App\Http\Resources\Management;

use App\Models\Space\Block;
use App\Models\Space\ContentVersion;
use App\Services\Content\Diff\ArrayDiffService;
use App\Services\Content\Diff\DiffResult;
use App\Services\Content\Diff\SchemaAwareDiffService;
use Illuminate\Http\Request;

/**
 * @mixin ContentVersion
 */
class ContentVersionResource extends ContentVersionListResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
                'diff' => $this->getDiff(),
            ];
    }

    protected function getDiff(): DiffResult
    {
        $old = $this->parent->content ?? [];
        $new = $this->content ?? [];

        $schema = $this->contentModel?->block?->schema?->toArray();

        if (empty($schema)) {
            return app(ArrayDiffService::class)->diff($old, $new);
        }

        return app(SchemaAwareDiffService::class)->diff($old, $new, $schema, $this->blockSchemaResolver());
    }

    /**
     * Lazily resolves nested block schemas by slug; the lookup is only
     * loaded when the content actually contains a blocks field.
     */
    protected function blockSchemaResolver(): callable
    {
        $schemasBySlug = null;

        return function (string $slug) use (&$schemasBySlug): array {
            $schemasBySlug ??= Block::query()
                ->select(['slug', 'schema'])
                ->get()
                ->mapWithKeys(static fn (Block $block): array => [
                    $block->slug => $block->schema?->toArray() ?? [],
                ])
                ->all();

            return $schemasBySlug[$slug] ?? [];
        };
    }
}
