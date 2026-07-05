<?php

namespace App\Services\Asset;

use App\Models\Space\AssetPackage;
use Aws\CloudFront\UrlSigner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Issues the actual download URL for a built asset package.
 *
 * Preferred path: a CloudFront-signed URL on the dedicated download
 * distribution (`/dl/{spaceId}/{packageId}/{filename}`), whose access logs are
 * ingested into `space_download_usage_hourly` for traffic metering. The
 * distribution's `/dl/*` behavior must front the transfers bucket and map
 * `/dl/...` onto the `packages/...` object keys (origin path or a viewer
 * request function stripping `/dl` -> `/packages`); the package zip is stored
 * at `packages/{spaceId}/{packageId}/{filename}` so the mapping is 1:1.
 *
 * Fallback (dev / unconfigured): a presigned S3 URL on the transfers disk —
 * functional, but bypasses download metering.
 */
class ShareDeliveryService
{
    private const string PACKAGE_PREFIX = 'packages/';

    public function isCloudfrontConfigured(): bool
    {
        $signing = config('services.cloudfront.signing', []);

        return ! empty($signing['key_pair_id'])
            && ! empty($signing['private_key'])
            && ! empty($signing['download_base_url']);
    }

    /**
     * @return array{url: string, expires_at: Carbon}
     */
    public function packageDownloadUrl(AssetPackage $package, ?int $minutes = null): array
    {
        if (empty($package->s3_path)) {
            throw new \RuntimeException("Package has no stored file: {$package->id}");
        }

        $minutes ??= (int) config('asset_distribution.download_url_ttl_minutes', 15);
        $expiresAt = now()->addMinutes($minutes);

        $url = $this->signedUrl($package->s3_path, $expiresAt->getTimestamp())
            ?? Storage::disk('transfers')->temporaryUrl($package->s3_path, $expiresAt);

        return [
            'url' => $url,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * CloudFront-signed URL for a transfers-disk object under `packages/`,
     * or null when signing is not configured / fails.
     */
    private function signedUrl(string $s3Path, int $expiresTimestamp): ?string
    {
        if (! $this->isCloudfrontConfigured() || ! str_starts_with($s3Path, self::PACKAGE_PREFIX)) {
            return null;
        }

        try {
            $signing = config('services.cloudfront.signing');

            $base = rtrim($signing['download_base_url'], '/');
            $path = substr($s3Path, strlen(self::PACKAGE_PREFIX));
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

            $signer = new UrlSigner($signing['key_pair_id'], $signing['private_key']);

            return $signer->getSignedUrl("{$base}/dl/{$encodedPath}", $expiresTimestamp);
        } catch (\Throwable $e) {
            Log::error('Failed to create CloudFront signed download URL', [
                's3_path' => $s3Path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
