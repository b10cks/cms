<?php

namespace App\Http\Resources\Management;

use App\Services\LemonSqueezy\LemonSqueezyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps an already-normalized LemonSqueezy invoice array
 * (see {@see LemonSqueezyService::normalizeInvoice()}).
 *
 * @property-read array<string, mixed> $resource
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'total' => $this->resource['total'],
            'total_formatted' => $this->resource['total_formatted'],
            'currency' => $this->resource['currency'],
            'status' => $this->resource['status'],
            'status_formatted' => $this->resource['status_formatted'],
            'refunded' => $this->resource['refunded'],
            'card_brand' => $this->resource['card_brand'],
            'card_last_four' => $this->resource['card_last_four'],
            'billing_reason' => $this->resource['billing_reason'],
            'invoice_url' => $this->resource['invoice_url'],
            'created_at' => $this->resource['created_at'],
        ];
    }
}
