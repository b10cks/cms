<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ContentBlockTranslationsRequest;
use App\Http\Requests\Content\ContentTranslationAssistRequest;
use App\Http\Requests\Content\ContentTranslationReviewRequest;
use App\Http\Requests\Content\ContentTranslationStatusRequest;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\TranslationSource;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Translation\TranslationAssist;
use App\Services\Ai\Translation\TranslationDestinations;
use App\Services\Ai\Translation\TranslationLineage;
use App\Services\Ai\Translation\TranslationStatus;
use App\Services\Content\BlockTranslations;
use Illuminate\Http\JsonResponse;

/**
 * Translation state for the translation view. Reads saved data only and makes no provider call.
 */
class ContentTranslationController extends Controller
{
    /**
     * Status of each translatable unit of an entry in one locale, keyed by unit ID (`title`, `body.<block id>.text`, `seo.title`): `missing`, `empty`, `stale` with `previous_source`, `same_as_source`, `current`, `inherited` or `not_in_translation`. The entry may be the canonical or any of its translations. Unsaved drafts are not seen.
     *
     * @apiQuery locale string required The target locale.
     *
     * @apiResponse 200 data:@ContentTranslationStatus
     * @apiResponse 422 @Error Unknown locale, the default language, or an entry with no source to translate from.
     */
    public function status(ContentTranslationStatusRequest $request, Space $space, Content $content, TranslationStatus $status): JsonResponse
    {
        $this->authorize('view', [$content, $space]);

        try {
            return response()->json(['data' => $status->of($space, $content->id, $request->validated('locale'))]);
        } catch (ToolError $error) {
            abort(422, $error->getMessage());
        }
    }

    /**
     * Mark the saved text of units as written by AI (`ai`) or reviewed by a person (`reviewed`), as `translation-status` flags them with `review`. Unit IDs are those of `translation-status`; units with no saved text or no source text are skipped. Returns the recomputed status.
     *
     * @apiResponse 200 data:@ContentTranslationStatus
     * @apiResponse 422 @Error Unknown locale, the default language, an entry with no source to translate from, or no translation entry in this locale yet.
     */
    public function review(ContentTranslationReviewRequest $request, Space $space, Content $content, TranslationDestinations $destinations, TranslationLineage $lineage, TranslationStatus $status): JsonResponse
    {
        $this->authorize('update', [$content, $space]);

        try {
            $locale = $request->validated('locale');
            $destination = $destinations->resolve($space, $content->id, $locale);
            if ($destination->translation === null) {
                throw new ToolError("This entry has no translation in \"{$destination->locale}\" yet.");
            }

            $lineage->recordOrigins($destination, $request->validated('ai', []), TranslationSource::ORIGIN_AI);
            $lineage->recordOrigins($destination, $request->validated('reviewed', []), TranslationSource::ORIGIN_REVIEW);

            return response()->json(['data' => $status->of($space, $content->id, $locale)]);
        } catch (ToolError $error) {
            abort(422, $error->getMessage());
        }
    }

    /**
     * What helps translate one unit by hand: similar translation memory pairs and the unit's text in other locales. `text` is the translation being written, by default the saved one; a memory pair that equals it is left out.
     *
     * @apiResponse 200 data:@ContentTranslationAssist
     * @apiResponse 404 @Error The entry has no such unit.
     * @apiResponse 422 @Error Unknown locale, the default language, or an entry with no source to translate from.
     */
    public function assist(ContentTranslationAssistRequest $request, Space $space, Content $content, TranslationAssist $assist): JsonResponse
    {
        $this->authorize('view', [$content, $space]);

        try {
            $data = $assist->for(
                $space,
                $content->id,
                $request->validated('locale'),
                $request->validated('unit'),
                $request->validated('text'),
            );
        } catch (ToolError $error) {
            abort(422, $error->getMessage());
        }

        abort_if($data === null, 404, 'This entry has no such unit.');

        return response()->json(['data' => $data]);
    }

    /**
     * What each translation of an overlay entry stores for the given nested blocks, with the source text each value was written against. The editor takes this along when blocks are copied, and sends it back as `block_translations` when it saves the entry they were pasted into. Empty for a translation, an independent entry, and blocks the saved entry does not hold.
     *
     * @apiQuery ids string[] required IDs of nested blocks of the entry.
     *
     * @apiResponse 200 data:object Block ID => language => `{ item, lineage }`.
     */
    public function blocks(ContentBlockTranslationsRequest $request, Space $space, Content $content, BlockTranslations $blockTranslations): JsonResponse
    {
        $this->authorize('view', [$content, $space]);

        $content->loadMissing(['block', 'current_version']);

        return response()->json(['data' => (object) $blockTranslations->collect($space, $content, $request->validated('ids'))]);
    }
}
