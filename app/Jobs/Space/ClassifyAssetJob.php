<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\AssetClassificationRun;
use App\Models\Management\Space;
use App\Models\Space\Asset;
use App\Services\Ai\AssetClassificationService;
use App\Services\Ai\Exceptions\AiServiceException;
use App\Support\SpaceContext;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Classifies one image asset with the space's vision model and fills its
 * metadata fields. Carries the asset id, not the model: the asset lives in the
 * space database, which is only resolvable once `currentSpace` is bound.
 *
 * Requests for the same asset stay queued and run one at a time. Mass runs
 * are paced per space by the asset-classification rate limiter.
 */
class ClassifyAssetJob extends QueuedJob
{
    public $tries = 0;

    public $maxExceptions = 3;

    public $backoff = [30, 120];

    /** Fits under the default worker's 60s timeout with a margin for the retry. */
    public $timeout = 55;

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
        public ?string $runId = null,
        public ?string $altContext = null,
        public bool $decorative = false,
        public bool $highDetail = false,
    ) {}

    public function middleware(): array
    {
        return [
            new RateLimited('asset-classification'),
            (new WithoutOverlapping("{$this->space->id}:{$this->assetId}"))
                ->releaseAfter(30)
                ->expireAfter(120),
        ];
    }

    protected function execute(): void
    {
        $restore = SpaceContext::enter($this->space);

        try {
            $asset = Asset::query()->with('folder')->find($this->assetId);

            if (! $asset) {
                $this->record('skipped');

                return;
            }

            $service = app(AssetClassificationService::class);

            try {
                $config = $service->resolveVisionConfig($this->space, $this->configId);
                $outcome = $service->classify($this->space, $asset, $this->languages, $config, $this->overwrite, $this->altContext, $this->decorative, $this->highDetail);
                $this->record($outcome);
            } catch (AiServiceException $e) {
                if (! \in_array($e->reason, [
                    AiServiceException::REASON_NOT_CONFIGURED,
                    AiServiceException::REASON_PLAN_EXCLUDED,
                    AssetClassificationService::REASON_MODEL_NOT_VISION,
                ], true)) {
                    throw $e;
                }

                Log::warning('Asset classification unavailable for space', [
                    'space_id' => $this->space->id,
                    'asset_id' => $this->assetId,
                    'reason' => $e->reason,
                    'error' => $e->getMessage(),
                ]);
                $this->record('failed');
            }
        } finally {
            $restore();
        }
    }

    protected function handleFailure(\Throwable $e): void
    {
        $this->record('failed');
        Log::error('Asset classification failed', [
            'space_id' => $this->space->id,
            'asset_id' => $this->assetId,
            'error' => $e->getMessage(),
        ]);
    }

    private function record(string $outcome): void
    {
        if ($this->runId !== null) {
            AssetClassificationRun::query()->find($this->runId)?->record($outcome);
        }
    }

    public function tags(): array
    {
        return ['asset-classification', 'space:'.$this->space->id];
    }
}
