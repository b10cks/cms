<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->getTranslatedName(),
            'description' => $this->getTranslatedDescription(),
            'features' => $this->getTranslatedFeatures(),
            'price' => $this->price,
            'period' => $this->period,
            'quotas' => $this->quotas,
            'is_free' => $this->is_free,
            'sort_order' => $this->sort_order,
            'contact_url' => $this->contact_url,
            // ls_product_id / ls_variant_id intentionally not exposed
        ];
    }
}
