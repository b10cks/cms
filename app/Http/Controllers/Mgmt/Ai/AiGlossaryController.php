<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\ResolvesAiOwner;
use App\Http\Controllers\Mgmt\Concerns\RespondsWithImportRun;
use App\Http\Requests\Ai\ExportAiGlossaryRequest;
use App\Http\Requests\Ai\ImportAiGlossaryRequest;
use App\Http\Requests\Ai\SaveAiGlossaryRequest;
use App\Http\Requests\Ai\SaveAiGlossaryTermRequest;
use App\Http\Resources\Management\Ai\AiGlossaryResource;
use App\Http\Resources\Management\Ai\AiGlossaryTermResource;
use App\Models\Management\AiGlossary;
use App\Models\Management\AiGlossaryTerm;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Ai\Knowledge\AiLayers;
use App\Services\Ai\Knowledge\Concepts\GlossaryConcepts;
use App\Services\Ai\Knowledge\GlossaryMatcher;
use App\Services\Ai\Knowledge\GlossaryTransfer;
use App\Services\Ai\Knowledge\LegacyGlossaryRows;
use App\Services\ImportExport\ImportRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Glossaries with CSV, XLSX and TBX import and export. The term endpoints are the older
 * shape the published SDK still calls: one language of a row at a time. They read and
 * write the row through the same writer as {@see AiGlossaryConceptRowController}.
 */
class AiGlossaryController extends Controller
{
    use ResolvesAiOwner;
    use RespondsWithImportRun;

    /** `?inherited=1` adds the glossaries of the owning and ancestor teams, read-only for this owner. */
    public function index(Request $request, AiLayers $layers): AnonymousResourceCollection
    {
        $owner = $this->aiOwner($request);
        $this->authorize('viewAny', [AiGlossary::class, $owner]);

        $query = $request->boolean('inherited')
            ? AiGlossary::query()->inLayers($owner instanceof Space ? $owner->id : null, $layers->teamIds($owner))
            : AiGlossary::query()->ownedBy($owner);

        return AiGlossaryResource::collection($query->withCount('concepts')->with(['team:id,name', 'space:id,name'])->orderBy('name')->get());
    }

    public function store(SaveAiGlossaryRequest $request): AiGlossaryResource
    {
        $owner = $this->aiOwner($request);
        $this->authorize('manage', [AiGlossary::class, $owner]);

        $glossary = AiGlossary::create([
            ...AiGlossary::ownerAttributes($owner),
            'name' => $request->input('name'),
            'enabled' => $request->boolean('enabled', true),
            'created_by' => $request->user()->id,
        ]);

        return new AiGlossaryResource($glossary->loadCount('concepts'));
    }

    public function update(SaveAiGlossaryRequest $request, Space|Team|string $owner, string $glossary): AiGlossaryResource
    {
        $model = $this->findGlossary($request, $glossary, 'manage');
        $model->fill($request->validated())->save();

        return new AiGlossaryResource($model->loadCount('concepts'));
    }

    public function destroy(Request $request, Space|Team|string $owner, string $glossary): JsonResponse
    {
        $this->findGlossary($request, $glossary, 'manage')->delete();

        return response()->json(null, 204);
    }

