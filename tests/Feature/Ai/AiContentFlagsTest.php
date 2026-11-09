<?php

namespace Tests\Feature\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\Changesets\ContentFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

/** Open conflicts show where people publish, until a person decides them. */
class AiContentFlagsTest extends TestCase
{
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Block $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        Sanctum::actingAs($this->user);

        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root',
            'schema' => ['title' => ['type' => 'text', 'name' => 'Title']],
        ]);
    }

    #[Test]
    public function an_open_conflict_is_counted_for_the_tree_and_named_at_publish(): void
    {
        $entry = $this->entry();
        $conflict = $this->item($entry, AiChangesetItemStatus::Conflict);
        $this->item($this->entry(), AiChangesetItemStatus::Accepted);

        $this->getJson($this->url('ai/content-flags'))->assertOk()->assertExactJson(['data' => [$entry->id => ['conflicts' => 1]]]);
        $this->getJson($this->url('ai/content-flags/details').'?ids[]='.$entry->id)
            ->assertOk()
            ->assertJsonPath('data.0.flags', [['kind' => 'conflict', 'path' => 'title', 'field' => 'Title', 'thread_id' => null]]);

        $conflict->update(['status' => AiChangesetItemStatus::Rejected]);
        $this->getJson($this->url('ai/content-flags'))->assertExactJson(['data' => []]);
    }

    #[Test]
    public function the_figures_cost_one_query_however_many_entries_have_conflicts(): void
    {
        $this->item($this->entry(), AiChangesetItemStatus::Conflict);
        $connection = DB::connection((new AiChangesetItem)->getConnectionName());
        $count = function () use ($connection) {
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            app(ContentFlags::class)->counts();

            return \count($connection->getQueryLog());
        };

        $this->assertSame(1, $count());
        $this->item($this->entry(), AiChangesetItemStatus::Conflict);
        $this->item($this->entry(), AiChangesetItemStatus::Conflict);
        $this->assertSame(1, $count());
    }

    private function item(Content $entry, AiChangesetItemStatus $status): AiChangesetItem
    {
        $changeset = AiChangeset::query()->create(['space_id' => $this->space->id, 'actor_id' => $this->user->id, 'title' => 'Edits']);

        return $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_UPDATE, 'target_type' => AiChangesetItem::TARGET_CONTENT, 'target_id' => $entry->id,
            'path' => 'title', 'before' => 'Old', 'after' => 'New', 'status' => $status,
        ]);
    }

    private function entry(): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id, 'name' => 'Entry '.Str::random(4), 'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)), 'language_iso' => 'en',
        ]);
        $content->id = strtolower((string) Str::ulid());
        $content->current_version_id = ContentVersion::query()->forceCreate(['content_id' => $content->id, 'content' => ['title' => 'Old'], 'created_by_id' => $this->user->id])->id;
        $content->save();

        return $content->fresh();
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }
}
