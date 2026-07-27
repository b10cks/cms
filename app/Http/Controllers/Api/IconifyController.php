<?php

namespace App\Http\Controllers\Api;

use App\Models\Management\Space;
use App\Models\Space\Icon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Iconify-compatible icon API for a space's icon registry.
 *
 * The space is resolved from the `?token=` data-API token (AuthenticateDataApi middleware).
 * All icons are served under the fixed `b10cks` prefix.
 *
 * Endpoints:
 *   GET /api/v1/iconify/collections
 *   GET /api/v1/iconify/last-modified
 *   GET /api/v1/iconify/search?query=...
 *   GET /api/v1/iconify/{prefix}.json[?icons=a,b]          — Iconify JSON
 *   GET /api/v1/iconify/{prefix}.svg[?icons=a,b]           — SVG <symbol> sprite
 *   GET /api/v1/iconify/{prefix}.css?icons=a,b             — CSS for multiple icons
 *   GET /api/v1/iconify/{prefix}/{name}.svg                 — SVG file
 *   GET /api/v1/iconify/{prefix}/{name}.css                 — CSS for a single icon
 *
 * @see https://iconify.design/docs/api/
 */
class IconifyController
{
    private const PREFIX = 'b10cks';

    private const MAX_ICONS = 5000;

    // -------------------------------------------------------------------------
    // JSON / metadata endpoints
    // -------------------------------------------------------------------------

    public function iconData(Request $request, string $prefix): JsonResponse
    {
        $this->abortIfUnknownPrefix($prefix);

        $requested    = $this->parseIconList($request->query('icons'));
        $strokeWidth  = $this->parseStrokeWidth($request->query('stroke-width'));

        $query = Icon::query();
        if ($requested !== null) {
            $query->whereIn('key', $requested);
        } else {
            $query->limit(self::MAX_ICONS);
        }

        $iconData = [];
        foreach ($query->get() as $icon) {
            $data = $icon->toIconifyData();
            if ($strokeWidth !== null) {
                $data['body'] = $this->wrapStrokeWidth($data['body'], $strokeWidth);
            }
            $iconData[$icon->key] = $data;
        }

        $payload = [
            'prefix'       => $prefix,
            'icons'        => $iconData === [] ? new \stdClass() : $iconData,
            'width'        => 24,
            'height'       => 24,
            'lastModified' => $this->lastModifiedTimestamp(),
        ];

        if ($requested !== null) {
            $notFound = array_values(array_filter($requested, fn ($k) => !isset($iconData[$k])));
            if ($notFound !== []) {
                $payload['not_found'] = $notFound;
            }
        }

        return response()->json($payload);
    }

    public function collections(): JsonResponse
    {
        return response()->json([
            self::PREFIX => [
                'name'  => $this->currentSpace()->name,
                'total' => Icon::query()->count(),
            ],
        ]);
    }

