<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Subscription\SyncSubscriptionFromLemonSqueezy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LemonSqueezyWebhookController
{
    public function __invoke(Request $request, SyncSubscriptionFromLemonSqueezy $sync): JsonResponse
    {
        $payload = $request->json()->all();
        $event = $request->header('X-Event-Name') ?: data_get($payload, 'meta.event_name');
        $webhookId = data_get($payload, 'meta.webhook_id');

        Log::info('LemonSqueezy webhook received', [
            'event' => $event,
            'webhook_id' => $webhookId,
        ]);

        // Replay protection: the payload is signature-verified upstream, but a
        // captured signed request could be re-sent to re-trigger a sync. Process
        // each webhook_id at most once. Cache::add is atomic, so concurrent
        // duplicates also collapse to a single run.
        if ($webhookId && ! Cache::add('ls_webhook:' . $webhookId, true, now()->addDays(7))) {
            Log::info('Ignoring duplicate LemonSqueezy webhook', ['webhook_id' => $webhookId]);

            return response()->json(['message' => 'OK']);
        }

        try {
            match ($event) {
                'subscription_created',
                'subscription_updated',
                'subscription_cancelled',
                'subscription_resumed',
                'subscription_expired',
                'subscription_paused',
                'subscription_unpaused',
                'subscription_plan_changed' => $this->handleSubscriptionUpsert($payload, $sync),

                'subscription_payment_success',
                'subscription_payment_failed',
                'subscription_payment_recovered',
                'subscription_payment_refunded' => $this->handleSubscriptionPaymentEvent($payload, $sync),

                'order_created' => $this->handleOrderCreated($payload),

                default => Log::debug('Unhandled LemonSqueezy event', [
                    'event' => $event,
                    'webhook_id' => $webhookId,
                ]),
            };
        } catch (\Throwable $e) {
            // Release the dedup marker so LemonSqueezy's retry of this same
            // webhook_id is processed rather than silently ignored.
            if ($webhookId) {
                Cache::forget('ls_webhook:' . $webhookId);
            }

            Log::error('LemonSqueezy webhook processing failed', [
                'event' => $event,
                'webhook_id' => $webhookId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Webhook processing failed.'], 500);
        }

        return response()->json(['message' => 'OK']);
    }

    private function handleSubscriptionUpsert(array $payload, SyncSubscriptionFromLemonSqueezy $sync): void
    {
        $lsSubscription = $payload['data'] ?? [];
        $spaceId = data_get($payload, 'meta.custom_data.space_id')
            ?? data_get($lsSubscription, 'attributes.custom_data.space_id');
        $subscriptionId = data_get($payload, 'meta.custom_data.subscription_id')
            ?? data_get($lsSubscription, 'attributes.custom_data.subscription_id');

        $sync->fromWebhook($lsSubscription, $spaceId, $subscriptionId);
    }

    private function handleSubscriptionPaymentEvent(array $payload, SyncSubscriptionFromLemonSqueezy $sync): void
    {
        $subscriptionId = data_get($payload, 'data.attributes.subscription_id')
            ?? data_get($payload, 'meta.custom_data.subscription_id');

        if (! $subscriptionId) {
            Log::warning('Subscription payment webhook missing subscription ID', [
                'event' => data_get($payload, 'meta.event_name'),
                'invoice_id' => data_get($payload, 'data.id'),
            ]);

            return;
        }

        $sync->fromLemonSqueezyId((string) $subscriptionId);
    }

    private function handleOrderCreated(array $payload): void
    {
        // Log order creation for debugging; subscriptions are handled via subscription_created
        Log::info('LemonSqueezy order created', [
            'order_id' => $payload['data']['id'] ?? null,
        ]);
    }
}
