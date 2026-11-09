<?php

namespace Tests\Feature\Jobs\Space;

use App\Jobs\Space\SignalPublishedAssetUsageJob;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\Asset;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\Enums\TriggerType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(SignalPublishedAssetUsageJob::class)]
class SignalPublishedAssetUsageJobTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Block $block;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->create();
        $storage = Storage::factory()->create(['space_id' => $this->space->id, 'is_default' => true, 'driver' => 'local', 'state' => 'live']);
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);

        $this->block = Block::query()->create(['external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root']);
        $this->asset = Asset::factory()->create(['storage_id' => $storage->id]);
    }

    #[Test]
    public function it_signals_live_entries_showing_the_asset_and_their_translations(): void
    {
        $live = $this->entry('live', publishedAssets: [$this->asset->id]);
        $overlay = $this->entry('live-de', publishedAssets: [], parent: $live, language: 'de');
        $this->entry('draft-only', publishedAssets: [], currentAssets: [$this->asset->id]);
        $this->entry('unpublished', publishedAssets: [$this->asset->id], live: false);
        $this->entry('unrelated', publishedAssets: []);

        $signalled = [];
        $this->mock(AutomationEngine::class, function (MockInterface $mock) use (&$signalled): void {
            $mock->shouldReceive('processTrigger')
                ->with(TriggerType::CONTENT_PUBLISHED, \Mockery::type('array'))
                ->andReturnUsing(function (TriggerType $type, array $context) use (&$signalled): void {
                    $signalled[$context['record_id']] = [$context['reason'], $context['asset_ids']];
                });
        });

        (new SignalPublishedAssetUsageJob($this->space, [$this->asset->id]))->handle();

        $this->assertEqualsCanonicalizing([$live->id, $overlay->id], array_keys($signalled));
        $this->assertSame(['asset_changed', [$this->asset->id]], $signalled[$live->id]);
    }

    #[Test]
    public function editing_a_published_asset_queues_the_signal_only_when_an_automation_listens(): void
    {
        $this->entry('live', publishedAssets: [$this->asset->id]);
        $listening = [];
        $this->mock(AutomationEngine::class, function (MockInterface $mock) use (&$listening): void {
            $mock->shouldReceive('activeTriggerTypes')->andReturnUsing(function () use (&$listening): array {
                return $listening;
            });
        });
        Queue::fake();

        $this->asset->update(['data' => ['fields' => ['_default' => ['alt' => 'First']]]]);
        Queue::assertNotPushed(SignalPublishedAssetUsageJob::class);

        $listening = [TriggerType::CONTENT_PUBLISHED->value];
        $this->asset->update(['data' => ['fields' => ['_default' => ['alt' => 'Second']]]]);
        Queue::assertPushed(
            SignalPublishedAssetUsageJob::class,
            fn (SignalPublishedAssetUsageJob $job) => $job->space->is($this->space) && $job->assetIds === [$this->asset->id],
        );
    }

    /**
     * @param  list<string>  $publishedAssets
     * @param  list<string>  $currentAssets
     */
    private function entry(
        string $slug,
        array $publishedAssets,
        array $currentAssets = [],
        ?Content $parent = null,
        string $language = 'en',
        bool $live = true,
    ): Content {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->block->id,
            'name' => $slug,
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => $language,
            'i18n_parent_id' => $parent?->id,
            'settings' => [],
        ]);
        $content->id = strtolower((string) Str::ulid());

        $published = $this->version($content, $publishedAssets, now());
        $current = $currentAssets === [] ? $published : $this->version($content, $currentAssets, null);

        $content->forceFill([
            'published_version_id' => $published->id,
            'current_version_id' => $current->id,
            'published_at' => $live ? now() : null,
        ])->save();

        return $content;
    }

    /** @param  list<string>  $assetIds */
    private function version(Content $content, array $assetIds, mixed $publishedAt): ContentVersion
    {
        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => ['title' => $content->name],
            'published_at' => $publishedAt,
        ]);
        ContentVersion::query()->whereKey($version->id)->update(['asset_ids' => $assetIds]);

        return $version;
    }
}
