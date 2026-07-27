<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Actions\Content\BulkCreateContent;
use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BulkCreateContentController extends Controller
{
    public function __invoke(Request $request, Space $space, BulkCreateContent $action): JsonResponse
    {
        $this->authorize('create', [Content::class, $space]);

        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.slug' => 'required|string|max:70',
            'items.*.block_id' => 'required|string',
            'items.*.parent_id' => 'nullable|string',
            'items.*.position' => 'sometimes|integer|min:0',

            'items.*.temp_id' => 'nullable|string',
            'items.*.content' => 'sometimes|array',
            'items.*.language_iso' => 'sometimes|string|size:2',
        ]);

        // One existence query for all block ids instead of a Rule::exists per item.
        $validator->after(function ($validator) use ($request) {
            $items = collect($request->input('items', []));
            $blockIds = $items->pluck('block_id')
                ->filter(fn ($id): bool => \is_string($id) && $id !== '')
                ->unique();

            if ($blockIds->isEmpty()) {
                return;
            }

            $existing = Block::query()->whereIn('id', $blockIds)->pluck('id')->flip();

            foreach ($items as $index => $item) {
                $blockId = \is_array($item) ? ($item['block_id'] ?? null) : null;
                if (\is_string($blockId) && $blockId !== '' && ! $existing->has($blockId)) {
                    $validator->errors()->add(
                        "items.$index.block_id",
                        trans('validation.exists', ['attribute' => str_replace('_', ' ', "items.$index.block_id")]),
                    );
                }
            }
        });

        $validated = $validator->validate();

        $createdItems = $action->execute($validated['items'], $space, $request->user());

        return response()->json([
            'data' => $createdItems,
        ], 201);
    }
}
