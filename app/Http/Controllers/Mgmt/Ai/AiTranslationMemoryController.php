<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\BulkTranslationUnitsRequest;
use App\Http\Resources\Management\Ai\TranslationUnitResource;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\TranslationUnit;
use App\Services\Ai\Translation\TranslationMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The space's translation memory, one unit per translated field of a published
 * translation. Runs reuse an exact match without a provider call, so this is
 * where a wrong pair is found and corrected or removed.
 *
 * Publishing a translation records its units again from what it holds: a
 * correction or deletion lasts until that translation is next published, so a
 * wrong text has to be fixed in the entry too.
 */
class AiTranslationMemoryController extends Controller
{
    /**
     * Units of the space, most recently recorded or corrected first.
     *
     * @apiQuery source_locale string
     * @apiQuery target_locale string
     * @apiQuery search string Part of the source or target text.
     * @apiQuery origin string `human` or `ai_accepted`.
     * @apiQuery content_id string A translation entry, or the source entry for all its translations.
     * @apiQuery page integer
     * @apiQuery per_page integer At most 200.
     *
     * @apiResponse 200 @TranslationUnitsPage
     */
    public function index(Request $request, Space $space): AnonymousResourceCollection
    {
        $this->authorizeSpace($space, 'ai.view');
        $filters = $request->validate([
            'source_locale' => ['sometimes', 'nullable', 'string', 'max:20'],
            'target_locale' => ['sometimes', 'nullable', 'string', 'max:20'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'origin' => ['sometimes', 'nullable', Rule::in([TranslationUnit::ORIGIN_HUMAN, TranslationUnit::ORIGIN_AI_ACCEPTED])],
            'content_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $units = TranslationUnit::query()
            ->with('content:id,name,full_slug,i18n_parent_id,deleted_at')
            ->when($filters['source_locale'] ?? null, fn ($q, string $locale) => $q->where('source_locale', strtolower($locale)))
            ->when($filters['target_locale'] ?? null, fn ($q, string $locale) => $q->where('target_locale', strtolower($locale)))
            ->when($filters['origin'] ?? null, fn ($q, string $origin) => $q->where('origin', $origin))
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($q) => $q->where('source_text', 'like', $like)->orWhere('target_text', 'like', $like));
            })
            ->when($filters['content_id'] ?? null, fn ($q, string $id) => $q->whereIn(
                'content_id',
                Content::withTrashed()->where('id', $id)->orWhere('i18n_parent_id', $id)->select('id'),
            ))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 50, 200));

        return TranslationUnitResource::collection($units);
    }

    /**
     * Correct a unit's target text. The pair counts as written by a person (`origin: human`) and, being the newest, wins an exact match at once.
     *
     * The translation entry is not changed. Its next publish records what it then holds, so fix the entry as well.
     *
     * @apiResponse 200 data:@TranslationUnitResource
     * @apiResponse 404 @Error Unknown unit.
     */
    public function update(Request $request, Space $space, string $unit, TranslationMemory $memory): TranslationUnitResource
    {
        $this->authorizeSpace($space, 'ai.manage');
        $target = $request->validate(['target_text' => ['required', 'string', 'max:20000']])['target_text'];

        return new TranslationUnitResource($memory->correct(TranslationUnit::query()->findOrFail($unit), $target)->load('content'));
    }

    /**
     * Delete a unit. A later publish of its translation records the pair again from what the entry holds.
     *
     * @apiResponse 404 @Error Unknown unit.
     */
    public function destroy(Space $space, string $unit): JsonResponse
    {
        $this->authorizeSpace($space, 'ai.manage');
        TranslationUnit::query()->findOrFail($unit)->delete();

        return response()->json(null, 204);
    }

    /**
     * Delete selected units. An unknown unit refuses the whole request.
     *
     * @apiResponse 200 data:@TranslationUnitsBulkResult
     * @apiResponse 422 @ValidationError Under `unit_ids` for units this space does not have.
     */
    public function bulk(BulkTranslationUnitsRequest $request, Space $space): JsonResponse
    {
        $this->authorizeSpace($space, 'ai.manage');
        $ids = array_values(array_unique($request->validated('unit_ids')));
        $unknown = array_values(array_diff($ids, TranslationUnit::query()->whereKey($ids)->pluck('id')->all()));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['unit_ids' => ['These units do not exist: '.implode(', ', $unknown).'.']]);
        }

        return response()->json(['data' => ['deleted' => TranslationUnit::query()->whereKey($ids)->delete()]]);
    }
}
