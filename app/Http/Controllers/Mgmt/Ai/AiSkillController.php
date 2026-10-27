<?php

namespace App\Http\Controllers\Mgmt\Ai;

use App\Actions\Ai\PublishAiSkill;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mgmt\Concerns\ResolvesAiOwner;
use App\Http\Requests\Ai\SaveAiSkillRequest;
use App\Http\Resources\Management\Ai\AiSkillResource;
use App\Http\Resources\Management\Ai\ResolvedSkillResource;
use App\Models\Management\AiSkill;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Ai\Skills\SkillParser;
use App\Services\Ai\Skills\SkillResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Team and space skills: editable drafts, published as immutable revisions. */
class AiSkillController extends Controller
{
    use ResolvesAiOwner;

    public function index(Request $request, SkillResolver $resolver): JsonResponse
    {
        $owner = $this->aiOwner($request);
        $this->authorize('viewAny', [AiSkill::class, $owner]);

        return response()->json([
            'data' => AiSkillResource::collection(AiSkill::query()->ownedBy($owner)->with('publishedRevision')->orderBy('name')->get()),
            'resolved' => ResolvedSkillResource::collection(array_values($resolver->resolve($owner))),
        ]);
    }

    public function store(SaveAiSkillRequest $request, SkillParser $parser): AiSkillResource
    {
        $owner = $this->aiOwner($request);
        $this->authorize('manage', [AiSkill::class, $owner]);

        $manifest = $parser->parseUpload($request->input('files'));
        $this->ensureUniqueName($owner, $manifest->name);

        $skill = AiSkill::create([
            ...AiSkill::ownerAttributes($owner),
            'name' => $manifest->name,
            'enabled' => $request->boolean('enabled', true),
            'draft' => ['files' => $manifest->files],
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return new AiSkillResource($skill->load('publishedRevision'));
    }

    public function show(Request $request, Space|Team|string $owner, string $skill): AiSkillResource
    {
        return new AiSkillResource($this->findSkill($request, $skill, 'viewAny')->load('publishedRevision'));
    }

    public function update(SaveAiSkillRequest $request, Space|Team|string $owner, string $skill, SkillParser $parser): AiSkillResource
    {
        $model = $this->findSkill($request, $skill, 'manage');

        if ($request->has('files')) {
            $manifest = $parser->parseUpload($request->input('files'));
            $this->ensureUniqueName($this->aiOwner($request), $manifest->name, $model->id);
            $model->fill(['name' => $manifest->name, 'draft' => ['files' => $manifest->files]]);
        }

        if ($request->has('enabled')) {
            $model->enabled = $request->boolean('enabled');
        }

        $model->updated_by = $request->user()->id;
        $model->save();

        return new AiSkillResource($model->load('publishedRevision'));
    }

    public function publish(Request $request, Space|Team|string $owner, string $skill, PublishAiSkill $publish): AiSkillResource
    {
        $model = $this->findSkill($request, $skill, 'manage');
        $publish->handle($model, $request->user());

        return new AiSkillResource($model->refresh()->load('publishedRevision'));
    }

    public function destroy(Request $request, Space|Team|string $owner, string $skill): JsonResponse
    {
        $this->findSkill($request, $skill, 'manage')->delete();

        return response()->json(null, 204);
    }

    private function findSkill(Request $request, string $id, string $ability): AiSkill
    {
        $owner = $this->aiOwner($request);
        $this->authorize($ability, [AiSkill::class, $owner]);

        return AiSkill::query()->ownedBy($owner)->findOrFail($id);
    }

    private function ensureUniqueName(mixed $owner, string $name, ?string $ignore = null): void
    {
        $taken = AiSkill::query()->ownedBy($owner)->where('name', $name)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => ["A skill named {$name} already exists here."]]);
        }
    }
}
