<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Ai\AssetClassificationService;
use App\Services\Ai\Exceptions\AiServiceException;
use App\Support\SpaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * Classifies one image asset with the space's vision model and fills its
 * metadata fields. Carries the asset id, not the model: the asset lives in the
 * space database, which is only resolvable once `currentSpace` is bound.
 *
 * Unique per asset so a mass run and an upload hook cannot queue the same
 * asset twice; the lock clears when the job finishes. Mass runs are paced per
 * space by the `asset-classification` rate limiter so a 2000-image library
 * does not hit the provider's request limits all at once.
 */
class ClassifyAssetJob extends QueuedJob implements ShouldBeUnique
{
    /**
     * Rate-limited releases count as attempts, so the ceiling is high and the
     * real failure budget lives in maxExceptions.
     */
    public $tries = 25;

    public $maxExceptions = 3;

    public $backoff = [30, 120];

    /** Fits under the default worker's 60s timeout with a margin for the retry. */
    public $timeout = 55;

    public $uniqueFor = 900;

    /**
     * @param  array<int, string>  $languages  language keys to fill (`_default`, `de`, ...)
     * @param  bool  $overwrite  regenerate every allowed field instead of filling empty ones
     */
    public function __construct(
        public Space $space,
        public string $assetId,
        public array $languages,
        public ?string $configId = null,
        public bool $overwrite = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->assetId;
    }

    public function middleware(): array
    {
        return [new RateLimited('asset-classification')];
    }

    protected function execute(): void
    {
        $restore = SpaceContext::enter($this->space);

        try {
            $asset = Asset::query()->with('folder')->find($this->assetId);

            if (! $asset) {
                return;
            }

            $service = app(AssetClassificationService::class);

            try {
                $config = $service->resolveVisionConfig($this->space, $this->configId);
                $service->classify($this->space, $asset, $this->languages, $config, $this->overwrite);
            } catch (AiServiceException $e) {
                // Not transient (plan, key, config): retrying would only repeat
                // the warning per asset of a mass run.
                Log::warning('Asset classification unavailable for space', [
                    'space_id' => $this->space->id,
                    'asset_id' => $this->assetId,
                    'reason' => $e->reason,
                    'error' => $e->getMessage(),
                ]);
            }
        } finally {
            $restore();
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::error('Asset classification failed', [
            'space_id' => $this->space->id,
            'asset_id' => $this->assetId,
            'error' => $e->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return ['asset-classification', 'space:'.$this->space->id];
    }
}
