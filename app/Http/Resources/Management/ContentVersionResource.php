<?php

namespace App\Http\Resources\Management;

use App\Models\Space\ContentVersion;
use App\Services\Content\Diff\ArrayDiffService;
use App\Services\Content\Diff\DiffResult;
use App\Services\Content\Diff\SchemaAwareDiffService;
use App\Services\Content\Schema\ContentSchemaValueMerger;
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

        // The merger's slug lookup is cached per request, so repeated
        // resource instances share one blocks query.
        $merger = app(ContentSchemaValueMerger::class);

        return app(SchemaAwareDiffService::class)->diff(
            $old,
            $new,
            $schema,
            static fn (string $slug): array => $merger->resolveBlockSchema($slug)
        );
    }
}
