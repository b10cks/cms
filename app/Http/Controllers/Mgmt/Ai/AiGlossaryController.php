<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\ResolvesAiOwner;
use App\Http\Controllers\Mgmt\Concerns\RespondsWithImportRun;
use App\Http\Requests\Ai\ExportAiGlossaryRequest;
use App\Http\Requests\Ai\ImportAiGlossaryRequest;
use App\Http\Requests\Ai\SaveAiGlossaryRequest;
use App\Http\Resources\Management\Ai\AiGlossaryResource;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Ai\Knowledge\AiLayers;
use App\Services\Ai\Knowledge\GlossaryTransfer;
use App\Services\ImportExport\ImportRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Glossaries with CSV, XLSX and TBX import and export. Rows are edited through {@see AiGlossaryConceptRowController}. */
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
}
