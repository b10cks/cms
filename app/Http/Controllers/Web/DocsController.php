<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the static VitePress documentation build from public/docs.
 *
 * In production the web server serves existing files directly; this
 * controller covers clean URLs (/docs/guides/nuxt → guides/nuxt.html)
 * and directory indexes that would otherwise fall through to the SPA
 * catch-all routes.
 */
class DocsController extends Controller
{
    public function __invoke(string $path = ''): BinaryFileResponse
    {
        $root = public_path('docs');

        abort_unless(is_dir($root), 404);

        foreach ([$path, "{$path}.html", trim("{$path}/index.html", '/')] as $candidate) {
            $file = realpath("{$root}/{$candidate}");

            if ($file !== false && is_file($file) && str_starts_with($file, realpath($root) . DIRECTORY_SEPARATOR)) {
                return response()->file($file);
            }
        }

        $notFound = realpath("{$root}/404.html");

        abort_unless($notFound !== false, 404);

        return response()->file($notFound)->setStatusCode(404);
    }
}