    public function lastModified(): JsonResponse
    {
        return response()->json([
            'lastModified' => [
                self::PREFIX => $this->lastModifiedTimestamp(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $term  = trim((string) $request->query('query', ''));
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
            ->map(fn ($key) => self::PREFIX . ':' . $key)
            ->all();

        return response()->json(['icons' => $icons, 'total' => $total, 'limit' => $limit]);
    }

    // -------------------------------------------------------------------------
    // SVG endpoint
    // -------------------------------------------------------------------------

    /**
     * Render a single icon as an SVG document.
     *
     * Query params:
     *   width  — output width  (default: 1em)
     *   height — output height (default: 1em)
     *   color  — sets CSS `color` on the SVG root so `currentColor` inherits it
     *   flip   — 'horizontal', 'vertical', or 'horizontal,vertical'
     *   rotate — quarter turns: 1=90°, 2=180°, 3=270°; or '90deg' / '180deg' / '270deg'
     *   box    — 1/true adds an invisible bounding <rect> (forces height in some renderers)
     *   stroke-width — raw SVG stroke width (viewBox userspace units) for outline icons
     */
    public function iconSvg(Request $request, string $prefix, string $name): Response
    {
        $this->abortIfUnknownPrefix($prefix);

        $icon = Icon::query()->where('key', $name)->first();
        abort_unless($icon !== null, 404);

        $color  = is_string($request->query('color')) ? $request->query('color') : null;
        $width  = $request->filled('width') ? $request->query('width') : null;
        $height = $request->filled('height') ? $request->query('height') : null;
        $flip   = (string) $request->query('flip', '');
        $rotate = $this->parseRotate($request->query('rotate'));
        $box    = $this->parseBool($request->query('box'));
        $stroke = $this->parseStrokeWidth($request->query('stroke-width'));

        $body = $icon->body;

        if ($box) {
            $body = sprintf('<rect width="%d" height="%d" fill="none"/>%s', $icon->width, $icon->height, $body);
        }

        [$transformedBody, $viewW, $viewH] = $this->applyTransforms($body, $icon->width, $icon->height, $rotate, $flip);

        $attrs = [
            'xmlns'   => 'http://www.w3.org/2000/svg',
            'width'   => (string) ($width ?? '1em'),
            'height'  => (string) ($height ?? '1em'),
            'viewBox' => "0 0 {$viewW} {$viewH}",
        ];

        if ($color !== null) {
            $attrs['color'] = $color;
        }

        if ($stroke !== null) {
            $attrs['stroke-width'] = $stroke;
        }

        $svg = sprintf('<svg %s>%s</svg>', $this->attrString($attrs), $transformedBody);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            ...$this->svgSecurityHeaders(),
        ]);
    }

    /**
     * Render a collection of icons as a single SVG sprite of <symbol> elements.
     *
     * Each icon becomes a `<symbol id="{prefix}--{name}">` that can be referenced
     * elsewhere with `<svg><use href="#{prefix}--{name}"/></svg>`. The sprite root
     * is hidden so it can be inlined at the top of a document without rendering.
     *
     * Query params:
     *   icons — comma-separated list of icon keys (optional; defaults to all icons)
     */
    public function iconSprite(Request $request, string $prefix): Response
    {
        $this->abortIfUnknownPrefix($prefix);

        $requested = $this->parseIconList($request->query('icons'));
        $stroke    = $this->parseStrokeWidth($request->query('stroke-width'));

        $query = Icon::query();
        if ($requested !== null) {
            $byKey = $query->whereIn('key', $requested)->get()->keyBy('key');
            // Preserve requested order; silently drop keys not found
            $icons = collect($requested)->map(fn ($k) => $byKey->get($k))->filter()->values();
        } else {
            $icons = $query->limit(self::MAX_ICONS)->get();
        }

        $symbols = $icons->map(function (Icon $icon) use ($stroke) {
            $attrs = [
                'xmlns'   => 'http://www.w3.org/2000/svg',
                'viewBox' => "0 0 {$icon->width} {$icon->height}",
                'id'      => self::PREFIX . '--' . $icon->key,
            ];

            if ($stroke !== null) {
                $attrs['stroke-width'] = $stroke;
            }

            return sprintf('<symbol %s>%s</symbol>', $this->attrString($attrs), $icon->body);
        })->implode('');

        $svg = sprintf('<svg width="0" height="0" class="hidden">%s</svg>', $symbols);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            ...$this->svgSecurityHeaders(),
        ]);
    }

    // -------------------------------------------------------------------------
    // CSS endpoints
    // -------------------------------------------------------------------------

