<?php

namespace App\Services\Image;

use App\Services\Image\Dto\ImageTransformation;

class ImageTransformationResolver
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function resolve(array $params, ?string $format = null, ?int $quality = null): ImageTransformation
    {
        $cropMode = $params['c'] ?? null;
        $gravity = $params['g'] ?? null;

        $operationParams = [
            'width' => isset($params['w']) ? (int) $params['w'] : null,
            'height' => isset($params['h']) ? (int) $params['h'] : null,
            'x' => isset($params['x']) ? (int) $params['x'] : 0,
            'y' => isset($params['y']) ? (int) $params['y'] : 0,
            'targetWidth' => isset($params['tw']) ? (int) $params['tw'] : null,
            'targetHeight' => isset($params['th']) ? (int) $params['th'] : null,
        ];

        if ($gravity && preg_match('/^(\d+(?:\.\d+)?)p?_(\d+(?:\.\d+)?)p?$/', $gravity, $matches)) {
            $operationParams['focusX'] = (float) $matches[1];
            $operationParams['focusY'] = (float) $matches[2];
        }

        $operation = 'original';

        if ($cropMode) {
            $operation = match ($cropMode) {
                'fill' => $this->resolveFillOperation($gravity),
                'fit' => 'resize',
                'crop' => isset($params['tw']) || isset($params['th']) ? 'cropresize' : 'crop',
                default => 'original',
            };
        } else {
            if (isset($params['w']) || isset($params['h'])) {
                $operation = 'resize';
            }
            if (isset($params['w']) && isset($params['h'])) {
                $operation = 'fit';
            }
        }

        return new ImageTransformation($operation, $operationParams, $format, $quality);
    }

    private function resolveFillOperation(?string $gravity): string
    {
        if ($gravity === null) {
            return 'fit';
        }

        if (\in_array($gravity, ['center', 'face', 'auto'], true)) {
            return 'smartfit';
        }

        if (preg_match('/^(\d+(?:\.\d+)?)p?_(\d+(?:\.\d+)?)p?$/', $gravity)) {
            return 'fitfocus';
        }

        return 'fit';
    }
}
