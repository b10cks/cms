<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\FieldPluginFilter;
use App\Http\Requests\Space\CreateFieldPluginRequest;
use App\Http\Requests\Space\UpdateFieldPluginRequest;
use App\Http\Resources\Management\FieldPluginResource;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\FieldPlugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class FieldPluginController extends Controller
{
    public function index(Space $space, Request $request): ResourceCollection
    {
        $this->authorize('viewAny', [FieldPlugin::class, $space]);

        $fieldPlugins = FieldPlugin::filter(new FieldPluginFilter($request->all()))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return FieldPluginResource::collection($fieldPlugins);
    }

    public function store(CreateFieldPluginRequest $request, Space $space): FieldPluginResource
    {
        $this->authorize('create', [FieldPlugin::class, $space]);

        $fieldPlugin = new FieldPlugin($request->safe()->except('code'));

        if (($code = $request->validated('code')) !== null) {
            $fieldPlugin->publish($code);
        }

        $fieldPlugin->save();

        return new FieldPluginResource($fieldPlugin)->withCode();
    }

    public function show(Space $space, FieldPlugin $fieldPlugin): FieldPluginResource
    {
        $this->authorize('view', [$fieldPlugin, $space]);

        return new FieldPluginResource($fieldPlugin)->withCode();
    }

    public function update(UpdateFieldPluginRequest $request, Space $space, FieldPlugin $fieldPlugin): FieldPluginResource
    {
        $this->authorize('update', [$fieldPlugin, $space]);

        $fieldPlugin->fill($request->safe()->except('code'));

        if (($code = $request->validated('code')) !== null) {
            $fieldPlugin->publish($code);
        }

        $fieldPlugin->save();

        return new FieldPluginResource($fieldPlugin)->withCode();
    }

    public function destroy(Space $space, FieldPlugin $fieldPlugin): JsonResponse
    {
        $this->authorize('delete', [$fieldPlugin, $space]);

        // There is no soft delete and the bundle only exists in this row, so
        // refuse while any block schema still references the handle.
        $usedBy = Block::query()
            ->where('schema', 'LIKE', '%"plugin_handle":"'.$fieldPlugin->handle.'"%')
            ->pluck('name');

        abort_if($usedBy->isNotEmpty(), 409, 'This field plugin is still used by: '.$usedBy->join(', '));

        $fieldPlugin->delete();

        return response()->json(null, 204);
    }
}
