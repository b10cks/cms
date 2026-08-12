<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Filters\Mgmt\IconFilter;
use App\Http\Requests\Icon\StoreIconRequest;
use App\Http\Requests\Icon\UpdateIconRequest;
use App\Http\Resources\Management\IconResource;
use App\Models\Management\Space;
use App\Models\Space\Icon;
use App\Services\Auth\AuthorizationService;
use App\Services\Icon\IconSvgParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class IconController extends Controller
{
    /**
     * Display a listing of icons.
     */
    public function index(Space $space, Request $request): ResourceCollection
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'icons.view'), 403);

        $filter = new IconFilter($request->all());

        $icons = Icon::filter($filter)
            ->paginate($this->perPage($request, 60, 200));

        return IconResource::collection($icons);
    }

    /**
     * Return the distinct list of tags used across the space's icons.
     */
    public function tags(Space $space): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'icons.view'), 403);

        $tags = Icon::query()
            ->pluck('tags')
            ->flatMap(fn ($tags) => \is_array($tags) ? $tags : [])
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return response()->json(['data' => $tags]);
    }

    /**
     * Store a newly uploaded icon.
     */
    public function store(Space $space, StoreIconRequest $request, IconSvgParser $parser): IconResource|JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'icons.manage'), 403);

        $validated = $request->validated();

        try {
            $parsed = $parser->parse($this->resolveSvgSource($request, $validated));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Invalid SVG: ' . $e->getMessage()], 422);
        }

        $icon = new Icon();
        $icon->fill([
            'external_id' => $validated['external_id'] ?? null,
            'key' => $validated['key'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'body' => $parsed['body'],
            'width' => $validated['width'] ?? $parsed['width'],
            'height' => $validated['height'] ?? $parsed['height'],
            'tags' => $validated['tags'] ?? [],
        ]);
        $icon->save();

        return new IconResource($icon);
    }

    /**
     * Display the specified icon.
     */
    public function show(Space $space, Icon $icon): IconResource
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'icons.view'), 403);

        return new IconResource($icon);
    }

    /**
     * Update the specified icon (metadata and/or SVG body).
     */
    public function update(UpdateIconRequest $request, Space $space, Icon $icon, IconSvgParser $parser): IconResource|JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'icons.manage'), 403);

        $validated = $request->validated();
        $replacesSvg = $request->hasFile('file') || filled($request->input('body'));

        if ($replacesSvg) {
            try {
                $parsed = $parser->parse($this->resolveSvgSource($request, $validated));
            } catch (\RuntimeException $e) {
                return response()->json(['message' => 'Invalid SVG: ' . $e->getMessage()], 422);
            }

            $icon->body = $parsed['body'];
            $icon->width = $validated['width'] ?? $parsed['width'];
            $icon->height = $validated['height'] ?? $parsed['height'];
        }

        foreach (['key', 'name', 'description', 'external_id'] as $field) {
            if (array_key_exists($field, $validated)) {
                $icon->{$field} = $validated[$field];
            }
        }

        if (array_key_exists('tags', $validated)) {
            $icon->tags = $validated['tags'] ?? [];
        }

        // Allow adjusting the viewBox dimensions without replacing the SVG body.
        if (!$replacesSvg) {
            if (!empty($validated['width'])) {
                $icon->width = $validated['width'];
            }
            if (!empty($validated['height'])) {
                $icon->height = $validated['height'];
            }
        }

        $icon->save();

        return new IconResource($icon);
    }

    /**
     * Remove the specified icon.
     */
    public function destroy(Space $space, Icon $icon): JsonResponse
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, 'icons.manage'), 403);

        $icon->delete();

        return response()->json(null, 204);
    }

    /**
     * Read the raw SVG markup from the uploaded file or the raw `body` input.
     */
    private function resolveSvgSource(Request $request, array $validated): string
    {
        if ($request->hasFile('file')) {
            return (string) file_get_contents($request->file('file')->getRealPath());
        }

        return (string) ($validated['body'] ?? $request->input('body', ''));
    }
}
