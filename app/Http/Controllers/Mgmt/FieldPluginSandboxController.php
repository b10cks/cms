<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\FieldPlugin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the sandboxed HTML shell that boots a published field plugin bundle
 * inside the content editor's iframe. Unauthenticated but signature-protected:
 * the iframe runs with an opaque origin and cannot send session cookies, so
 * the management API hands out permanently signed URLs instead.
 */
class FieldPluginSandboxController extends Controller
{
    public function __invoke(Request $request, Space $space, string $fieldPlugin): Response
    {
        // The space id in the URL resolves the per-space database, same as
        // the public asset-share endpoints.
        app()->offsetSet('currentSpace', $space);

        $plugin = FieldPlugin::query()->find($fieldPlugin);

        abort_unless($plugin !== null && $plugin->is_active && $plugin->isPublished(), 404);

        // The URL is only immutable-cacheable when it pins the exact bundle
        // version; a stale or missing hash must not stick in caches.
        $cacheControl = $request->query('v') === $plugin->code_hash
            ? 'public, max-age=31536000, immutable'
            : 'no-store';

        $appOrigin = rtrim((string) config('app.url'), '/');

        return response()
            ->view('field-plugin-shell', ['plugin' => $plugin])
            ->header('Content-Security-Policy', implode('; ', [
                "default-src 'none'",
                "script-src 'unsafe-inline'",
                "style-src 'unsafe-inline'",
                'img-src data: https:',
                'font-src data: https:',
                'connect-src https:',
                "frame-ancestors {$appOrigin}",
                "form-action 'none'",
                "base-uri 'none'",
            ]))
            ->header('Cache-Control', $cacheControl)
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
