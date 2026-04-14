<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Image\ImageTransformationService;
use App\Services\Storage\StorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    protected const int CACHE_DURATION = 31_536_000;

    protected ImageTransformationService $imageService;

    protected StorageService $storageService;

    public function __construct(ImageTransformationService $imageService, StorageService $storageService)
    {
        $this->imageService = $imageService;
        $this->storageService = $storageService;
    }

    public function process(
        Request $request,
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
            $asset = Asset::findOrFail($assetId);
            $mimetype = $asset->mime_type;
            $disk = $this->storageService->getStorage($space->storages()->find($storage));
        } else {
            $disk = Storage::disk();
            $mimetype = $disk->mimeType($fullPath);
        }

        if (\str_starts_with($mimetype, 'image/') === false) {
            return $disk->response($fullPath, null, [
                'Content-Type' => $mimetype,
                'Cache-Control' => 'public, max-age=' . self::CACHE_DURATION . ', immutable',
                'Pragma' => 'public',
            ]);
        }

        try {
            $params = $this->parseTransformations($transformations);
            $format = $request->query('format');
            $quality = $request->query('quality');

            if ($quality && is_numeric($quality)) {
                $params['quality'] = (int) $quality;
            }

            [$operation, $operationParams] = $this->determineOperation($params);
            $result = $this->imageService->processImage($disk, $fullPath, $operation, $operationParams, $format);

            if (!$result) {
                return response()->json(['error' => 'Image not found or processing failed'], 404);
            }

            return new Response($result['data'], 200, [
                'Content-Type' => $result['mime'],
                'Content-Length' => \strlen($result['data']),
                'Cache-Control' => 'public, max-age=' . self::CACHE_DURATION . ', immutable',
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

    /**
     * Parse a cloudinary-inspired transformation string into parameters array
     *
     * @param string|null $transformations Cloudinary-style transformations (e.g. w_300,h_300,c_fill,g_face)
     * @return array Parsed parameters
     */
    protected function parseTransformations(?string $transformations): array
    {
        if (!$transformations) {
            return [];
        }

        $params = [];
        $transformParts = \explode(',', $transformations);

        foreach ($transformParts as $part) {
            if (empty($part)) {
                continue;
            }

            [$key, $value] = \array_pad(\explode('_', $part, 2), 2, null);

            if ($key && $value !== null) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Determine the operation and parameters based on the parsed transformations
     *
     * @param array $params Parsed transformation parameters
     * @return array [$operation, $operationParams]
     */
    protected function determineOperation(array $params): array
    {
        $cropMode = $params['c'] ?? null;
        $gravity = $params['g'] ?? null;

        // Default parameters
        $operationParams = [
            'width' => isset($params['w']) ? (int) $params['w'] : 0,
            'height' => isset($params['h']) ? (int) $params['h'] : 0,
            'x' => isset($params['x']) ? (int) $params['x'] : 0,
            'y' => isset($params['y']) ? (int) $params['y'] : 0,
        ];

        // Handle target width and height for crop and resize
        $operationParams['targetWidth'] = isset($params['tw']) ? (int) $params['tw'] : $operationParams['width'] / 2;
        $operationParams['targetHeight'] = isset($params['th']) ? (int) $params['th'] : $operationParams['height'] / 2;

        // Parse focus points for fitfocus
        if ($gravity && preg_match('/^(\d+(?:\.\d+)?)p?_(\d+(?:\.\d+)?)p?$/', $gravity, $matches)) {
            $operationParams['x'] = (float) $matches[1];
            $operationParams['y'] = (float) $matches[2];
        }

        $operation = 'original';
        if ($cropMode) {
            switch ($cropMode) {
                case 'fill':
                    if ($gravity === 'face') {
                        $operation = 'smartfit';
                    } elseif ($gravity && \in_array($gravity, ['center', 'face', 'auto'])) {
                        $operation = 'smartfit';
                    } elseif ($gravity && preg_match('/^(\d+(?:\.\d+)?)p?_(\d+(?:\.\d+)?)p?$/', $gravity)) {
                        $operation = 'fitfocus';
                    } else {
                        $operation = 'fit';
                    }
                    break;

                case 'fit':
                    $operation = 'resize';
                    break;

                case 'crop':
                    $operation = isset($params['tw']) || isset($params['th']) ? 'cropresize' : 'crop';
                    break;

                default:
                    $operation = 'fit';
                    break;
            }
        } elseif (isset($params['w']) || isset($params['h'])) {
            $operation = 'resize';
        }

        return [$operation, $operationParams];
    }
}