    /**
     * Render CSS for multiple icons in a single stylesheet.
     *
     * Required: ?icons=name1,name2,...
     *
     * Query params:
     *   mode     — 'mask' (default for currentColor icons) or 'background' (colorful icons)
     *   color    — replaces currentColor in the embedded SVG (default: 'black' for mask mode)
     *   selector — per-icon selector template; supports {prefix} and {name} (default: .icon--{prefix}--{name})
     *   common   — shared selector for size+mode props (default: .icon--{prefix})
     *   var      — CSS variable name for the SVG data URL, without '--' (default: svg → --svg)
     *   flip, rotate — same as SVG endpoint
     *   stroke-width — raw SVG stroke width (viewBox userspace units) for outline icons
     */
    public function iconCss(Request $request, string $prefix): Response
    {
        $this->abortIfUnknownPrefix($prefix);

        $requested = $this->parseIconList($request->query('icons'));
        abort_if($requested === null || $requested === [], 400);

        $byKey = Icon::query()->whereIn('key', $requested)->get()->keyBy('key');

        // Preserve requested order; silently drop keys not found
        $icons = collect($requested)
            ->map(fn ($k) => $byKey->get($k))
            ->filter()
            ->values();

        return $this->cssResponse($icons, $request);
    }

    /**
     * Render CSS for a single icon.
     * Same query params as iconCss() except `icons` is not needed.
     */
    public function iconCssSingle(Request $request, string $prefix, string $name): Response
    {
        $this->abortIfUnknownPrefix($prefix);

        $icon = Icon::query()->where('key', $name)->first();
        abort_unless($icon !== null, 404);

        return $this->cssResponse(collect([$icon]), $request);
    }

    // -------------------------------------------------------------------------
    // CSS rendering
    // -------------------------------------------------------------------------

    private function cssResponse(Collection $icons, Request $request): Response
    {
        $opts = [
            'color'    => $request->query('color') ?: null,
            'flip'     => (string) $request->query('flip', ''),
            'rotate'   => $this->parseRotate($request->query('rotate')),
            'mode'     => in_array($request->query('mode'), ['mask', 'background'], true)
                            ? $request->query('mode')
                            : null,
            'selector' => $this->sanitizeCssSelector($request->query('selector'), '.icon--{prefix}--{name}'),
            'common'   => $this->sanitizeCssSelector($request->query('common'), '.icon--{prefix}'),
            'var'      => preg_replace('/[^a-z0-9-]/i', '', (string) $request->query('var', 'svg')) ?: 'svg',
            'stroke'   => $this->parseStrokeWidth($request->query('stroke-width')),
        ];

        return response($this->renderCss($icons, $opts), 200, ['Content-Type' => 'text/css; charset=utf-8']);
    }

    /**
     * Sanitize a client-supplied CSS selector before it is interpolated into a
     * text/css response. Only characters that can appear in a plain selector are
     * allowed (plus the {prefix}/{name} placeholders); anything that could break
     * out of the rule — braces, parentheses, semicolons, at-signs — forces the
     * safe default so the endpoint can't be used for CSS injection.
     */
    private function sanitizeCssSelector(mixed $value, string $default): string
    {
        $value = (string) ($value ?: $default);

        // Validate the value with the placeholders removed, so their braces
        // don't count against the "no braces" rule.
        $withoutPlaceholders = str_replace(['{prefix}', '{name}'], '', $value);

        if ($withoutPlaceholders === '' || preg_match('/[^a-z0-9_\-.#:>~+\s,]/i', $withoutPlaceholders)) {
            return $default;
        }

        return $value;
    }

