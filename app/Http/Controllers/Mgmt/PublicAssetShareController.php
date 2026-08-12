<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Models\Space\AssetShare;
use App\Models\Space\AssetShareEvent;
use App\Services\Asset\AssetPackageService;
use App\Services\Asset\ShareAccessTokenService;
use App\Services\Asset\ShareDeliveryService;
use App\Services\Asset\ShareUsageService;
use App\Services\Image\ImageTransformationResolver;
use App\Services\Image\ImageTransformationService;
use App\Services\Storage\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Unauthenticated public share API, addressed by space + token
 * (`/shares/{space}/{token}`): shares live in each space's own database, and
 * the space id in the URL is what lets the request resolve the right database
 * without any global lookup. Unknown spaces, unknown tokens and revoked/
 * expired/deleted shares are indistinguishable (plain 404). Password
 * protection is enforced with short-lived stateless HMAC access tokens issued
 * by `unlock` (no sessions) — passed via `Authorization: Bearer <token>` or
 * `?access=<token>`.
 */
class PublicAssetShareController extends Controller
{
    private const array NO_STORE = ['Cache-Control' => 'private, no-store'];

    public function __construct(
        private readonly AssetPackageService $packageService,
        private readonly ShareAccessTokenService $accessTokens,
        private readonly ShareDeliveryService $delivery,
        private readonly ShareUsageService $usage,
    ) {}

    public function show(Request $request, Space $space, string $token): JsonResponse
    {
        $share = $this->resolveShare($space, $token);

        if ($share->hasPassword() && ! $this->isUnlocked($share, $request)) {
            return response()->json([
                'data' => [
                    'name' => $share->name,
                    'protected' => true,
                    'unlocked' => false,
                ],
            ], 200, self::NO_STORE);
        }

        $this->usage->recordEvent($space, $share, AssetShareEvent::EVENT_VIEW, null, $request);

        $assetCount = $this->countAssets($space, $share);

        return response()->json([
            'data' => [
                'name' => $share->name,
                'description' => $share->description,
                'settings' => $share->settings,
                'asset_count' => $assetCount,
                'allow_individual_downloads' => $share->allow_individual_downloads,
                'download_limit' => $share->download_limit,
                'download_count' => $share->download_count,
                'expires_at' => $share->expires_at?->toIso8601String(),
                'package_state' => $share->package?->state,
                'package_progress' => $share->package?->progress,
                'protected' => $share->hasPassword(),
                'unlocked' => true,
            ],
        ], 200, self::NO_STORE);
    }

    public function unlock(Request $request, Space $space, string $token): JsonResponse
    {
        $share = $this->resolveShare($space, $token);

        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! $share->hasPassword() || ! Hash::check($validated['password'], $share->password)) {
            return response()->json(['message' => 'Invalid password.'], 403, self::NO_STORE);
        }

        $this->usage->recordEvent($space, $share, AssetShareEvent::EVENT_UNLOCK, null, $request);

        $ttlMinutes = (int) config('asset_distribution.access_token_ttl_minutes', 60);

