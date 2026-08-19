<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Enums\ContentTranslationImportMode;
use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\ContentMassEditFilter;
use App\Http\Requests\Content\MassEditRowsRequest;
use App\Http\Requests\Content\MassEditSaveRequest;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Services\ContentData\ContentTranslationApplier;
use App\Services\ContentData\ContentTranslationExtractor;
use Illuminate\Http\JsonResponse;

class ContentMassEditController extends Controller
{
    /**
     * List the translatable fields available for mass editing, aggregated across all
     * block schemas of the space — including fields that only exist inside nested
     * `blocks` fields.
     */
    public function fields(Space $space, ContentTranslationExtractor $extractor): JsonResponse
    {
        $this->authorizeSpace($space, 'content.view');

        $blocks = Block::query()->get(['id', 'name', 'slug'])->keyBy('id');
        $fields = [];

        foreach ($extractor->schemaFieldsByBlock() as $blockId => $blockFields) {
            $block = $blocks->get($blockId);

            if ($block === null) {
                continue;
            }

            foreach ($blockFields as $fieldKey => $field) {
                if (! isset($fields[$fieldKey])) {
                    $fields[$fieldKey] = [
                        'key' => $fieldKey,
                        'type' => $field['type'],
                        'label' => $field['label'],
                        'translatable' => $field['translatable'],
                        'blocks' => [],
                    ];
                } elseif ($field['translatable'] && ! $fields[$fieldKey]['translatable']) {
                    // A field key counts as translatable if any block marks it so;
                    // that block's label/type also describe the key best.
                    $fields[$fieldKey]['type'] = $field['type'];
                    $fields[$fieldKey]['label'] = $field['label'];
                    $fields[$fieldKey]['translatable'] = true;
                }

                $fields[$fieldKey]['blocks'][] = [
                    'id' => $block->id,
                    'name' => $block->name,
                    'slug' => $block->slug,
                ];
            }
        }

        ksort($fields);

        return response()->json(['data' => array_values($fields)]);
    }

    /**
     * Paginated grid rows: one translation document per canonical content whose
     * block schema carries at least one of the selected fields.
     */
    public function rows(
        MassEditRowsRequest $request,
        Space $space,
        ContentTranslationExtractor $extractor,
    ): JsonResponse {
        $this->authorizeSpace($space, 'content.view');

        $fieldKeys = $request->getFieldKeys();
        $blockIds = $extractor->blockIdsWithFields($fieldKeys);

        $paginator = Content::query()
            ->filter(new ContentMassEditFilter($request->all()))
            ->with('block')
            ->whereNull('i18n_parent_id')
            ->whereIn('block_id', $blockIds)
            // Without an explicit order the DB may hand back rows in any order, which
            // makes paging skip or repeat items. Keep it in sync with the grid export.
            ->orderBy('id')
            ->paginate($this->perPage($request, 25));

        $documents = $extractor->extractForContents(
            $space,
            $paginator->getCollection(),
            $fieldKeys,
            $request->getLanguageFilter(),
            includeEmptyUnits: true,
            includeNonTranslatable: true,
        );

        return response()->json([
            'data' => array_map(static fn ($document): array => [
                'content_id' => $document->contentId,
                'name' => $document->name,
                'slug' => $document->slug,
                'full_slug' => $document->fullSlug,
                'source_language' => $document->sourceLanguage,
                'languages' => $document->languages,
                'units' => array_map(static fn ($unit): array => [
                    'id' => $unit->id,
                    'field' => $unit->fieldKey,
                    'type' => $unit->type,
                    'label' => $unit->label,
                    'note' => $unit->note,
                    'source' => $unit->source,
                    'targets' => $unit->targets,
                    'translatable' => $unit->translatable,
                ], $document->units),
            ], $documents),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'links' => $paginator->linkCollection()->toArray(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Apply a delta of edited cells. Values are keyed by the same stable unit ids
     * the rows endpoint returns; only provided cells are written.
     */
    public function save(
        MassEditSaveRequest $request,
        Space $space,
        ContentTranslationApplier $applier,
    ): JsonResponse {
        $this->authorizeSpace($space, 'content.manage');

        $mode = $request->getMode();

        if ($mode === ContentTranslationImportMode::PUBLISH) {
            $this->authorizeSpace($space, 'content.publish');
        }

        $result = $applier->apply(
            $space,
            $request->validated('documents'),
            $mode,
            $request->shouldCreateMissing(),
            auth()->user(),
            allowSourceEdits: true,
            applyEmpty: true,
        );

        return response()->json($result->toArray());
    }
}
