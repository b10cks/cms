<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\InvoiceResource;
use App\Models\Management\Space;
use App\Models\Management\Subscription;
use App\Services\LemonSqueezy\LemonSqueezyService;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SpaceInvoiceController extends Controller
{
    public function __construct(private LemonSqueezyService $ls) {}

    /**
     * List the space's LemonSqueezy invoices (newest first). Returns an empty
     * collection for free spaces or when LemonSqueezy is unreachable — the
     * billing portal remains the source of truth.
     */
    public function __invoke(Space $space): ResourceCollection
    {
        $this->authorize('viewBilling', $space);

        $customerId = Subscription::where('space_id', $space->id)
            ->whereNotNull('ls_customer_id')
            ->latest()
            ->value('ls_customer_id');

        if (! $this->ls->isConfigured() || ! $customerId) {
            return InvoiceResource::collection([]);
        }

        try {
            // A failing closure leaves the cache untouched, so errors are
            // never cached — only successful responses are kept for 5 minutes.
            $invoices = Cache::remember(
                "ls.invoices.{$space->id}.{$customerId}",
                now()->addMinutes(5),
                fn () => collect($this->ls->listInvoices(['filter[customer_id]' => $customerId]))
                    ->map(fn (array $invoice) => $this->ls->normalizeInvoice($invoice))
                    ->sortByDesc('created_at')
                    ->values()
                    ->all()
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to list LemonSqueezy invoices', [
                'space' => $space->id,
                'error' => $e->getMessage(),
            ]);

            $invoices = [];
        }

        return InvoiceResource::collection($invoices);
    }
}
