<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\BlockTemplateFilter;
use App\Http\Requests\BlockTemplate\CreateBlockTemplateRequest;
use App\Http\Requests\BlockTemplate\UpdateBlockTemplateRequest;
use App\Http\Resources\Management\BlockTemplateResource;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\BlockTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class BlockTemplateController extends Controller
{
    public function index(Space $space, Block $block, Request $request): ResourceCollection
    {
        $this->authorize('viewAny', [BlockTemplate::class, $space]);

        $templates = BlockTemplate::filter(BlockTemplateFilter::fromRequest($request))
            ->where('block_id', $block->id)
            ->with(['createdBy'])
            ->paginate(min($request->per_page ?? 20, 1000));

        return BlockTemplateResource::collection($templates);
    }

    public function store(Space $space, Block $block, CreateBlockTemplateRequest $request): BlockTemplateResource
    {
        $this->authorize('create', [BlockTemplate::class, $space]);

        $data = $request->validated();
        $data['block_id'] = $block->id;
        $data['created_by_id'] = auth()->id();

        $template = BlockTemplate::forceCreate($data);

        return new BlockTemplateResource($template->load('createdBy'));
    }

    public function show(Space $space, Block $block, BlockTemplate $template): BlockTemplateResource
    {
        $this->authorize('view', [$template, $space]);

        return new BlockTemplateResource($template->load('createdBy'));
    }

    public function update(Space $space, Block $block, UpdateBlockTemplateRequest $request, BlockTemplate $template): BlockTemplateResource
    {
        $this->authorize('update', [$template, $space]);

        $template->fill($request->validated());

        if (!$template->save()) {
            Log::error('Failed to update block template', ['template_id' => $template->id]);
            abort(500, 'Failed to update block template');
        }

        return new BlockTemplateResource($template->load('createdBy'));
    }

    public function destroy(Space $space, Block $block, BlockTemplate $template): JsonResponse
    {
        $this->authorize('delete', [$template, $space]);

        try {
            $template->delete();

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete block template', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the block template',
            ], 500);
        }
    }
}