        return response()->json([
            'access_token' => $this->accessTokens->issue($share, $ttlMinutes),
            'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
        ], 200, self::NO_STORE);
    }

    public function assets(Request $request, Space $space, string $token): JsonResponse
    {
        $share = $this->resolveShare($space, $token);
        $this->ensureUnlocked($share, $request);

        $perPage = $this->perPage($request, 50, 100);

        $assets = $this->packageService
            ->resolveAssetQueryFor($share)
            ->paginate($perPage);

        // Deliberately limited public shape — not the management AssetResource.
        // preview_url points at the share-scoped preview endpoint (which
        // enforces unlock/revocation/expiry per request) rather than the
        // permanent, unauthenticated ilum original URL: that would defeat
        // allow_individual_downloads, download limits and revocation.
        $data = collect($assets->items())->map(fn (Asset $asset) => [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'extension' => $asset->extension,
            'mime_type' => $asset->mime_type,
            'size' => $asset->size,
            'metadata' => array_filter([
                'type' => $asset->metadata['type'] ?? null,
                'width' => $asset->metadata['width'] ?? null,
                'height' => $asset->metadata['height'] ?? null,
                'dominant_color' => $asset->metadata['dominant_color'] ?? null,
                'thumbnails' => $this->enrichedThumbnails($asset),
            ], fn ($value) => $value !== null),
            'preview_url' => $this->previewUrl($space, $share, $asset, $request),
        ])->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $assets->currentPage(),
                'last_page' => $assets->lastPage(),
                'per_page' => $assets->perPage(),
                'total' => $assets->total(),
            ],
        ], 200, self::NO_STORE);
    }

    public function download(Request $request, Space $space, string $token): JsonResponse
    {
        $share = $this->resolveShare($space, $token);
        $this->ensureUnlocked($share, $request);

        if ($share->isExhausted()) {
            return response()->json(['message' => 'The download limit for this share has been reached.'], 403, self::NO_STORE);
        }

        // Failed builds are retried by the service itself after a cooldown;
        // report the failure so pollers can stop instead of spinning.
        $package = $this->packageService->ensureFreshPackageForShare($space, $share);

        if (! $package->isDownloadable()) {
            return response()->json([
                'state' => $package->isFailed() ? 'failed' : 'building',
                'progress' => $package->progress,
            ], 202, self::NO_STORE);
        }

        // Atomic increment guarded by the limit so concurrent requests can't
        // overshoot; 0 affected rows means another request just exhausted it.
        $affected = AssetShare::query()
            ->whereKey($share->id)
            ->when(
                $share->download_limit !== null,
                fn ($query) => $query->whereRaw('download_count < download_limit')
            )
            ->increment('download_count');

        if ($affected === 0) {
            return response()->json(['message' => 'The download limit for this share has been reached.'], 403, self::NO_STORE);
        }

        $this->usage->recordEvent($space, $share, AssetShareEvent::EVENT_DOWNLOAD_PACKAGE, null, $request);

        $url = $this->delivery->packageDownloadUrl($package);

        return response()->json([
            'url' => $url['url'],
            'expires_at' => $url['expires_at']->toIso8601String(),
        ], 200, self::NO_STORE);
    }

    /**
     * Share-scoped image preview: a bounded-size derivative served only while
     * the share is accessible and unlocked. Never hands out a permanent asset
     * URL — those would outlive revocation, expiry and download limits.
     */
    public function previewAsset(Request $request, Space $space, string $token, string $asset): Response
    {
        $share = $this->resolveShare($space, $token);
        $this->ensureUnlocked($share, $request);

        /** @var Asset|null $model */
        $model = $this->packageService
            ->resolveAssetQueryFor($share)
            ->whereKey($asset)
            ->first();

        abort_unless($model !== null, 404);
        abort_unless(str_starts_with((string) $model->mime_type, 'image/'), 404);
        abort_unless($model->storage !== null && $model->path, 404);

        $transformation = app(ImageTransformationResolver::class)->resolve(['w' => 1280, 'c' => 'fit']);
        $result = app(ImageTransformationService::class)->processImage(
            app(StorageService::class)->getStorage($model->storage),
            $model->path,
            $transformation,
        );

        abort_unless((bool) $result, 404);

        return new Response($result['data'], 200, [
            'content-type' => $result['mime'],
            'content-length' => strlen($result['data']),
            // Private: cacheable by the viewer's browser only, and short-lived
            // so revocation takes effect quickly.
            'cache-control' => 'private, max-age=900',
            'x-content-type-options' => 'nosniff',
            'content-security-policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; sandbox",
        ]);
    }

    public function downloadAsset(Request $request, Space $space, string $token, string $asset): JsonResponse
    {
        $share = $this->resolveShare($space, $token);
        $this->ensureUnlocked($share, $request);

        abort_unless($share->allow_individual_downloads, 403, 'Individual downloads are disabled for this share.');

        /** @var Asset|null $model */
        $model = $this->packageService
            ->resolveAssetQueryFor($share)
            ->whereKey($asset)
            ->first();

        abort_unless($model !== null, 404);

        $this->usage->recordEvent($space, $share, AssetShareEvent::EVENT_DOWNLOAD_ASSET, $model->id, $request);

        // Single files live on the space's own storage (not the transfers
        // bucket the CloudFront /dl/* behavior fronts), so they can't reuse
        // the signed CDN path. Prefer a presigned URL on the space storage;
        // fall back to the public ilum original URL when the driver doesn't
        // support temporary URLs (e.g. local disks).
        $minutes = (int) config('asset_distribution.download_url_ttl_minutes', 15);
        $expiresAt = now()->addMinutes($minutes);

        try {
            $filesystem = app(StorageService::class)->getDefaultStorage($space);
            $url = $filesystem->temporaryUrl($model->path, $expiresAt);
        } catch (\Throwable) {
            $url = $model->getUrl();
            $expiresAt = null;
        }

        abort_unless(! empty($url), 404);

        return response()->json([
            'url' => $url,
            'expires_at' => $expiresAt?->toIso8601String(),
        ], 200, self::NO_STORE);
    }

    /**
     * Unknown tokens and revoked, expired or soft-deleted shares all yield the
     * same plain 404 so the endpoint doesn't leak share state. (An unknown
     * space id 404s one step earlier via route model binding.)
     *
     * Binds the space so space-database models resolve their connection in
     * every context (mirrors AuthenticateDataApi); the share query itself
     * already resolves via the bound `space` route parameter.
     */
    private function resolveShare(Space $space, string $token): AssetShare
    {
        app()->offsetSet('currentSpace', $space);

        $share = AssetShare::query()->where('token', $token)->first();

        abort_unless($share !== null && $share->isAccessible(), 404);

        return $share;
    }

    private function isUnlocked(AssetShare $share, Request $request): bool
    {
        if (! $share->hasPassword()) {
            return true;
        }

        $token = $request->bearerToken() ?? $request->query('access');

        return $this->accessTokens->verify($share, is_string($token) ? $token : null);
    }

    private function ensureUnlocked(AssetShare $share, Request $request): void
    {
        abort_unless($this->isUnlocked($share, $request), 403, 'This share is password protected.');
    }

    /**
     * The share page renders thumbnails via `full_path` (storage-prefixed, the
     * same shape the management AssetResource serves), so the raw stored paths
     * are enriched here rather than trusting whatever the row happens to hold.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function enrichedThumbnails(Asset $asset): ?array
    {
        $thumbnails = collect((array) ($asset->metadata['thumbnails'] ?? []))
            ->filter(fn ($thumb) => is_array($thumb) && ! empty($thumb['path']))
            ->map(fn (array $thumb) => [...$thumb, 'full_path' => $asset->storage_id.'/'.$thumb['path']])
            ->values()
            ->all();

        return $thumbnails === [] ? null : $thumbnails;
    }

    /**
     * Image previews are only rendered for image assets; other types fall back
     * to their generated thumbnails client-side. The current access token is
     * embedded for password-protected shares since <img> can't send headers.
     */
    private function previewUrl(Space $space, AssetShare $share, Asset $asset, Request $request): ?string
    {
        if (! str_starts_with((string) $asset->mime_type, 'image/')) {
            return null;
        }

        $params = ['space' => $space->id, 'token' => $share->token, 'asset' => $asset->id];

        if ($share->hasPassword()) {
            $access = $request->bearerToken() ?? $request->query('access');

            if (is_string($access) && $access !== '') {
                $params['access'] = $access;
            }
        }

        return route('mgmt.shares.assets.preview', $params);
    }

    /**
     * Briefly cached: the public show endpoint is polled by anonymous viewers
     * and a smart-collection COUNT full-scans the tenant assets table.
     */
    private function countAssets(Space $space, AssetShare $share): ?int
    {
        try {
            return Cache::remember(
                "asset-share:{$space->id}:{$share->id}:asset-count",
                60,
                fn () => $this->packageService->resolveAssetQueryFor($share)->count(),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
