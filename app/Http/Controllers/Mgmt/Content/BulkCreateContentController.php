<?php

namespace App\Http\Controllers\Mgmt\Content;

use App\Actions\Content\BulkCreateContent;
use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BulkCreateContentController extends Controller
{
    public function __invoke(Request $request, Space $space, BulkCreateContent $action): JsonResponse
    {
        $this->authorize('create', [Content::class, $space]);

        $connectionName = new Content()->getConnectionName();
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.slug' => 'required|string|max:70',
            'items.*.block_id' => ['required','string',Rule::exists("$connectionName.blocks", 'id')
                ->whereNull('deleted_at')],
            'items.*.parent_id' => 'nullable|string',

            'items.*.temp_id' => 'nullable|string',
            'items.*.content' => 'sometimes|array',
            'items.*.language_iso' => 'sometimes|string|size:2',
        ]);

        $createdItems = $action->execute($validated['items'], $space, $request->user());

        return response()->json([
            'data' => $createdItems,
        ], 201);
    }
}
