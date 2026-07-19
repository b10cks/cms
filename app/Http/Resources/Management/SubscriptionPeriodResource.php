<?php

namespace App\Http\Resources\Management;

use App\Models\Management\SubscriptionPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubscriptionPeriod
 */
class SubscriptionPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $quotas = $this->quotas ?? [];

        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'plan_name' => $this->plan_name,
            'status' => $this->status,
            'price' => (float) $this->price,
            'billing_period' => $this->billing_period,
            'quotas' => $quotas,
            'started_at' => $this->started_at?->toIso8601String(),
            'renews_at' => $this->renews_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'close_reason' => $this->close_reason,
            'is_open' => $this->isOpen(),
            'usage' => [
                'storage' => $this->metric('storage_bytes', $quotas['storage'] ?? null),
                'traffic' => $this->metric('traffic_bytes', $quotas['traffic'] ?? null),
                'ai' => $this->metric('ai_spend_usd', $quotas['aiCredit'] ?? null),
            ],
        ];
    }

    /**
     * Shape a single rolled-up metric. Values are null for an open period whose
     * rollup has not been computed yet; the live `/usage` endpoint covers it.
     *
     * @return array{used: float|int|null, limit: float|int|null, percentage: int|null}
     */
    private function metric(string $column, mixed $limit): array
    {
        $used = $this->{$column};
        $used = $used === null ? null : (float) $used;
        $limit = $limit === null ? null : (float) $limit;

        // Not capped at 100: quotas are soft and the UI flags over-usage in red.
        $percentage = null;
        if ($used !== null && $limit !== null && $limit > 0) {
            $percentage = (int) max(0, round($used / $limit * 100));
        }

        return [
            'used' => $used,
            'limit' => $limit,
            'percentage' => $percentage,
        ];
    }
}
