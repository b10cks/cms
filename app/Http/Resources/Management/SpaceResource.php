<?php

namespace App\Http\Resources\Management;

use App\Models\Management\Space;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Space
 */
class SpaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subscription = $this->resource->resolveCurrentSubscription();

        return [
            'id' => $this->id,
            'state' => $this->state,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon_url,
            'color' => $this->color,
            'badge' => $this->badge,
            'description' => $this->description,
            'settings' => $this->settings->toArray(),
            'team_id' => $this->team_id,
            'plan' => $subscription ? [
                'id' => $subscription->plan?->id,
                'name' => $subscription->plan?->getTranslatedName() ?? $subscription->name,
                'status' => $subscription->status,
            ] : null,
            'user_count' => $this->whenCounted('users', fn() => $this->users_count),
            'content_updated_at' => $this->content_updated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
