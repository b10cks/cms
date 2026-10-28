<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\ResolvesAiOwner;
use App\Http\Requests\Ai\ImportAiGlossaryRequest;
use App\Http\Requests\Ai\SaveAiGlossaryRequest;
use App\Http\Requests\Ai\SaveAiGlossaryTermRequest;
use App\Http\Resources\Management\Ai\AiGlossaryResource;
use App\Http\Resources\Management\Ai\AiGlossaryTermResource;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Ai\Knowledge\AiLayers;
use App\Services\Ai\Knowledge\GlossaryTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Glossaries and their terms, with CSV, XLSX and TBX import and export. */
class AiGlossaryController extends Controller
{
    use ResolvesAiOwner;

    /** `?inherited=1` adds the glossaries of the owning and ancestor teams, read-only for this owner. */
    public function index(Request $request, AiLayers $layers): AnonymousResourceCollection
    {
        $owner = $this->aiOwner($request);
        $this->authorize('viewAny', [AiGlossary::class, $owner]);

        $query = $request->boolean('inherited')
            ? AiGlossary::query()->inLayers($owner instanceof Space ? $owner->id : null, $layers->teamIds($owner))
            : AiGlossary::query()->ownedBy($owner);

        return AiGlossaryResource::collection($query->withCount('terms')->orderBy('name')->get());
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

        return new AiGlossaryResource($glossary->loadCount('terms'));
    }

    public function update(SaveAiGlossaryRequest $request, Space|Team|string $owner, string $glossary): AiGlossaryResource
    {
        $model = $this->findGlossary($request, $glossary, 'manage');
        $model->fill($request->validated())->save();

        return new AiGlossaryResource($model->loadCount('terms'));
    }

    public function destroy(Request $request, Space|Team|string $owner, string $glossary): JsonResponse
    {
        $this->findGlossary($request, $glossary, 'manage')->delete();

        return response()->json(null, 204);
    }

    public function terms(Request $request, Space|Team|string $owner, string $glossary): AnonymousResourceCollection
    {
        $model = $this->findGlossary($request, $glossary, 'viewAny');
        $search = trim((string) $request->query('search', ''));

        return AiGlossaryTermResource::collection(
            $model->terms()
                ->when($search !== '', fn ($query) => $query->where('term', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->orderBy('term')
                ->paginate($this->perPage($request, 50, 200)),
        );
    }

    public function storeTerm(SaveAiGlossaryTermRequest $request, Space|Team|string $owner, string $glossary): AiGlossaryTermResource
    {
        $model = $this->findGlossary($request, $glossary, 'manage');

        return new AiGlossaryTermResource($model->terms()->create($this->termAttributes($request)));
    }

    public function updateTerm(SaveAiGlossaryTermRequest $request, Space|Team|string $owner, string $glossary, string $term): AiGlossaryTermResource
    {
        $termModel = $this->findGlossary($request, $glossary, 'manage')->terms()->findOrFail($term);
        $termModel->fill($this->termAttributes($request))->save();

        return new AiGlossaryTermResource($termModel);
    }

    public function destroyTerm(Request $request, Space|Team|string $owner, string $glossary, string $term): JsonResponse
    {
        $this->findGlossary($request, $glossary, 'manage')->terms()->findOrFail($term)->delete();

        return response()->json(null, 204);
    }

    public function import(ImportAiGlossaryRequest $request, Space|Team|string $owner, string $glossary, GlossaryTransfer $transfer): JsonResponse
    {
        $model = $this->findGlossary($request, $glossary, 'manage');

        return response()->json(['data' => $transfer->import($model, $request->file('file'))->toArray()]);
    }

    public function export(Request $request, Space|Team|string $owner, string $glossary, GlossaryTransfer $transfer): Response
    {
        $model = $this->findGlossary($request, $glossary, 'viewAny');
        $format = $request->validate(['format' => ['sometimes', Rule::in(GlossaryTransfer::FORMATS)]])['format'] ?? 'csv';

        return $transfer->export($model, $format);
    }

    private function findGlossary(Request $request, string $id, string $ability): AiGlossary
    {
        $owner = $this->aiOwner($request);
        $this->authorize($ability, [AiGlossary::class, $owner]);

        return AiGlossary::query()->ownedBy($owner)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function termAttributes(SaveAiGlossaryTermRequest $request): array
    {
        $attributes = $request->validated();

        if (\array_key_exists('translations', $attributes)) {
            $attributes['translations'] = array_filter($attributes['translations'] ?? [], fn ($value) => \is_string($value) && trim($value) !== '');
        }

        return $attributes;
    }
}
