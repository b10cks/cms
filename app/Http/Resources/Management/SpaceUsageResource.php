<?php

namespace App\Http\Resources\Management;

use App\Services\Space\Dto\UsageMetricDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{storage: UsageMetricDto, traffic: UsageMetricDto, downloads?: UsageMetricDto, requests: UsageMetricDto, ai: UsageMetricDto, period: array} $resource
 */
class SpaceUsageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'storage' => $this->resource['storage']->toArray(),
            'traffic' => $this->resource['traffic']->toArray(),
            // Additive: the download share of traffic (already counted in the
            // traffic quota above).
            'downloads' => $this->when(
                isset($this->resource['downloads']),
                fn () => $this->resource['downloads']->toArray()
            ),
            'requests' => $this->resource['requests']->toArray(),
            'ai' => $this->resource['ai']->toArray(),
            'period' => $this->resource['period'],
        ];
    }
}
