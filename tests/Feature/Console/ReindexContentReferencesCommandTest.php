<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ReindexContentReferencesCommand;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

#[CoversClass(ReindexContentReferencesCommand::class)]
class ReindexContentReferencesCommandTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Block $block;

    protected function setUp(): void
    {
        parent::setUp();

        $this->space = Space::factory()->create();
        $this->setUpSpaceTesting($this->space);

        $this->block = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
        ]);
    }

    #[Test]
    public function it_rebuilds_stale_asset_ids_including_trashed_entries_and_bumps_the_delivery_revision(): void
    {
        $assetId = strtolower((string) Str::ulid());
        $live = $this->createContentReferencing($assetId);
        $trashed = $this->createContentReferencing($assetId);
        $trashed->delete();
        ContentVersion::query()->toBase()->update(['asset_ids' => '[]']);

        $this->artisan('content:reindex-references', ['--space' => $this->space->id])
            ->expectsOutputToContain('2 version(s) updated')
            ->assertSuccessful();

        $this->assertSame([$assetId], ContentVersion::query()->find($live->current_version_id)->asset_ids);
        $this->assertSame([$assetId], ContentVersion::query()->find($trashed->current_version_id)->asset_ids);
        $this->assertNotNull($this->space->fresh()->content_updated_at);
    }

    #[Test]
    public function it_leaves_correct_rows_and_the_delivery_revision_alone(): void
    {
        $this->createContentReferencing(strtolower((string) Str::ulid()));

        $this->artisan('content:reindex-references', ['--space' => $this->space->id])
            ->expectsOutputToContain('0 version(s) updated')
            ->assertSuccessful();

        $this->assertNull($this->space->fresh()->content_updated_at);
    }

    private function createContentReferencing(string $assetId): Content
    {
        $slug = strtolower((string) Str::ulid());
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->block->id,
            'name' => "Page {$slug}",
            'slug' => $slug,
            'full_slug' => "/{$slug}",
            'language_iso' => 'en',
            'settings' => [],
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => ['hero' => ['type' => 'asset', 'id' => $assetId]],
        ]);

        $content->current_version_id = $version->id;
        $content->save();

        return $content;
    }
}
