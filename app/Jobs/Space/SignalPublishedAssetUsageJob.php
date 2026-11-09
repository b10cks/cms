<?php

namespace App\Jobs\Space;

use App\Jobs\QueuedJob;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Automation\AutomationContextFactory;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\Enums\TriggerType;
use App\Support\SpaceContext;
use Illuminate\Support\Facades\Log;

/**
 * Tells `content_published` automations about live entries whose delivered
 * payload changed because an asset they show changed. Frontends that cache
 * pages per entry (and invalidate them from a publish webhook) need this, as
 * the entry itself was not republished.
 *
 * Nothing is republished: no version is written and `published_at` stays put,
 * so pending drafts stay drafts. Each signal carries `reason: asset_changed`
 * and the `asset_ids`, so automations can tell it apart from a publish.
 */
class SignalPublishedAssetUsageJob extends QueuedJob
{
    public $tries = 1;

    /** @param  list<string>  $assetIds */
    public function __construct(
        public readonly Space $space,
        public readonly array $assetIds,
    ) {
    }

    /**
     * Queue a signal for the assets after the current transaction commits.
     * Skipped when the space has no active `content_published` automation,
     * which is the only thing listening.
     *
     * @param  list<string>  $assetIds
     */
    public static function dispatchFor(Space $space, array $assetIds): void
    {
        $listening = \in_array(
            TriggerType::CONTENT_PUBLISHED->value,
            app(AutomationEngine::class)->activeTriggerTypes($space->id),
            true,
        );

        if ($listening && $assetIds !== []) {
            static::dispatch($space, array_values(array_unique($assetIds)))->afterCommit();
        }
    }

    protected function execute(): void
    {
        $restore = SpaceContext::enter($this->space);

        try {
            $engine = app(AutomationEngine::class);
            $contexts = app(AutomationContextFactory::class);

            foreach ($this->affectedEntries() as $content) {
                $record = $contexts->normalizeSnapshot($content->attributesToArray());
                $context = $contexts->forModelEvent($content, TriggerType::CONTENT_PUBLISHED, $record, $record, [], $this->space);

                $engine->processTrigger(TriggerType::CONTENT_PUBLISHED, [
                    ...$context,
                    'reason' => 'asset_changed',
                    'asset_ids' => $this->assetIds,
                ]);
            }
        } finally {
            $restore();
        }
    }

    /**
     * Live entries whose published version shows one of the assets, plus their
     * live translations: an overlay translation inherits the asset without
     * referencing it in its own version.
     *
     * @return iterable<Content>
     */
    private function affectedEntries(): iterable
    {
        $ids = Content::query()
            ->published()
            ->whereIn('published_version_id', ContentVersion::query()->referencingAssets($this->assetIds)->select('id'))
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [];
        }

        return Content::query()
            ->published()
            ->where(fn ($query) => $query->whereIn('id', $ids)->orWhereIn('i18n_parent_id', $ids))
            ->lazyById(200);
    }

    protected function handleFailure(\Throwable $e): void
    {
        Log::warning('Failed to signal content for changed assets', [
            'space_id' => $this->space->id,
            'asset_ids' => $this->assetIds,
            'error' => $e->getMessage(),
        ]);
    }
}
