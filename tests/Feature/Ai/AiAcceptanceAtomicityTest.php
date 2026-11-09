<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\AcceptAiChangesetItems;
use App\Actions\Ai\RejectAiChangesetItems;
use App\Actions\Content\UpdateContent;
use App\Enums\AiChangesetItemStatus;
use App\Enums\AiChangesetStatus;
use App\Events\Space\ContentUpdated;
use App\Events\Space\SpaceModelChanged;
use App\Jobs\DecideAiOperationGroupJob;
use App\Models\Management\Space;
use App\Models\Management\Storage;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiRun;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\Space\TranslationSource;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

/**
 * Decisions run on a separate space database, so a rollback here is a real one
 * of the space connection. SQLite has one writer and no row locks: competing
 * decisions run inline at the point where the decision has read its items and
 * holds no lock yet. That proves the re-read under lock, not the blocking.
 */
class AiAcceptanceAtomicityTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private Block $page;

    private Storage $storage;

    /** Fail the Nth recording of an accepted decision, after its target was written. */
    private ?int $failAtDecision = null;

    private string $failure = 'The worker is gone.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => [
            'default_language' => 'en',
            'asset_fields' => [['key' => 'alt', 'label' => 'Alt', 'required' => false]],
        ]]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->storage = Storage::factory()->create(['space_id' => $this->space->id, 'driver' => 'local', 'state' => 'live']);
        $this->setUpSeparateSpaceDatabase($this->space);
        // Without its prefix: SQLite cannot run the asset usage JSON queries with one.
        config(['database.connections.space_test.prefix' => '']);
        DB::purge('space_test');
        $this->artisan('migrate', ['--database' => 'space_test', '--path' => 'database/migrations/spaces', '--realpath' => true]);
        $this->stubManagementAuditService();
        $this->giveSpaceAiConfig($this->space);
        $this->fakeAi();
        $this->actingAs($this->user);

        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title', 'max_length' => 60, 'translatable' => true],
                'summary' => ['type' => 'text', 'name' => 'Summary'],
            ],
        ]);

        AiChangesetItem::updating(function (AiChangesetItem $item): void {
            if ($this->failAtDecision !== null && $item->status === AiChangesetItemStatus::Accepted && --$this->failAtDecision === 0) {
                $this->failAtDecision = null;

                throw new RuntimeException($this->failure);
            }
        });
    }

    #[Test]
    public function a_failure_between_the_write_and_the_decision_rolls_both_back_and_a_retry_applies_once(): void
    {
        $content = $this->page(['title' => 'Hello', 'summary' => 'World']);
        $changeset = $this->changeset();
        $item = $this->field($changeset, $content, 'title', 'Hello', 'Hello there');
        Event::fake([ContentUpdated::class]);
        $this->failAtDecision = 1;

        $this->assertThrows(fn () => $this->accept($changeset), RuntimeException::class);

        $this->assertSame('Hello', $content->fresh()->getCurrentContent()['title']);
        $this->assertSame(1, ContentVersion::query()->where('content_id', $content->id)->count());
        $this->assertSame(AiChangesetItemStatus::Pending, $item->refresh()->status);
        $this->assertNull($item->decided_at);
        Event::assertNotDispatched(ContentUpdated::class);

        $this->assertSame(1, $this->accept($changeset)['accepted']);

        $this->assertSame('Hello there', $content->fresh()->getCurrentContent()['title']);
        $this->assertSame(2, ContentVersion::query()->where('content_id', $content->id)->count());
        $this->assertSame(AiChangesetItemStatus::Accepted, $item->refresh()->status);
        $this->assertSame($this->user->id, $item->decided_by_id);
        Event::assertDispatchedTimes(ContentUpdated::class, 1);
    }

    #[Test]
    public function model_broadcasts_wait_for_the_space_transaction_and_disappear_on_rollback(): void
    {
        $content = $this->page(['title' => 'Hello']);
        Event::fake([ContentUpdated::class, SpaceModelChanged::class]);
        $connection = $content->getConnection();

        $connection->beginTransaction();
        event(new ContentUpdated($content, $this->space));
        event(new SpaceModelChanged($this->space, 'blocks', 'updated', $this->page));
        Event::assertNothingDispatched();
        $connection->rollBack();
        Event::assertNothingDispatched();

        $connection->beginTransaction();
        event(new ContentUpdated($content, $this->space));
        event(new SpaceModelChanged($this->space, 'blocks', 'updated', $this->page));
        Event::assertNothingDispatched();
        $connection->commit();
        Event::assertDispatchedTimes(ContentUpdated::class, 1);
        Event::assertDispatchedTimes(SpaceModelChanged::class, 1);
    }

    #[Test]
    public function a_failure_while_creating_an_entry_leaves_no_row_and_a_repeat_returns_the_established_entry(): void
    {
        $changeset = $this->changeset();
        $item = $this->newEntry($changeset, 'Fresh page');
        $this->failAtDecision = 1;

        $this->assertThrows(fn () => $this->accept($changeset), RuntimeException::class);

        $this->assertSame(0, Content::query()->count());
        $this->assertSame(0, ContentVersion::query()->count());
        $this->assertSame(AiChangesetItemStatus::Pending, $item->refresh()->status);
        $this->assertNull($item->target_id);

        $this->assertSame(1, $this->accept($changeset, [$item->id])['accepted']);
        $created = Content::query()->sole();
        $this->assertSame($created->id, $item->refresh()->target_id);

        // Whether it repeats the decision after the first committed, or raced it.
        $this->assertSame(0, $this->accept($changeset, [$item->id])['accepted']);
        $this->postJson($this->url("ai/changesets/{$changeset->id}/accept"), ['item_ids' => [$item->id]])
            ->assertOk()
            ->assertJsonPath('accepted', 0)
            ->assertJsonPath('data.status', 'applied');

        $this->assertSame($created->id, Content::query()->sole()->id);
        $this->assertSame($created->id, $item->refresh()->target_id);
    }

    #[Test]
    public function two_accepts_racing_for_a_new_entry_or_block_create_one(): void
    {
        $changeset = $this->changeset();
        $entry = $this->newEntry($changeset, 'Raced page');
        $block = $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_CREATE,
            'target_type' => AiChangesetItem::TARGET_BLOCK,
            'target_label' => 'Teaser',
            'after' => ['name' => 'Teaser', 'slug' => 'teaser', 'type' => 'nestable', 'schema' => ['text' => ['type' => 'text', 'name' => 'Text']]],
            'status' => AiChangesetItemStatus::Pending,
        ]);
        $first = null;
        $this->afterPreRead(function () use ($changeset, $entry, $block, &$first): void {
            $first = $this->accept($changeset, [$entry->id, $block->id]);
        });

        $second = $this->accept($changeset, [$entry->id, $block->id]);

        $this->assertSame([2, 0], [$first['accepted'], $second['accepted']]);
        $this->assertSame(Content::query()->sole()->id, $entry->refresh()->target_id);
        $this->assertSame(Block::query()->where('slug', 'teaser')->sole()->id, $block->refresh()->target_id);
        $this->assertSame(AiChangesetStatus::Applied, $changeset->refresh()->status);
    }

    #[Test]
    public function an_accept_finds_items_a_competing_reject_closed_and_writes_nothing(): void
    {
        $content = $this->page(['title' => 'Hello', 'summary' => 'World']);
        $changeset = $this->changeset();
        $item = $this->field($changeset, $content, 'title', 'Hello', 'Hello there');
        $reviewer = $this->editor();
        $this->afterPreRead(fn () => app(RejectAiChangesetItems::class)->handle($changeset, $this->space, $reviewer, [$item->id]));

        $result = $this->accept($changeset, [$item->id]);

        $this->assertSame(['accepted' => 0, 'conflicts' => 0, 'failed' => 0, 'blocked' => 0], $result);
        $this->assertSame(AiChangesetItemStatus::Rejected, $item->refresh()->status);
        $this->assertSame($reviewer->id, $item->decided_by_id);
        $this->assertSame('Hello', $content->fresh()->getCurrentContent()['title']);
        $this->assertSame(AiChangesetStatus::Discarded, $changeset->refresh()->status);
    }

    #[Test]
    public function a_reject_finds_items_a_competing_accept_decided_and_leaves_them(): void
    {
        $content = $this->page(['title' => 'Hello', 'summary' => 'World']);
        $changeset = $this->changeset();
        $item = $this->field($changeset, $content, 'title', 'Hello', 'Hello there');
        $this->afterPreRead(fn () => $this->accept($changeset, [$item->id]));

        $rejected = app(RejectAiChangesetItems::class)->handle($changeset, $this->space, $this->editor(), [$item->id]);

        $this->assertSame(0, $rejected);
        $this->assertSame(AiChangesetItemStatus::Accepted, $item->refresh()->status);
        $this->assertSame($this->user->id, $item->decided_by_id);
        $this->assertSame('Hello there', $content->fresh()->getCurrentContent()['title']);
    }

    #[Test]
    public function a_bulk_accept_leaves_an_item_that_was_flagged_after_it_was_selected(): void
    {
        $flagged = $this->page(['title' => 'Flagged', 'summary' => 'World']);
        $clean = $this->page(['title' => 'Clean', 'summary' => 'World']);
        $changeset = $this->changeset();
        $flaggedItem = $this->field($changeset, $flagged, 'summary', 'World', 'New words');
        $cleanItem = $this->field($changeset, $clean, 'summary', 'World', 'New words');
        $this->afterPreRead(fn () => $flaggedItem->update(['flags' => [['code' => 'uncertain', 'message' => 'Two topics.']]]));

        $result = $this->accept($changeset, null);

        $this->assertSame(1, $result['accepted']);
        $this->assertSame(AiChangesetItemStatus::Pending, $flaggedItem->refresh()->status);
        $this->assertSame(AiChangesetItemStatus::Accepted, $cleanItem->refresh()->status);
        $this->assertSame('World', $flagged->fresh()->getCurrentContent()['summary']);
    }

    #[Test]
    public function a_queued_slice_and_an_http_accept_decide_an_item_once(): void
    {
        $entry = DataEntry::factory()->create(['data_source_id' => DataSource::factory()->create()->id, 'key' => 'yes', 'value' => 'World']);
        $operation = AiOperation::query()->forceCreate([
            'space_id' => $this->space->id,
            'actor_id' => $this->user->id,
            'kind' => 'translation',
            'surface' => 'bulk:content',
            'prompt' => 'Write summaries.',
            'scope' => [],
            'counts' => [],
            'state' => 'completed',
        ]);
        $changeset = $this->changeset(['operation_id' => $operation->id]);
        $item = $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_UPDATE, 'target_type' => AiChangesetItem::TARGET_DATA_ENTRY, 'target_id' => $entry->id, 'target_label' => $entry->key,
            'path' => 'value', 'field_type' => 'text', 'before' => 'World', 'after' => 'From the job', 'status' => AiChangesetItemStatus::Pending,
        ]);
        $reviewer = $this->editor();
        $this->afterPreRead(fn () => $this->accept($changeset, [$item->id], values: [$item->id => 'From the reviewer'], user: $reviewer));

        $this->withoutExceptionHandling();
        (new DecideAiOperationGroupJob($this->space->id, $operation->id, $this->user->id, 'update|data_entry|value|text', true))->handle();

        $this->assertSame(AiChangesetItemStatus::Accepted, $item->refresh()->status);
        $this->assertSame($reviewer->id, $item->decided_by_id);
        $this->assertSame('From the reviewer', $entry->fresh()->value);
    }

    #[Test]
    public function an_editors_save_before_the_lock_conflicts_by_field_and_stays(): void
    {
        $content = $this->page(['title' => 'Hello', 'summary' => 'World']);
        $changeset = $this->changeset();
        $summary = $this->field($changeset, $content, 'summary', 'World', 'AI summary');
        $title = $this->field($changeset, $content, 'title', 'Hello', 'Hello there');
        $this->afterPreRead(fn () => app(UpdateContent::class)->execute(['content' => ['title' => 'Hello', 'summary' => 'Edited by hand'], 'force' => true], $content->fresh(), $this->space, $this->user));

        $result = $this->accept($changeset, [$summary->id, $title->id]);

        $this->assertSame([1, 1], [$result['accepted'], $result['conflicts']]);
        $this->assertSame(AiChangesetItemStatus::Conflict, $summary->refresh()->status);
        $this->assertSame('Edited by hand', $summary->current);
        $this->assertSame(AiChangesetItemStatus::Accepted, $title->refresh()->status);
        $this->assertSame(['title' => 'Hello there', 'summary' => 'Edited by hand'], $content->fresh()->getCurrentContent());
        $this->assertSame(AiChangesetStatus::PartiallyApplied, $changeset->refresh()->status);
    }

    #[Test]
    public function a_reviewers_edit_is_applied_and_recorded(): void
    {
        $content = $this->page(['title' => 'Hello', 'summary' => 'World']);
        $changeset = $this->changeset();
        $item = $this->field($changeset, $content, 'title', 'Hello', 'Hello there');
        TranslationSource::query()->insert([
            'id' => strtolower((string) Str::ulid()),
            'changeset_item_id' => $item->id,
            'content_id' => null,
            'source_content_id' => $content->id,
            'field_path' => 'title',
            'source_hash' => 'source',
            'source_text' => 'Hallo',
            'target_hash' => 'proposal',
            'origin' => TranslationSource::ORIGIN_AI,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson($this->url("ai/changesets/{$changeset->id}/accept"), ['item_ids' => [$item->id], 'values' => [$item->id => 'Hello, reviewed']])
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('data.status', 'applied');

        $this->assertSame('Hello, reviewed', $content->fresh()->getCurrentContent()['title']);
        $this->assertSame('Hello, reviewed', $item->refresh()->final);
        $this->assertSame('Hello there', $item->after);
        $this->assertSame(1, TranslationSource::query()->where('changeset_item_id', $item->id)->where('origin', TranslationSource::ORIGIN_REVIEW)->where('content_id', $content->id)->count());
    }

    #[Test]
    public function an_entry_that_fails_validation_keeps_the_committed_decisions_counted(): void
    {
        $good = $this->page(['title' => 'Good', 'summary' => 'World']);
        $bad = $this->page(['title' => 'Bad', 'summary' => 'World']);
        $changeset = $this->changeset();
        $goodItem = $this->field($changeset, $good, 'summary', 'World', 'New words');
        // Longer than the title allows, as if the schema had changed since.
        $badItem = $this->field($changeset, $bad, 'title', 'Bad', str_repeat('x', 70));

        $this->assertThrows(fn () => $this->accept($changeset, [$goodItem->id, $badItem->id]), ValidationException::class);

        $this->assertSame(AiChangesetItemStatus::Accepted, $goodItem->refresh()->status);
        $this->assertSame(AiChangesetItemStatus::Pending, $badItem->refresh()->status);
        $this->assertSame('Bad', $bad->fresh()->getCurrentContent()['title']);
        $this->assertSame(AiChangesetStatus::PartiallyApplied, $changeset->refresh()->status);
        $this->assertSame(1, $changeset->pending_count);
    }

    #[Test]
    public function a_deadlock_retry_starts_over_and_counts_once(): void
    {
        $content = $this->page(['title' => 'Hello', 'summary' => 'World']);
        $asset = $this->assetWithMoveBundle();
        $changeset = $asset['changeset'];
        $item = $this->field($changeset, $content, 'title', 'Hello', 'Hello there');
        $this->failure = 'Deadlock found when trying to get lock; try restarting transaction';

        // After the planned folder, inside the asset's transaction: three, not four or five.
        $this->failAtDecision = 2;
        $result = $this->accept($changeset, [$asset['move']->id, $asset['alt']->id]);
        $this->assertSame(['accepted' => 3, 'conflicts' => 0, 'failed' => 0, 'blocked' => 0], $result);
        $this->assertSame(1, AssetFolder::query()->where('name', 'Shoes')->count());

        $this->failAtDecision = 1;
        $this->assertSame(1, $this->accept($changeset, [$item->id])['accepted']);
        $this->assertSame(2, ContentVersion::query()->where('content_id', $content->id)->count());
    }

    #[Test]
    public function an_asset_bundle_and_its_planned_folder_roll_back_together_and_then_apply_with_their_evidence(): void
    {
        ['changeset' => $changeset, 'asset' => $asset, 'folder' => $folder, 'move' => $move, 'alt' => $alt] = $this->assetWithMoveBundle();
        $this->failAtDecision = 3;

        $this->assertThrows(fn () => $this->accept($changeset, [$move->id, $alt->id]), RuntimeException::class);

        $this->assertNull($asset->fresh()->folder_id);
        $this->assertNull(data_get($asset->fresh()->data, 'fields._default.alt'));
        $this->assertSame(0, AssetFolder::query()->count());
        foreach ([$folder, $move, $alt] as $item) {
            $this->assertSame(AiChangesetItemStatus::Pending, $item->refresh()->status);
        }

        $this->assertSame(3, $this->accept($changeset, [$move->id, $alt->id])['accepted']);

        $created = AssetFolder::query()->sole();
        $this->assertSame($created->id, $folder->refresh()->target_id);
        $this->assertSame($created->id, $asset->fresh()->folder_id);
        $this->assertSame('White sneaker', $asset->fresh()->data['fields']['_default']['alt']);
        $this->assertSame('image', $alt->refresh()->review['evidence']['basis']);
        $this->assertSame(AiChangesetStatus::Applied, $changeset->refresh()->status);
    }

    #[Test]
    public function half_a_bundle_never_applies_and_a_rejected_folder_stops_the_move_that_raced_it(): void
    {
        ['changeset' => $changeset, 'asset' => $asset, 'folder' => $folder, 'move' => $move, 'alt' => $alt] = $this->assetWithMoveBundle();

        $this->assertSame(['accepted' => 0, 'conflicts' => 0, 'failed' => 0, 'blocked' => 1], $this->accept($changeset, [$alt->id]));
        $this->assertNull(data_get($asset->fresh()->data, 'fields._default.alt'));

        $this->afterPreRead(fn () => app(RejectAiChangesetItems::class)->handle($changeset, $this->space, $this->user, [$folder->id]));

        $result = $this->accept($changeset, [$move->id, $alt->id]);

        $this->assertSame(0, $result['accepted']);
        $this->assertSame(0, AssetFolder::query()->count());
        $this->assertNull($asset->fresh()->folder_id);
        $this->assertSame(AiChangesetItemStatus::Conflict, $move->refresh()->status);
        $this->assertSame('dependency_rejected', $move->review['problem']['code']);
    }

    #[Test]
    public function two_assets_moving_into_one_proposed_folder_create_it_once_when_they_race(): void
    {
        ['changeset' => $changeset, 'folder' => $folder, 'move' => $move, 'alt' => $alt] = $this->assetWithMoveBundle();
        $other = $this->asset();
        $otherMove = $this->assetItem($changeset, $other, 'folder_id', null, "new:{$folder->id}");
        $first = null;
        $this->afterPreRead(function () use ($changeset, $move, $alt, &$first): void {
            $first = $this->accept($changeset, [$move->id, $alt->id]);
        });

        $second = $this->accept($changeset, [$otherMove->id, $move->id, $alt->id]);

        $this->assertSame([3, 1], [$first['accepted'], $second['accepted']]);
        $created = AssetFolder::query()->sole();
        $this->assertSame($created->id, $other->fresh()->folder_id);
    }

    /**
     * Run the competitor once, right after a decision's unlocked read of its
     * items, when it has read them and holds no lock yet.
     */
    private function afterPreRead(callable $competitor): void
    {
        DB::connection('space_test')->listen(function (QueryExecuted $query) use (&$competitor): void {
            if ($competitor !== null && str_contains($query->sql, 'ai_changeset_items') && str_contains($query->sql, '"status" in')) {
                $run = $competitor;
                $competitor = null;
                $run();
            }
        });
    }

    /**
     * @param  list<string>|null  $ids
     * @param  array<string, string>  $values
     * @return array{accepted: int, conflicts: int, failed: int, blocked: int}
     */
    private function accept(AiChangeset $changeset, ?array $ids = null, array $values = [], ?User $user = null): array
    {
        return app(AcceptAiChangesetItems::class)->handle($changeset, $this->space, $user ?? $this->user, $ids, values: $values);
    }

    private function editor(): User
    {
        $user = User::factory()->create();
        $this->assignSpaceRole($this->space, $user, 'owner');

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function changeset(array $attributes = []): AiChangeset
    {
        return AiChangeset::query()->create(['space_id' => $this->space->id, 'actor_id' => $this->user->id, 'title' => 'Proposed changes', ...$attributes]);
    }

    private function field(AiChangeset $changeset, Content $content, string $path, mixed $before, mixed $after): AiChangesetItem
    {
        return $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_UPDATE,
            'target_type' => AiChangesetItem::TARGET_CONTENT,
            'target_id' => $content->id,
            'target_label' => $content->name,
            'path' => $path,
            'field_type' => 'text',
            'before' => $before,
            'after' => $after,
            'status' => AiChangesetItemStatus::Pending,
        ]);
    }

    private function newEntry(AiChangeset $changeset, string $name): AiChangesetItem
    {
        return $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_CREATE,
            'target_type' => AiChangesetItem::TARGET_CONTENT,
            'target_label' => $name,
            'after' => ['block_id' => $this->page->id, 'name' => $name, 'slug' => Str::slug($name), 'parent_id' => null, 'language_iso' => 'en', 'content' => ['title' => $name, 'summary' => 'New']],
            'status' => AiChangesetItemStatus::Pending,
        ]);
    }

    /**
     * A proposal moving an asset into a folder the plan approved, with alt text.
     *
     * @return array{changeset: AiChangeset, asset: Asset, folder: AiChangesetItem, move: AiChangesetItem, alt: AiChangesetItem}
     */
    private function assetWithMoveBundle(): array
    {
        $run = AiRun::query()->forceCreate([
            'space_id' => $this->space->id,
            'actor_id' => $this->user->id,
            'surface' => 'bulk:assets',
            'state' => 'completed',
            'setup' => '{}',
            'transcript' => '[]',
        ]);
        $changeset = $this->changeset(['run_id' => $run->id]);
        $folder = $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_CREATE,
            'target_type' => AiChangesetItem::TARGET_ASSET_FOLDER,
            'target_label' => 'Shoes',
            'after' => ['name' => 'Shoes', 'parent' => null],
            'status' => AiChangesetItemStatus::Pending,
        ]);
        $run->setup = ['plan' => ['assets' => ['move' => true, 'approved_structure' => [$folder->id]]]];
        $run->save();

        $asset = $this->asset();
        $review = ['bundle' => 'move-1', 'evidence' => ['basis' => 'image', 'run_id' => $run->id, 'message' => 2, 'sha256' => 'abc']];

        return [
            'changeset' => $changeset,
            'asset' => $asset,
            'folder' => $folder,
            'move' => $this->assetItem($changeset, $asset, 'folder_id', null, "new:{$folder->id}", $review),
            'alt' => $this->assetItem($changeset, $asset, 'fields._default.alt', null, 'White sneaker', $review),
        ];
    }

    private function asset(): Asset
    {
        return Asset::factory()->create(['storage_id' => $this->storage->id, 'folder_id' => null, 'data' => [], 'tags' => [], 'metadata' => []]);
    }

    /** @param  array<string, mixed>|null  $review */
    private function assetItem(AiChangeset $changeset, Asset $asset, string $path, mixed $before, mixed $after, ?array $review = null): AiChangesetItem
    {
        return $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_UPDATE,
            'target_type' => AiChangesetItem::TARGET_ASSET,
            'target_id' => $asset->id,
            'target_label' => $asset->filename,
            'path' => $path,
            'before' => $before,
            'after' => $after,
            'review' => $review,
            'status' => AiChangesetItemStatus::Pending,
        ]);
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    /** @param  array<string, mixed>  $values */
    private function page(array $values): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => $values['title'],
            'slug' => Str::slug($values['title']).'-'.Str::lower(Str::random(4)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => 'en',
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => $values,
            'created_by_id' => $this->user->id,
            'published_by_id' => $this->user->id,
            'published_at' => Carbon::parse('2026-01-01 10:00:00'),
        ]);

        $content->current_version_id = $version->id;
        $content->published_version_id = $version->id;
        $content->published_at = $version->published_at;
        $content->first_published_at = $version->published_at;
        $content->save();

        return $content->fresh();
    }
}