    public function terms(Request $request, Space|Team|string $owner, string $glossary): AnonymousResourceCollection
    {
        $model = $this->readableGlossary($request, $glossary);
        $search = trim((string) $request->query('search', ''));

        return AiGlossaryTermResource::collection(
            AiGlossaryTerm::query()->inGlossary($model->id)
                ->with('concept.terms')
                ->when($search !== '', fn ($query) => $query->where(fn ($found) => $found
                    ->where('preferred', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->orWhereIn('id', $this->bansNamed($model, $search))))
                ->orderBy('preferred')
                ->paginate($this->perPage($request, 50, 200)),
        );
    }

    /**
     * Terms that show a ban's first Avoid wording as their `term`. It sits in JSON, with
     * Unicode escaped, so it is compared here.
     *
     * @return list<string>
     */
    private function bansNamed(AiGlossary $glossary, string $search): array
    {
        return AiGlossaryTerm::query()->inGlossary($glossary->id)->whereNull('preferred')->get(['id', 'forbidden'])
            ->filter(fn (AiGlossaryTerm $term) => mb_stripos((string) ($term->forbidden[0] ?? ''), $search) !== false)
            ->pluck('id')->all();
    }

    /**
     * Create a row from one language in the older term shape.
     *
     * @apiResponse 201 data:@AiGlossaryTermResource
     * @apiResponse 409 @AiGlossaryConceptConflict `wording_conflict`. Nothing changes.
     * @apiResponse 422 @AiGlossaryConceptConflict `language_invalid`, `term_required`, `use_avoided`, or `note_too_long` for a note over 200 characters.
     */
    public function storeTerm(SaveAiGlossaryTermRequest $request, Space|Team|string $owner, string $glossary): JsonResponse
    {
        $model = $this->findGlossary($request, $glossary, 'manage');
        $fields = $request->validated();
        $id = GlossaryConcepts::transaction($model, fn (GlossaryConcepts $concepts) => $concepts->create(LegacyGlossaryRows::input($fields)));

        return (new AiGlossaryTermResource($this->termIn($model, $id, $fields['source_locale'])))->response()->setStatusCode(201);
    }

    /**
     * Change one language of a row in the older term shape.
     *
     * `translations` changes Use only. A language it omits loses its Use and keeps its Avoid and Also written as. A language with neither goes. The row endpoints remove a language outright.
     *
     * @apiResponse 200 data:@AiGlossaryTermResource
     * @apiResponse 409 @AiGlossaryConceptConflict `wording_conflict`, or `language_conflict` when `source_locale` names a language the row already has. Nothing changes.
     * @apiResponse 422 @AiGlossaryConceptConflict `language_invalid`, `term_required`, `use_avoided`, or `note_too_long` for a new or changed note over 200 characters.
     */
    public function updateTerm(SaveAiGlossaryTermRequest $request, Space|Team|string $owner, string $glossary, string $term): AiGlossaryTermResource
    {
        $model = $this->findGlossary($request, $glossary, 'manage');
        $found = AiGlossaryTerm::query()->inGlossary($model->id)->findOrFail($term);
        $fields = $request->validated();
        GlossaryConcepts::transaction($model, fn (GlossaryConcepts $concepts) => $concepts->update($found->concept_id, LegacyGlossaryRows::termChange($concepts, $found->concept_id, $found->id, $fields)));

        return new AiGlossaryTermResource($this->termIn($model, $found->concept_id, $fields['source_locale'] ?? $found->locale));
    }

    /**
     * Delete one language of a row. The last language takes the row with it.
     */
    public function destroyTerm(Request $request, Space|Team|string $owner, string $glossary, string $term): JsonResponse
    {
        $model = $this->findGlossary($request, $glossary, 'manage');
        $found = AiGlossaryTerm::query()->inGlossary($model->id)->findOrFail($term);

        GlossaryConcepts::transaction($model, function (GlossaryConcepts $concepts) use ($found): void {
            $languages = $concepts->rowLanguages($found->concept_id);
            $locale = LegacyGlossaryRows::localeOf($languages, $found->id);

            \count($languages) === 1
                ? $concepts->remove($found->concept_id)
                : $concepts->update($found->concept_id, ['remove_languages' => [$locale]]);
        });

        return response()->json(null, 204);
    }

    /**
     * Imports a CSV, XLSX or TBX file. CSV and XLSX apply directly, one row per concept:
     * missing rows stay, blank cells keep their value and nothing is removed. A bad row
     * becomes a row error and the rest applies. Each such import writes one audit log
     * entry. A header with `source_locale` is the older format. That and TBX find the row
     * by its wording and write through the same writer. Send `Accept: text/event-stream`
     * for progress.
     *
     * @apiResponse 200 data:@AiGlossarySheetImportResult
     * @apiResponse 422 @ValidationError The file cannot be read, or its first row names no usable columns.
     */
    public function import(ImportAiGlossaryRequest $request, Space|Team|string $owner, string $glossary, GlossaryTransfer $transfer): JsonResponse|StreamedResponse
    {
        $model = $this->findGlossary($request, $glossary, 'manage');
        $run = $transfer->import($model, $request->file('file'), $request->user());

        if ($this->wantsImportStream($request)) {
            return $this->streamImport($run, 'glossary terms', ['glossary_id' => $model->id]);
        }

        return response()->json(['data' => ImportRun::drain($run)->toArray()]);
    }

    /**
     * Downloads the glossary, one row per concept in CSV and XLSX. `template=1` gives
     * the columns and one made up row instead, and an XLSX also explains each column. A
     * glossary inherited from a team can be read and exported here, not changed.
     */
    public function export(ExportAiGlossaryRequest $request, Space|Team|string $owner, string $glossary, GlossaryTransfer $transfer): Response
    {
        $model = $this->readableGlossary($request, $glossary);

        return $transfer->export($model, $request->validated('format') ?? 'csv', $request->boolean('template'));
    }

    private function findGlossary(Request $request, string $id, string $ability): AiGlossary
    {
        $owner = $this->aiOwner($request);
        $this->authorize($ability, [AiGlossary::class, $owner]);

        return AiGlossary::query()->ownedBy($owner)->findOrFail($id);
    }

    private function termIn(AiGlossary $glossary, string $conceptId, string $locale): AiGlossaryTerm
    {
        return AiGlossaryTerm::query()->inGlossary($glossary->id)->with('concept.terms')
            ->where('concept_id', $conceptId)
            ->where('locale', GlossaryMatcher::normalizeLocale($locale))
            ->firstOrFail();
    }
}
