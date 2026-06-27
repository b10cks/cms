<?php

namespace App\Http\Controllers\Api;

use App\Models\Management\Space;
use App\Models\Space\Icon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Iconify-compatible icon API for a space's icon registry.
 *
 * The space is resolved from the `?token=` data-API token (see AuthenticateDataApi) and echoed in
 * the URL path for clarity + cache keying. Every space exposes a single, fixed-prefix collection
 * (`b10cks`) so consumers reference icons as `b10cks:<key>`.
 *
 * @see https://iconify.design/docs/api/
 */
class IconifyController
{
    /** Fixed Iconify collection prefix exposed for every space. */
    private const PREFIX = 'b10cks';

    /** Safety cap when a consumer requests the full set (no `icons=` filter). */
    private const MAX_ICONS = 5000;

    /**
     * GET /api/v1/iconify/{space}/{prefix}.json[?icons=a,b]
     * Returns icon data in IconifyJSON format.
     */
    public function iconData(Request $request, string $space, string $prefix): JsonResponse
    {
        $this->resolveSpace($space);

        $requested = $this->parseIconList($request->query('icons'));

        $query = Icon::query();
        if ($requested !== null) {
            $query->whereIn('key', $requested);
        } else {
            $query->limit(self::MAX_ICONS);
        }

        $iconData = [];
        foreach ($query->get() as $icon) {
            $iconData[$icon->key] = $icon->toIconifyData();
        }

        $payload = [
            'prefix' => $prefix,
            'icons' => $iconData === [] ? new \stdClass() : $iconData,
            'width' => 24,
            'height' => 24,
            'lastModified' => $this->lastModifiedTimestamp(),
        ];

        if ($requested !== null) {
            $notFound = array_values(array_filter($requested, fn (string $key) => !isset($iconData[$key])));
            if ($notFound !== []) {
                $payload['not_found'] = $notFound;
            }
        }

        return response()->json($payload);
    }

    /**
     * GET /api/v1/iconify/{space}/collections
     */
    public function collections(string $space): JsonResponse
    {
        $current = $this->resolveSpace($space);

        return response()->json([
            self::PREFIX => [
                'name' => $current->name,
                'total' => Icon::query()->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/iconify/{space}/last-modified
     */
    public function lastModified(string $space): JsonResponse
    {
        $this->resolveSpace($space);

        return response()->json([
            'lastModified' => [
                self::PREFIX => $this->lastModifiedTimestamp(),
            ],
        ]);
    }

    /**
     * GET /api/v1/iconify/{space}/search?query=...
     */
    public function search(Request $request, string $space): JsonResponse
    {
        $this->resolveSpace($space);

        $term = trim((string) $request->query('query', ''));
        $limit = min(max((int) $request->query('limit', 64), 1), 999);

        $query = Icon::query();
        if ($term !== '') {
            $query->where(function ($builder) use ($term) {
                $builder->where('key', 'LIKE', "%{$term}%")
                    ->orWhere('name', 'LIKE', "%{$term}%")
                    ->orWhere('description', 'LIKE', "%{$term}%");
            });
        }

        $total = (clone $query)->count();
        $icons = $query->orderBy('key')
            ->limit($limit)
            ->pluck('key')
            ->map(fn (string $key) => self::PREFIX . ':' . $key)
            ->all();

        return response()->json([
            'icons' => $icons,
            'total' => $total,
            'limit' => $limit,
        ]);
    }

    /**
     * GET /api/v1/iconify/{space}/{prefix}/{name}.svg[?color=&width=&height=]
     */
    public function iconSvg(Request $request, string $space, string $prefix, string $name): Response
    {
        $this->resolveSpace($space);

        $icon = Icon::query()->where('key', $name)->first();
        abort_unless($icon !== null, 404);

        $color = $request->query('color');
        $width = $request->filled('width') ? (int) $request->query('width') : null;
        $height = $request->filled('height') ? (int) $request->query('height') : null;

        $svg = $icon->toSvg(\is_string($color) ? $color : null, $width, $height);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml; charset=utf-8']);
    }

    /**
     * Ensure the `{space}` path segment matches the space the token resolved to.
     */
    private function resolveSpace(string $space): Space
    {
        $current = app()->bound('currentSpace') ? app('currentSpace') : null;

        abort_unless($current instanceof Space, 404);
        abort_unless($space === $current->id || $space === (string) $current->slug, 404);

        return $current;
    }

    /**
     * @return array<int, string>|null  null when no `icons` filter was supplied (full set requested)
     */
    private function parseIconList(?string $icons): ?array
    {
        if ($icons === null || trim($icons) === '') {
            return null;
        }

        return collect(explode(',', $icons))
            ->map(fn (string $key) => trim($key))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function lastModifiedTimestamp(): int
    {
        $latest = Icon::query()->max('updated_at');

        return $latest ? Carbon::parse($latest)->getTimestamp() : 0;
    }
}
