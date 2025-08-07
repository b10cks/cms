<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Image\ImageTransformationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    protected const int CACHE_DURATION = 31536000;

    protected ImageTransformationService $imageService;

    public function __construct(ImageTransformationService $imageService)
    {
        $this->imageService = $imageService;
    }

    public function process(
        Request $request,
        string $storage,
        string $space,
        string $assetId,
        string $name,
        ?string $transformations = null
    ) {
        $fullPath = "{$space}/{$assetId}/{$name}";
        if ($storage !== 'storage') {
            $space = Space::findOrFail($space);
            $request->route()->setParameter('space', $space);
            $asset = Asset::findOrFail($assetId);
            $mimetype = $asset->mime_type;
        } else {
            $mimetype = Storage::disk()->mimeType($fullPath);
        }

        if (\Str::startsWith($mimetype, 'image/') === false) {
            return Storage::disk()->response(
                $fullPath,
                null,
                [
                    'Content-Type' => $mimetype,
                    'Cache-Control' => 'public, max-age=' . self::CACHE_DURATION . ', immutable',
                    'Pragma' => 'public',
                ]
            );
        }

        try {
            $params = $this->parseTransformations($transformations);

            $format = $request->query('format');
            $quality = $request->query('quality');

            if ($quality && is_numeric($quality)) {
                $params['quality'] = (int)$quality;
            }

            list($operation, $operationParams) = $this->determineOperation($params);
            $result = $this->imageService->processImage($storage, $fullPath, $operation, $operationParams, $format);

            if (!$result) {
                return response()->json(['error' => 'Image not found or processing failed'], 404);
            }

            return new Response($result['data'], 200, [
                'Content-Type' => $result['mime'],
                'Content-Length' => strlen($result['data']),
                'Cache-Control' => 'public, max-age=' . self::CACHE_DURATION . ', immutable',
                'Pragma' => 'public'
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
        $transformParts = explode(',', $transformations);

        foreach ($transformParts as $part) {
            if (empty($part)) {
                continue;
            }

            // Split by underscore to get key and value
            list($key, $value) = array_pad(explode('_', $part, 2), 2, null);

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
        // Default parameters
        $operationParams = [];

        // Check for width and height
        if (isset($params['w'])) {
            $operationParams['width'] = (int)$params['w'];
        }

        if (isset($params['h'])) {
            $operationParams['height'] = (int)$params['h'];
        }

        // Handle crop mode (c parameter)
        $cropMode = $params['c'] ?? null;

        // Handle gravity/focus (g parameter)
        $gravity = $params['g'] ?? null;

        // Set defaults if width or height not specified
        $operationParams['width'] ??= 0;
        $operationParams['height'] ??= 0;

        // Check for x and y for manual cropping
        if (isset($params['x'])) {
            $operationParams['x'] = (int)$params['x'];
        }

        if (isset($params['y'])) {
            $operationParams['y'] = (int)$params['y'];
        }

        // Default x and y for manual crop
        $operationParams['x'] ??= 0;
        $operationParams['y'] ??= 0;

        // Handle target width and height for crop and resize
        if (isset($params['tw'])) {
            $operationParams['targetWidth'] = (int)$params['tw'];
        }

        if (isset($params['th'])) {
            $operationParams['targetHeight'] = (int)$params['th'];
        }

        // Set default target dimensions for crop resize
        $operationParams['targetWidth'] ??= $operationParams['width'] / 2;
        $operationParams['targetHeight'] ??= $operationParams['height'] / 2;

        // Parse focus points for fitfocus
        if ($gravity && preg_match('/^(\d+(?:\.\d+)?)p?_(\d+(?:\.\d+)?)p?$/', $gravity, $matches)) {
            $operationParams['x'] = (float)$matches[1];
            $operationParams['y'] = (float)$matches[2];
        }

        // Default operation
        $operation = 'original';

        // Determine operation based on parameters
        if ($cropMode) {
            switch ($cropMode) {
                case 'fill':
                    if ($gravity === 'face') {
                        $operation = 'smartfit';
                    } elseif ($gravity && in_array($gravity, ['center', 'face', 'auto'])) {
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
                    if (isset($params['tw']) || isset($params['th'])) {
                        $operation = 'cropresize';
                    } else {
                        $operation = 'crop';
                    }
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
