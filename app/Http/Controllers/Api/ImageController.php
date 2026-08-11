<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProcessImageRequest;
use App\Models\Space\Asset;
use App\Services\Image\ImageTransformationResolver;
use App\Services\Image\ImageTransformationService;
use App\Services\Media\Dto\IlumSource;
use App\Services\Media\IlumSourceResolver;
use App\Services\Media\MediaStreamResponder;
use App\Services\Media\StoredFileProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ImageController extends Controller
{
    public function __construct(
        private readonly ImageTransformationService $imageService,
        private readonly IlumSourceResolver $sources,
        private readonly MediaStreamResponder $responder,
        private readonly StoredFileProbe $probe,
    ) {}

    /**
     * Deliver an asset, optionally transformed.
     *
     * Images run through the transformation pipeline; everything else is
     * proxied byte-for-byte with range support so media players can seek.
     */
    public function process(
        ProcessImageRequest $request,
        string $storage,
        string $space,
        string $assetId,
        string $name,
        ?string $transformations = null,
    ): Response {
        $source = $this->sources->resolve($storage, $space, $assetId, $name);

        if ($source === null) {
            return $this->notFound();
        }

        if (! $source->file->isImage()) {
            return $this->responder->respond(
                $request,
                $source->disk,
                $source->file,
                immutable: (bool) config('ilum.cache.passthrough_immutable', false),
            );
        }

        return $this->deliverImage($request, $source, (bool) config('ilum.cache.immutable', true));
    }

    /**
     * Deliver the poster frame for a non-image asset.
     *
     * Poster URLs mirror the image grammar, so the same transformation segment
     * and format/quality parameters apply — a poster is just another image
     * once it has been resolved.
     */
    public function poster(
        ProcessImageRequest $request,
        string $storage,
        string $space,
        string $assetId,
        string $name,
        ?string $transformations = null,
    ): Response {
        $source = $this->sources->resolve($storage, $space, $assetId, $name);

        if ($source === null || $source->asset === null) {
            return $this->notFound();
        }

        $mime = (string) $source->asset->mime_type;

        // Images are their own preview and never carry a poster.
        if (Str::startsWith($mime, 'image/')) {
            return $this->notFound();
        }

        $thumbnails = $this->posterThumbnails($source->asset);

        // Video and audio legitimately expose their generated frames; every
        // other type only carries a poster once one was explicitly uploaded —
        // `thumbnails` metadata from unrelated sources is not a poster.
        if (! Str::startsWith($mime, ['video/', 'audio/']) && empty($thumbnails[0]['custom'])) {
            return $this->notFound();
        }

        $posterPath = $this->resolvePosterPath($thumbnails, $request->integer('frame'));

        if ($posterPath === null) {
            return response()->json(['error' => 'No poster available for this asset'], 404);
        }

        $poster = $this->probe->probe($source->disk, $posterPath);

        if ($poster === null) {
            return $this->notFound();
        }

        // The poster URL is stable across poster changes, so it can only be
        // cached immutably when the caller pins a version (see AssetResource's
        // `poster_url`). Without one, keep it short and revalidatable.
        $pinned = $request->filled('v');

        return $this->deliverImage(
            $request,
            $source->withFile($poster),
            immutable: $pinned,
            maxAge: $pinned ? null : (int) config('ilum.cache.poster_duration', 3600),
        );
    }

    private function deliverImage(
        ProcessImageRequest $request,
        IlumSource $source,
        bool $immutable,
        ?int $maxAge = null,
    ): Response {
        try {
            $transformationParameters = $request->transformationParameters();
            $format = $request->validated('format');
            $quality = $request->validated('quality');

            if (empty($transformationParameters) && $format === null && $quality === null) {
                return $this->responder->respond($request, $source->disk, $source->file, $immutable, $maxAge);
            }

            $transformation = app(ImageTransformationResolver::class)->resolve(
                $transformationParameters,
                $format,
                $quality,
            );

            $result = $this->imageService->processImage($source->disk, $source->file->path, $transformation);

            if (! $result) {
                return response()->json(['error' => 'Image not found or processing failed'], 404);
            }

            return $this->responder->respondWithBody(
                $request,
                $result['data'],
                $result['mime'],
                $immutable,
                $maxAge,
                $this->transformedDownloadName($source, $result['format']),
            );
        } catch (\Exception $e) {
            Log::error('Image processing error: '.$e->getMessage(), [
                'path' => $source->file->path,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'Error processing image'], 500);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function posterThumbnails(Asset $asset): array
    {
        return array_values(array_filter(
            (array) ($asset->metadata['thumbnails'] ?? []),
            static fn ($thumb): bool => is_array($thumb) && ! empty($thumb['path']),
        ));
    }

    /**
     * Pick a stored poster frame. `frame` indexes into the thumbnail list in
     * capture order; a custom uploaded poster collapses that list to one entry.
     *
     * @param  array<int, array<string, mixed>>  $thumbnails
     */
    private function resolvePosterPath(array $thumbnails, ?int $frame): ?string
    {
        if ($thumbnails === []) {
            return null;
        }

        return $thumbnails[$frame ?? 0]['path'] ?? $thumbnails[0]['path'];
    }

    private function transformedDownloadName(IlumSource $source, string $format): string
    {
        $base = pathinfo($source->file->downloadName ?: $source->file->path, PATHINFO_FILENAME);

        return ($base !== '' ? $base : 'image').'.'.$format;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'Image not found'], 404);
    }
}
