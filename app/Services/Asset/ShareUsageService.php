<?php

namespace App\Services\Asset;

use App\Models\Management\Space;
use App\Models\Space\AssetShare;
use App\Models\Space\AssetShareEvent;
use App\Support\SpaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records public share access analytics without any DB write in the inline
 * request path (writes are deferred with ->afterResponse(), analogous to
 * TokenUsageService).
 *
 * Denormalized counters: `view_count` is incremented here on 'view' events and
 * `last_accessed_at` is touched for every event. `download_count` is NOT
 * incremented here — the public download endpoint increments it inline
 * (atomically) because it enforces the download limit.
 */
class ShareUsageService
{
    public function recordEvent(Space $space, AssetShare $share, string $event, ?string $assetId, Request $request): void
    {
        $shareId = $share->id;
        $ipHash = $request->ip()
            ? hash('sha256', $request->ip().'|'.config('app.key'))
            : null;
        $userAgent = $request->userAgent()
            ? mb_substr($request->userAgent(), 0, 255)
            : null;

        dispatch(function () use ($space, $shareId, $event, $assetId, $ipHash, $userAgent) {
            // The deferred closure runs after the response; the space models
            // need the space bound to resolve their database connection.
            $restore = SpaceContext::enter($space);

            try {
                (new AssetShareEvent)->getConnection()->transaction(function () use ($shareId, $event, $assetId, $ipHash, $userAgent) {
                    AssetShareEvent::create([
                        'share_id' => $shareId,
                        'event' => $event,
                        'asset_id' => $assetId,
                        'ip_hash' => $ipHash,
                        'user_agent' => $userAgent,
                    ]);

                    $query = AssetShare::withTrashed()->whereKey($shareId);

                    if ($event === AssetShareEvent::EVENT_VIEW) {
                        $query->update([
                            'view_count' => DB::raw('view_count + 1'),
                            'last_accessed_at' => now(),
                        ]);
                    } else {
                        $query->update(['last_accessed_at' => now()]);
                    }
                });
            } catch (\Throwable $e) {
                Log::warning('Failed to record asset share event', [
                    'share_id' => $shareId,
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $restore();
            }
        })->afterResponse();
    }
}