    private function renderCss(Collection $icons, array $opts): string
    {
        if ($icons->isEmpty()) {
            return '';
        }

        $prefix  = self::PREFIX;
        $varName = '--' . $opts['var'];
        $commonSel = str_replace('{prefix}', $prefix, $opts['common']);

        // Group by rendering mode; each group shares a common rule
        $grouped = $icons->groupBy(fn (Icon $icon) => $this->detectMode($icon->body, $opts['mode']));

        $parts = [];

        foreach ($grouped as $mode => $modeIcons) {
            // Common rule: shared display + mode properties
            $block  = "{$commonSel} {\n";
            $block .= "  display: inline-block;\n";
            $block .= "  width: 1em;\n";
            $block .= "  height: 1em;\n";

            if ($mode === 'mask') {
                $block .= "  background-color: currentColor;\n";
                $block .= "  -webkit-mask-image: var({$varName});\n";
                $block .= "  mask-image: var({$varName});\n";
                $block .= "  -webkit-mask-repeat: no-repeat;\n";
                $block .= "  mask-repeat: no-repeat;\n";
                $block .= "  -webkit-mask-size: 100% 100%;\n";
                $block .= "  mask-size: 100% 100%;\n";
            } else {
                $block .= "  background-repeat: no-repeat;\n";
                $block .= "  background-size: 100% 100%;\n";
            }

            $block .= '}';
            $parts[] = $block;

            // Per-icon rule: only the --svg variable (and background-image for background mode)
            foreach ($modeIcons as $icon) {
                $sel     = str_replace(['{prefix}', '{name}'], [$prefix, $icon->key], $opts['selector']);
                $dataUrl = $this->buildCssDataUrl($icon, $opts);

                $iconBlock  = "{$sel} {\n";
                $iconBlock .= "  {$varName}: url(\"{$dataUrl}\");\n";

                if ($mode === 'background') {
                    $iconBlock .= "  background-image: var({$varName});\n";
                }

                $iconBlock .= '}';
                $parts[] = $iconBlock;
            }
        }

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * Build a percent-encoded SVG data URL for embedding in CSS url() values.
     *
     * currentColor is replaced with the specified color (default: black) because CSS
     * mask/background contexts cannot inherit it from the surrounding document.
     * Single quotes are used in SVG attributes so < > # are the only chars needing encoding.
     */
    private function buildCssDataUrl(Icon $icon, array $opts): string
    {
        [$body, $viewW, $viewH] = $this->applyTransforms(
            $icon->body, $icon->width, $icon->height, $opts['rotate'], $opts['flip']
        );

        $color = $opts['color'] ?? 'black';
        $body  = str_replace('currentColor', $color, $body);

        $strokeAttr = isset($opts['stroke']) && $opts['stroke'] !== null
            ? " stroke-width='{$opts['stroke']}'"
            : '';

        $svg = "<svg xmlns='http://www.w3.org/2000/svg'"
            . " viewBox='0 0 {$viewW} {$viewH}'"
            . " width='{$viewW}' height='{$viewH}'"
            . $strokeAttr
            . '>'
            . $body
            . '</svg>';

        // Convert all remaining double quotes to single quotes (body attributes),
        // then percent-encode the characters that must be encoded in CSS url() values.
        $svg     = str_replace('"', "'", $svg);
        $encoded = str_replace(['<', '>', '#'], ['%3C', '%3E', '%23'], $svg);

        return 'data:image/svg+xml,' . $encoded;
    }

    // -------------------------------------------------------------------------
    // Transform logic — mirrors the @iconify/utils iconToSVG implementation
    // -------------------------------------------------------------------------

    /**
     * Apply flip and rotate transforms to the SVG body, returning the transformed body
     * and the resulting viewBox dimensions (which may swap for 90°/270° rotations).
     *
     * Transform order (matching Iconify):
     *  - flip transforms are pushed (applied first to content in SVG right-to-left evaluation)
     *  - rotation is unshifted to front (applied after flips)
     *
     * @return array{0: string, 1: int|float, 2: int|float}
     */
    private function applyTransforms(string $body, int $w, int $h, int $rotate, string $flip): array
    {
        $flipH = str_contains($flip, 'horizontal');
        $flipV = str_contains($flip, 'vertical');

        $transformations = [];
        $outW = $w;
        $outH = $h;

        // hFlip + vFlip together == 180° rotation (Iconify convention)
        if ($flipH && $flipV) {
            $rotate = ($rotate + 2) % 4;
            $flipH  = false;
            $flipV  = false;
        }

        // Flip transforms: pushed (rightmost in transform string = applied first)
        if ($flipH) {
            $transformations[] = "translate({$w} 0) scale(-1 1)";
        } elseif ($flipV) {
            $transformations[] = "translate(0 {$h}) scale(1 -1)";
        }

        // Rotation: unshifted (leftmost in transform string = applied last)
        switch ($rotate) {
            case 1: // 90° CW
                $t = $h / 2;
                array_unshift($transformations, "rotate(90 {$t} {$t})");
                [$outW, $outH] = [$h, $w];
                break;

            case 2: // 180°
                array_unshift($transformations, sprintf('rotate(180 %s %s)', $w / 2, $h / 2));
                break;

            case 3: // 270° CW (= 90° CCW)
                $t = $w / 2;
                array_unshift($transformations, "rotate(-90 {$t} {$t})");
                [$outW, $outH] = [$h, $w];
                break;
        }

        if (empty($transformations)) {
            return [$body, $outW, $outH];
        }

        return [
            sprintf('<g transform="%s">%s</g>', implode(' ', $transformations), $body),
            $outW,
            $outH,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse the `rotate` query parameter.
     * Accepts quarter-turn integers (1, 2, 3) or degree strings ('90deg', '180deg', '270deg').
     * Returns 0–3 (quarter turns).
     */
    private function parseRotate(mixed $value): int
    {
        $str = trim((string) ($value ?? ''));

        if ($str === '' || $str === '0') {
            return 0;
        }

        if (str_ends_with($str, 'deg')) {
            $deg = (int) substr($str, 0, -3);
            // Convert degrees to quarter turns (0–3)
            return (int) round(((($deg % 360) + 360) % 360) / 90) % 4;
        }

        return ((int) $str % 4 + 4) % 4;
    }

    private function parseBool(mixed $value): bool
    {
        return in_array((string) ($value ?? ''), ['1', 'true', 'yes'], true);
    }

    /**
     * Parse the `stroke-width` query parameter.
     *
     * The value is a raw SVG stroke width expressed in the icon's viewBox userspace
     * units — it is written verbatim into the output (SVG root, CSS data URL, JSON body
     * wrapper, sprite symbol). Consumers own any size→stroke mapping; the platform stays
     * tenant-agnostic. Returns null for empty/non-numeric input (no stroke override).
     */
    private function parseStrokeWidth(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    /**
     * Wrap an Iconify body in a <g stroke-width="..."> so the width cascades to the
     * outline paths. Used for the JSON endpoint, where the icon-data object has no
     * dedicated stroke-width field.
     */
    private function wrapStrokeWidth(string $body, string $strokeWidth): string
    {
        return sprintf('<g stroke-width="%s">%s</g>', $strokeWidth, $body);
    }

    private function detectMode(string $body, ?string $requested): string
    {
        if ($requested === 'mask' || $requested === 'background') {
            return $requested;
        }

        return str_contains($body, 'currentColor') ? 'mask' : 'background';
    }

    /** @return string[]|null  null = no filter (return all icons) */
    private function parseIconList(?string $icons): ?array
    {
        if ($icons === null || trim($icons) === '') {
            return null;
        }

        return collect(explode(',', $icons))
            ->map(fn ($k) => trim($k))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function attrString(array $attrs): string
    {
        return collect($attrs)
            ->map(fn ($v, $k) => sprintf('%s="%s"', $k, htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1)))
            ->implode(' ');
    }

    private function lastModifiedTimestamp(): int
    {
        $latest = Icon::query()->max('updated_at');

        return $latest ? Carbon::parse($latest)->getTimestamp() : 0;
    }

    private function currentSpace(): Space
    {
        return app('currentSpace');
    }

    private function abortIfUnknownPrefix(string $prefix): void
    {
        abort_unless($prefix === self::PREFIX, 404);
    }

    /**
     * Icon bodies are tenant-supplied SVG. IconSvgParser sanitizes them on the
     * way in, but an SVG opened as a document runs its own script, so these
     * responses get the same inert treatment as asset delivery: a hand-rolled
     * sanitizer should not be the only thing between an icon and same-origin
     * script execution on the delivery host.
     *
     * @return array<string, string>
     */
    private function svgSecurityHeaders(): array
    {
        return [
            'x-content-type-options' => 'nosniff',
            'content-security-policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; sandbox",
        ];
    }
}
