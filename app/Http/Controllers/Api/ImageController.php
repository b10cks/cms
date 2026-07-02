<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProcessImageRequest;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Image\ImageTransformationResolver;
use App\Services\Image\ImageTransformationService;
use App\Services\Storage\StorageService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    public function __construct(
        private readonly ImageTransformationService $imageService,
        private readonly StorageService $storageService,
    ) {}

    public function process(
        ProcessImageRequest $request,
        string $storage,
        string $space,
        string $assetId,
        string $name,
        ?string $transformations = null,
    ) {
        $fullPath = "{$space}/{$assetId}/{$name}";

        if ($storage !== 'storage') {
            $space = Space::findOrFail($space);
            $request->route()->setParameter('space', $space);
            app()->offsetSet('currentSpace', $space);

            $storageModel = $space->storages()->findOrFail($storage);

            $asset = Asset::query()->whereKey($assetId)->where('storage_id', $storageModel->id)->firstOrFail();
            $disk = $this->storageService->getStorage($storageModel);

            if ($asset->path === $fullPath) {
                $mimetype = $asset->mime_type;
            } else {
                $mimetype = $disk->mimeType($fullPath);
            }
        } else {
            $disk = Storage::disk();

            if (! $disk->exists($fullPath)) {
                return response()->json(['error' => 'Image not found'], 404);
            }

            $mimetype = $disk->mimeType($fullPath);
        }

        if (\str_starts_with($mimetype, 'image/') === false) {
            return $disk->response($fullPath, null, [
                'access-control-allow-origin' => '*',
                'access-control-allow-methods' => 'GET',
                'content-type' => $mimetype,
                'cache-control' => $this->buildCacheControlHeader(),
                'pragma' => 'public',
                ...$this->securityHeaders(),
            ]);
        }

        try {
            $transformationParameters = $request->transformationParameters();
            $format = $request->validated('format');
            $quality = $request->validated('quality');

            if (empty($transformationParameters) && $format === null && $quality === null) {
                return $disk->response($fullPath, null, [
                    'access-control-allow-origin' => '*',
                    'access-control-allow-methods' => 'GET',
                    'content-type' => $mimetype,
                    'cache-control' => $this->buildCacheControlHeader(),
                    'pragma' => 'public',
                    ...$this->securityHeaders(),
                ]);
            }

            $transformation = app(ImageTransformationResolver::class)->resolve(
                $transformationParameters,
                $format,
                $quality,
            );
            $result = $this->imageService->processImage($disk, $fullPath, $transformation);

            if (! $result) {
                return response()->json(['error' => 'Image not found or processing failed'], 404);
            }

            return new Response($result['data'], 200, [
                'access-control-allow-origin' => '*',
                'access-control-allow-methods' => 'GET',
                'content-type' => $result['mime'],
                'content-length' => \strlen($result['data']),
                'cache-control' => $this->buildCacheControlHeader(),
                'pragma' => 'public',
                ...$this->securityHeaders(),
            ]);
        } catch (\Exception $e) {
            Log::error('Image processing error: '.$e->getMessage(), [
                'storage' => $storage,
                'path' => $fullPath,
                'transformations' => $transformations,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Error processing image'], 500);
        }
    }

    /**
     * Headers that make it safe to serve user-uploaded files inline from the
     * delivery origin. `nosniff` stops browsers from re-interpreting a file as
     * HTML, and the restrictive CSP + sandbox neutralize any script embedded in
     * an otherwise-legitimate SVG/XML asset while still letting it render.
     *
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        return [
            'x-content-type-options' => 'nosniff',
            'content-security-policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; sandbox",
        ];
    }

    private function buildCacheControlHeader(): string
    {
        $duration = (int) config('ilum.cache.duration', 31_536_000);
        $immutable = (bool) config('ilum.cache.immutable', true);

        return 'public, max-age='.$duration.($immutable ? ', immutable' : '');
    }
}
