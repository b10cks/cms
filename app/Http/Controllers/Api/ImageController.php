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
            $asset = Asset::query()
                ->whereKey($assetId)
                ->where('storage_id', $storageModel->id)
                ->firstOrFail();

            abort_unless($asset->path === $fullPath, 404);

            $mimetype = $asset->mime_type;
            $disk = $this->storageService->getStorage($storageModel);
        } else {
            $disk = Storage::disk();

            if (! $disk->exists($fullPath)) {
                return response()->json(['error' => 'Image not found'], 404);
            }

            $mimetype = $disk->mimeType($fullPath);
        }

        if (\str_starts_with($mimetype, 'image/') === false) {
            return $disk->response($fullPath, null, [
                'Content-Type' => $mimetype,
                'Cache-Control' => $this->buildCacheControlHeader(),
                'Pragma' => 'public',
            ]);
        }

        try {
            $transformation = app(ImageTransformationResolver::class)->resolve(
                $request->transformationParameters(),
                $request->validated('format'),
                $request->validated('quality'),
            );
            $result = $this->imageService->processImage($disk, $fullPath, $transformation);

            if (!$result) {
                return response()->json(['error' => 'Image not found or processing failed'], 404);
            }

            return new Response($result['data'], 200, [
                'Content-Type' => $result['mime'],
                'Content-Length' => \strlen($result['data']),
                'Cache-Control' => $this->buildCacheControlHeader(),
                'Pragma' => 'public',
            ]);
        } catch (\Exception $e) {
            Log::error('Image processing error: ' . $e->getMessage(), [
                'storage' => $storage,
                'path' => $fullPath,
                'transformations' => $transformations,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Error processing image'], 500);
        }
    }

    private function buildCacheControlHeader(): string
    {
        $duration = (int) config('ilum.cache.duration', 31_536_000);
        $immutable = (bool) config('ilum.cache.immutable', true);

        return 'public, max-age=' . $duration . ($immutable ? ', immutable' : '');
    }
}
