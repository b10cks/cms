<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ContentTranslationAssistRequest;
use App\Http\Requests\Content\ContentTranslationStatusRequest;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Translation\TranslationAssist;
use App\Services\Ai\Translation\TranslationStatus;
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
}
