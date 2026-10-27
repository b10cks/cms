<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Actions\Ai\PublishAiKnowledgeDocument;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\ResolvesAiOwner;
use App\Http\Requests\Ai\SaveAiKnowledgeDocumentRequest;
use App\Http\Requests\Ai\UploadAiKnowledgeSourceRequest;
use App\Http\Resources\Management\Ai\AiKnowledgeDocumentResource;
use App\Jobs\ExtractAiKnowledgeTextJob;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\Space;
use App\Models\Management\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Style guides and reference documents: markdown drafts, published as revisions. */
class AiKnowledgeDocumentController extends Controller
{
    use ResolvesAiOwner;

    public function index(Request $request): AnonymousResourceCollection
    {
        $owner = $this->aiOwner($request);
        $this->authorize('viewAny', [AiKnowledgeDocument::class, $owner]);

        $columns = array_diff((new AiKnowledgeDocument)->getFillable(), ['draft']);

        return AiKnowledgeDocumentResource::collection(
            AiKnowledgeDocument::query()->ownedBy($owner)->with('publishedRevision:id,document_id,revision,created_at')
                ->orderBy('title')->get(['id', 'updated_at', ...$columns]),
        );
    }

    public function store(SaveAiKnowledgeDocumentRequest $request): AiKnowledgeDocumentResource
    {
        $owner = $this->aiOwner($request);
        $this->authorize('manage', [AiKnowledgeDocument::class, $owner]);
        $this->ensureUniqueSlug($owner, $request->input('slug'));

        $document = AiKnowledgeDocument::create([
            ...AiKnowledgeDocument::ownerAttributes($owner),
            ...$request->safe()->only(['title', 'slug', 'type', 'enabled']),
            'draft' => (string) $request->input('draft', ''),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return new AiKnowledgeDocumentResource($document->load('publishedRevision'));
    }

    public function show(Request $request, Space|Team|string $owner, string $document): AiKnowledgeDocumentResource
    {
        return new AiKnowledgeDocumentResource($this->findDocument($request, $document, 'viewAny')->load('publishedRevision'));
    }

    public function update(SaveAiKnowledgeDocumentRequest $request, Space|Team|string $owner, string $document): AiKnowledgeDocumentResource
    {
        $model = $this->findDocument($request, $document, 'manage');

        if ($request->has('slug')) {
            $this->ensureUniqueSlug($this->aiOwner($request), $request->input('slug'), $model->id);
        }

        $model->fill($request->safe()->only(['title', 'slug', 'type', 'enabled']));
        if ($request->has('draft')) {
            $model->draft = (string) $request->input('draft', '');
        }
        $model->updated_by = $request->user()->id;
        $model->save();

        return new AiKnowledgeDocumentResource($model->load('publishedRevision'));
    }

    public function publish(Request $request, Space|Team|string $owner, string $document, PublishAiKnowledgeDocument $publish): AiKnowledgeDocumentResource
    {
        $model = $this->findDocument($request, $document, 'manage');
        $publish->handle($model, $request->user());

        return new AiKnowledgeDocumentResource($model->refresh()->load('publishedRevision'));
    }

    /** Store a PDF or DOCX privately and extract its text into the draft on the queue. */
    public function upload(UploadAiKnowledgeSourceRequest $request, Space|Team|string $owner, string $document): AiKnowledgeDocumentResource
    {
        $model = $this->findDocument($request, $document, 'manage');
        $file = $request->file('file');
        $path = $file->storeAs("ai-knowledge/{$model->id}", Str::ulid().'.'.$file->getClientOriginalExtension(), 'transfers');

        if ($model->source_path) {
            Storage::disk('transfers')->delete($model->source_path);
        }

        $model->forceFill([
            'source_path' => $path,
            'source_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'extraction_status' => 'pending',
            'updated_by' => $request->user()->id,
        ])->save();

        ExtractAiKnowledgeTextJob::dispatch($model->id);

        return new AiKnowledgeDocumentResource($model->refresh()->load('publishedRevision'));
    }

    public function destroy(Request $request, Space|Team|string $owner, string $document): JsonResponse
    {
        $model = $this->findDocument($request, $document, 'manage');

        if ($model->source_path) {
            Storage::disk('transfers')->delete($model->source_path);
        }

        $model->delete();

        return response()->json(null, 204);
    }

    private function findDocument(Request $request, string $id, string $ability): AiKnowledgeDocument
    {
        $owner = $this->aiOwner($request);
        $this->authorize($ability, [AiKnowledgeDocument::class, $owner]);

        return AiKnowledgeDocument::query()->ownedBy($owner)->findOrFail($id);
    }

    private function ensureUniqueSlug(Space|Team $owner, string $slug, ?string $ignore = null): void
    {
        $taken = AiKnowledgeDocument::query()->ownedBy($owner)->where('slug', $slug)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['slug' => ['A document with this slug already exists here.']]);
        }
    }
}
