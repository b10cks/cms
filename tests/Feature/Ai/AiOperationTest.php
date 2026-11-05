<?php

namespace Tests\Feature\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiOperationItemStatus;
use App\Enums\AiOperationState;
use App\Enums\AiRunState;
use App\Enums\AiThreadStatus;
use App\Jobs\DecideAiOperationGroupJob;
use App\Jobs\RunAgentJob;
use App\Models\Management\AiSchedule;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiConversation;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Models\Space\Asset;
use App\Models\Space\AssetFolder;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Services\Ai\Exceptions\ProviderCallFailed;
use App\Services\Ai\Operations\AiOperations;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Operations\Tools\ReportItemsTool;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use App\Services\Ai\Schedules\ScheduleRunner;
use App\Services\Auth\MembershipService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiOperationTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private FakeAiDriver $driver;

    private Block $page;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);
        config(['ai.operations.batch_size' => 2]);

        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => ['title' => ['type' => 'text', 'name' => 'Title'], 'summary' => ['type' => 'text', 'name' => 'Summary']],
        ]);
    }

    #[Test]
    public function an_asset_folder_freezes_with_its_descendants_and_reports_unsupported_files(): void
    {
        $root = $this->folder('Products');
        $child = $this->folder('Shoes', $root);
        $outside = $this->folder('Press');
        $this->asset('a.jpg', 'image/jpeg', $root);
        $this->asset('b.png', 'image/png', $child);
        $this->asset('manual.pdf', 'application/pdf', $child);
        $this->asset('c.jpg', 'image/jpeg', $outside);

        $operation = $this->postJson($this->url('ai/operations'), [
            'kind' => 'assets',
            'prompt' => 'Tag and organise the product images.',
            'scope' => ['type' => 'asset', 'root_id' => $root],
        ])->assertCreated()
            ->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.counts.total', 3)
            ->assertJsonPath('data.counts.supported', 2)
            ->assertJsonPath('data.counts.unsupported', 1)
            ->assertJsonPath('data.counts.groups', ['image' => 2, 'document' => 1])
            ->assertJsonPath('data.progress.reasons.skipped.unsupported_file_type', 1)
            ->json('data');

        $this->assertNotNull($operation['conversation_id']);
        $this->assertSame(['a.jpg', 'b.png', 'manual.pdf'], AiOperationItem::query()->orderBy('label')->pluck('label')->all());
        $this->assertSame(0, $this->driver->calls);
    }

    #[Test]
    public function a_scope_larger_than_the_limit_is_refused_with_its_count_and_nothing_is_frozen(): void
    {
        config(['ai.operations.max_items' => 2]);
        $this->pages(3);

        $this->postJson($this->url('ai/operations'), $this->generalPayload())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'scope_too_large')
            ->assertJsonPath('count', 3)
            ->assertJsonPath('limit', 2);

        $this->assertSame(0, AiOperation::query()->count());
        $this->assertSame(0, AiOperationItem::query()->count());
    }

    #[Test]
    public function only_the_reviewed_valid_plan_can_be_approved_and_it_is_frozen(): void
    {
        $this->space->settings = [...$this->space->settings->toArray(), 'languages' => [['code' => 'de', 'name' => 'German']]];
        $this->space->save();
        $this->pages(1);
        $id = $this->postJson($this->url('ai/operations'), [
            'kind' => 'translation',
            'prompt' => 'Translate to German.',
            'scope' => ['type' => 'content'],
        ])->assertCreated()->json('data.id');

        $this->patchJson($this->url("ai/operations/{$id}"), ['plan' => ['summary' => 'French too.', 'locales' => ['fr'], 'include' => ['missing']]])
            ->assertStatus(422)->assertJsonValidationErrors('plan.locales');

        $hash = $this->patchJson($this->url("ai/operations/{$id}"), ['plan' => ['summary' => 'German only.', 'locales' => ['de'], 'include' => ['missing', 'stale']]])
            ->assertOk()->json('data.plan_hash');

        $scope = AiOperation::query()->findOrFail($id)->counts['scope_hash'];
        $this->postJson($this->url("ai/operations/{$id}/approve"), ['plan_hash' => str_repeat('0', 64), 'max_usd' => 1, 'scope_hash' => $scope])
            ->assertStatus(409)->assertJsonPath('reason', 'plan_changed');

        Queue::fake();
        $this->postJson($this->url("ai/operations/{$id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $scope])
            ->assertOk()
            ->assertJsonPath('data.state', 'running')
            ->assertJsonPath('data.approved_plan.locales', ['de'])
            ->assertJsonPath('data.approved_plan_hash', $hash);

        $this->patchJson($this->url("ai/operations/{$id}"), ['plan' => ['summary' => 'Changed.', 'locales' => ['de'], 'include' => ['stale']]])
            ->assertStatus(409);
    }

    #[Test]
    public function a_planning_run_cannot_propose_changes_and_saves_the_draft_plan(): void
    {
        $pages = $this->pages(2);
        $id = $this->postJson($this->url('ai/operations'), $this->generalPayload())->json('data.id');
        $this->driver->push(
            $this->propose($pages[0], 'Too early'),
            FakeAiDriver::toolCall('propose_plan', ['summary' => 'Rewrite every summary in one sentence.', 'rules' => ['Keep product names.']]),
            FakeAiDriver::text('Plan ready.'),
        );

        $this->postJson($this->url("ai/operations/{$id}/plan"))->assertStatus(202);

        $this->assertNotContains('propose_update', $this->driver->toolNames[0]);
        $this->assertContains('propose_plan', $this->driver->toolNames[0]);
        $this->assertSame(0, AiChangesetItem::query()->count());
        $this->getJson($this->url("ai/operations/{$id}"))
            ->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.plan.rules', ['Keep product names.']);
    }

    #[Test]
    public function batches_work_through_every_entry_with_bounded_targets_into_one_changeset(): void
    {
        $pages = $this->pages(5);
        $this->driver->push(...array_fill(0, 20, $this->agent(fn (AiOperationItem $item) => $item->label === 'Page 2'
            ? FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $item->target_id, 'outcome' => 'unchanged']]])
            : $this->propose(Content::query()->findOrFail($item->target_id), "About {$item->label}"))));

        $operation = $this->approved($this->createGeneral());

        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->assertSame(3, AiRun::query()->where('operation_id', $operation->id)->count());
        $this->assertSame([2, 2, 1], AiRun::query()->where('operation_id', $operation->id)->orderBy('created_at')->orderBy('id')->get()->map(fn (AiRun $run) => \count($run->setup['targets']['ids']))->all());
        $this->assertSame(['unchanged' => 1, 'written' => 4], AiOperationItem::query()->get()->countBy(fn (AiOperationItem $item) => $item->status->value)->sortKeys()->all());
        $this->assertSame(1, AiChangeset::query()->count());
        $this->assertSame(4, AiChangesetItem::query()->whereNotNull('operation_item_id')->count());
        $this->assertSame(collect($pages)->pluck('id')->sort()->values()->all(), AiOperationItem::query()->pluck('target_id')->sort()->values()->all());
    }

    #[Test]
    public function an_entry_left_without_an_outcome_is_retried_then_failed_never_counted_as_done(): void
    {
        config(['ai.operations.batch_size' => 1]);
        [$done, $ignored] = $this->pages(2);
        $this->driver->push(...array_fill(0, 10, $this->agent(fn (AiOperationItem $item) => $item->target_id === $done->id
            ? $this->propose($done, 'Done')
            : FakeAiDriver::text('I looked at it.'))));

        $operation = $this->approved($this->createGeneral());

        $item = AiOperationItem::query()->where('target_id', $ignored->id)->sole();
        $this->assertSame(AiOperationItemStatus::Failed, $item->status);
        $this->assertSame('not_processed', $item->reason);
        $this->assertSame(2, $item->attempts);
        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->getJson($this->url("ai/operations/{$operation->id}"))
            ->assertJsonPath('data.progress.by_status.failed', 1)
            ->assertJsonPath('data.progress.reasons.failed.not_processed', 1);
    }

    #[Test]
    public function a_spent_budget_pauses_until_explicitly_extended_and_completed_work_is_kept(): void
    {
        $pages = $this->pages(4);
        $this->driver->push(...array_fill(0, 20, $this->agent(fn (AiOperationItem $item) => $this->propose(Content::query()->findOrFail($item->target_id), 'New', 0.03))));

        $operation = $this->approved($this->createGeneral(), 0.05);

        $this->assertSame(AiOperationState::Paused, $operation->state);
        $this->assertSame('budget', $operation->pause_reason->value);
        $this->assertSame(2, AiOperationItem::query()->where('status', 'written')->count());
        $this->assertSame(2, AiOperationItem::query()->where('status', 'pending')->count());
        $calls = $this->driver->calls;

        $this->postJson($this->url("ai/operations/{$operation->id}/resume"))->assertStatus(409)->assertJsonPath('reason', 'budget');
        $this->postJson($this->url("ai/operations/{$operation->id}/budget"), ['max_usd' => 0.01])->assertJsonValidationErrors('max_usd');
        $this->assertSame($calls, $this->driver->calls);

        $this->postJson($this->url("ai/operations/{$operation->id}/budget"), ['max_usd' => 1])
            ->assertOk()->assertJsonPath('data.state', 'completed');
        $this->assertSame(4, AiOperationItem::query()->where('status', 'written')->count());
        $this->assertSame(4, AiChangesetItem::query()->count());
        $this->assertCount(4, collect($pages));
    }

    #[Test]
    public function batches_recheck_entries_and_access_before_any_provider_call(): void
    {
        config(['ai.operations.batch_size' => 1]);
        [$kept, $deleted] = $this->pages(2);
        $this->driver->push(...array_fill(0, 5, $this->agent(fn (AiOperationItem $item) => $this->propose($kept, 'Kept'))));
        Queue::fake();
        $operation = $this->approved($this->createGeneral());
        $deleted->delete();

        (new RunAgentJob($this->space->id, AiRun::query()->where('operation_id', $operation->id)->sole()->id))->handle();
        $operation->refresh();

        $this->assertSame('deleted', AiOperationItem::query()->where('target_id', $deleted->id)->sole()->reason);
        $this->assertSame(AiOperationState::Completed, $operation->state);

        $other = $this->createGeneral();
        $hash = $this->patchJson($this->url("ai/operations/{$other->id}"), ['plan' => ['summary' => 'Again.']])->json('data.plan_hash');
        $calls = $this->driver->calls;
        app(MembershipService::class)->removeSpaceMembership($this->space, $this->user);

        $other = app(AiOperations::class)->approve($other, $this->user, $hash, 1.0);

        $this->assertSame(AiOperationState::Paused, $other->state);
        $this->assertSame('access_revoked', $other->pause_reason->value);
        $this->assertSame($calls, $this->driver->calls);
    }

    #[Test]
    public function the_scope_freezes_at_approval_and_a_scope_changed_since_its_preview_is_refused_until_refreshed(): void
    {
        [, $removed] = $this->pages(2);
        $operation = $this->createGeneral();
        $previewed = $operation->counts['scope_hash'];
        $hash = $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => ['summary' => 'Rewrite.']])->json('data.plan_hash');
        $removed->delete();
        $this->pages(1);
        Queue::fake();

        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $previewed])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'scope_changed')
            ->assertJsonPath('added', 1)
            ->assertJsonPath('removed', 1);
        $this->assertSame(AiOperationState::Draft, $operation->refresh()->state);

        $refreshed = $this->postJson($this->url("ai/operations/{$operation->id}/scope"))->assertOk()->assertJsonPath('data.counts.total', 2)->json('data.counts.scope_hash');
        $this->assertNotSame($previewed, $refreshed);
        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $previewed])
            ->assertStatus(409)->assertJsonPath('reason', 'scope_changed');
        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $refreshed])
            ->assertOk()->assertJsonPath('data.state', 'running');
        $this->assertNotContains($removed->id, AiOperationItem::query()->pluck('target_id')->all());
        $this->postJson($this->url("ai/operations/{$operation->id}/scope"))->assertStatus(409);
    }

    #[Test]
    public function resuming_a_batch_that_lost_its_worker_reuses_its_run_without_duplicating_work(): void
    {
        $this->pages(2);
        Queue::fake();
        $operation = $this->approved($this->createGeneral());
        $run = AiRun::query()->where('operation_id', $operation->id)->sole();

        $this->postJson($this->url("ai/operations/{$operation->id}/resume"))->assertOk()->assertJsonPath('data.current_run.id', $run->id);

        Queue::assertPushed(RunAgentJob::class, 2);
        $this->assertSame(1, AiRun::query()->where('operation_id', $operation->id)->count());
        $this->assertSame(2, AiOperationItem::query()->where('status', 'running')->where('run_id', $run->id)->count());
    }

    #[Test]
    public function tools_may_derive_writes_only_from_entries_of_their_batch_and_the_approved_plan(): void
    {
        $this->space->settings = [...$this->space->settings->toArray(), 'languages' => [['code' => 'de', 'name' => 'German'], ['code' => 'fr', 'name' => 'French']]];
        $this->space->save();
        config(['ai.operations.batch_size' => 1]);
        [$first, $second] = $this->pages(2);
        $id = $this->postJson($this->url('ai/operations'), ['kind' => 'translation', 'prompt' => 'Translate.', 'scope' => ['type' => 'content']])->json('data.id');
        $hash = $this->patchJson($this->url("ai/operations/{$id}"), ['plan' => ['summary' => 'German.', 'locales' => ['de'], 'include' => ['missing']]])->json('data.plan_hash');
        Queue::fake();
        $this->postJson($this->url("ai/operations/{$id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => AiOperation::query()->findOrFail($id)->counts['scope_hash']])->assertOk();

        $run = AiRun::query()->where('operation_id', $id)->sole();
        $this->assertSame(['locales' => ['de'], 'include' => ['missing']], $run->setup['translation']);
        $this->assertSame($hash, $run->setup['operation']['plan_hash']);
        $context = OperationContext::forRun($run);
        $inBatch = AiOperationItem::query()->where('status', 'running')->sole();
        $outside = $inBatch->target_id === $first->id ? $second : $first;

        $this->assertSame(['operation_item_id' => $inBatch->id], $context->derivedFrom('content', $inBatch->target_id));
        $context->ensureLocale('de');
        foreach ([fn () => $context->derivedFrom('content', $outside->id), fn () => $context->ensureLocale('fr')] as $refused) {
            try {
                $refused();
                $this->fail('The write should be refused.');
            } catch (ToolError) {
            }
        }
    }

    #[Test]
    public function a_group_accept_takes_clean_proposals_and_leaves_flagged_ones_for_individual_review(): void
    {
        $operation = $this->withProposals(3);
        AiChangesetItem::query()->where('target_type', AiChangesetItem::TARGET_DATA_ENTRY)->orderBy('id')->first()->update(['flags' => [['code' => 'uncertain', 'message' => 'The source is ambiguous.']]]);

        $group = $this->getJson($this->url("ai/operations/{$operation->id}/review"))
            ->assertOk()
            ->assertJsonPath('data.0.path', 'value')
            ->assertJsonPath('data.0.bulk', 2)
            ->assertJsonPath('data.0.individual', 1)
            ->assertJsonCount(3, 'data.0.examples')
            ->json('data.0.key');

        $this->postJson($this->url("ai/operations/{$operation->id}/review/accept"), ['group' => $group])
            ->assertOk()
            ->assertJsonPath('data.accepted', 2)
            ->assertJsonPath('data.individual', 1);

        $this->assertSame(1, AiChangesetItem::query()->where('status', AiChangesetItemStatus::Pending->value)->whereNotNull('flags')->count());
        $this->assertSame(2, AiChangesetItem::query()->where('status', AiChangesetItemStatus::Accepted->value)->count());
    }

    #[Test]
    public function asset_proposals_group_by_their_review_label_after_new_folders_and_tags(): void
    {
        $this->pages(1);
        $this->driver->push(...array_fill(0, 3, $this->agent(fn (AiOperationItem $item) => $this->propose(Content::query()->findOrFail($item->target_id), 'New'))));
        $operation = $this->approved($this->createGeneral());
        $changeset = $operation->refresh()->changeset;
        $asset = fn (string $id, array $review) => $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_UPDATE, 'target_type' => AiChangesetItem::TARGET_ASSET, 'target_id' => $id, 'target_label' => "{$id}.jpg",
            'path' => 'fields._default.alt', 'field_type' => 'textarea', 'before' => null, 'after' => 'A shoe', 'status' => AiChangesetItemStatus::Pending, 'review' => $review,
        ]);
        $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_CREATE, 'target_type' => AiChangesetItem::TARGET_ASSET_TAG, 'target_label' => 'Shoes',
            'after' => ['name' => 'Shoes'], 'status' => AiChangesetItemStatus::Pending, 'review' => ['reason' => 'Many shoes', 'examples' => ['a1', 'a2']],
        ]);
        $asset('a1', ['group' => 'Shoes on white']);
        $uncertain = $asset('a2', ['group' => 'Shoes on white', 'flags' => [['code' => 'uncertain', 'message' => 'Could be a boot.']]]);

        $groups = $this->getJson($this->url("ai/operations/{$operation->id}/review"))->assertOk()->json('data');

        $this->assertSame(['structure', 'asset|Shoes on white'], \array_slice(array_column($groups, 'key'), 0, 2));
        // New tags are approved one by one, as are flagged assets.
        $this->assertSame([0, 1], [$groups[0]['bulk'], $groups[0]['individual']]);
        $this->assertSame(['Shoes on white', 1, 1], [$groups[1]['label'], $groups[1]['bulk'], $groups[1]['individual']]);
        $this->getJson($this->url("ai/operations/{$operation->id}/review/items?".http_build_query(['group' => 'asset|Shoes on white', 'individual' => 1])))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $uncertain->id);
    }

    #[Test]
    public function an_operation_and_its_changeset_stay_private_to_its_actor(): void
    {
        $this->pages(1);
        $this->driver->push(...array_fill(0, 3, $this->agent(fn (AiOperationItem $item) => $this->propose(Content::query()->findOrFail($item->target_id), 'New'))));
        $operation = $this->approved($this->createGeneral());
        $other = User::factory()->create();
        $this->assignSpaceRole($this->space, $other, 'owner');

        $this->actingAs($other);
        $this->getJson($this->url("ai/operations/{$operation->id}"))->assertForbidden();
        $this->getJson($this->url("ai/changesets/{$operation->changeset->id}"))->assertForbidden();
    }

    #[Test]
    public function entries_arriving_after_the_freeze_are_reported_and_left_out(): void
    {
        $this->pages(2);
        $operation = $this->createGeneral();
        [$late] = $this->pages(1);

        $this->getJson($this->url("ai/operations/{$operation->id}/arrivals"))
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.entries.0.id', $late->id);
        $this->assertSame(2, AiOperationItem::query()->count());
    }

    #[Test]
    public function a_schedule_over_more_entries_than_one_run_takes_starts_an_operation_and_skips_while_it_is_open(): void
    {
        $now = now();
        $rows = [];
        foreach (range(1, 501) as $i) {
            $rows[] = [
                'id' => strtolower((string) Str::ulid()), 'block_id' => $this->page->id, 'name' => "Bulk {$i}", 'slug' => "bulk-{$i}",
                'full_slug' => "/bulk-{$i}", 'language_iso' => 'en', 'current_version_id' => strtolower((string) Str::ulid()), 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            Content::query()->toBase()->insert($chunk);
        }
        $schedule = AiSchedule::query()->create([
            'space_id' => $this->space->id, 'created_by' => $this->user->id, 'name' => 'Translate pages', 'skill' => 'translate-fields',
            'scope' => ['type' => 'content', 'filters' => ['block' => 'page']], 'cron' => '* * * * *', 'max_usd' => 0.5, 'enabled' => true,
        ]);
        Queue::fake();

        $run = app(ScheduleRunner::class)->run($schedule);

        $operation = AiOperation::query()->sole();
        $this->assertSame($schedule->id, $operation->schedule_id);
        $this->assertSame(AiOperationState::Running, $operation->state);
        $this->assertSame(501, $operation->counts['total']);
        $this->assertSame($operation->id, $run->operation_id);
        $this->assertCount(2, $run->setup['targets']['ids']);

        $this->assertNull(app(ScheduleRunner::class)->run($schedule->refresh()));
        $this->assertSame(1, AiOperation::query()->count());

        // Everyone who manages AI follows a schedule's operation from its thread; the creator's own operations stay private.
        $private = $this->createGeneral();
        $admin = User::factory()->create();
        $this->assignSpaceRole($this->space, $admin, 'admin');
        $editor = User::factory()->create();
        $this->assignSpaceRole($this->space, $editor, 'editor');
        $this->actingAs($admin)->getJson($this->url('ai/operations?conversation_id='.$operation->conversation_id))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $operation->id);
        $this->assertSame([$operation->id], array_column($this->getJson($this->url('ai/operations'))->json('data'), 'id'));
        $this->actingAs($editor)->getJson($this->url('ai/operations'))->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function ten_thousand_entries_are_each_accounted_for_once_in_bounded_batches_through_a_lazy_batch_and_a_failed_one(): void
    {
        config(['ai.operations.batch_size' => 250]);
        $now = now();
        foreach (array_chunk(range(1, 10_000), 1000) as $chunk) {
            Content::query()->toBase()->insert(array_map(fn (int $i) => [
                'id' => strtolower((string) Str::ulid()), 'block_id' => $this->page->id, 'name' => "Entry {$i}", 'slug' => "entry-{$i}",
                'full_slug' => "/entry-{$i}", 'language_iso' => 'en', 'current_version_id' => strtolower((string) Str::ulid()), 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        $failed = false;
        $this->driver->push(...array_fill(0, 400, function () use (&$failed): TurnResult {
            $open = AiOperationItem::query()->where('status', 'running')->orderBy('position')->limit(100)->get();
            $batch = $open->first()?->batch;
            if ($batch === 5 && ! $failed) {
                $failed = true;

                throw new ProviderCallFailed('The provider is down.');
            }
            // Batch 7 stops after one report and leaves 150 entries without an outcome.
            if ($open->isEmpty() || ($batch === 7 && AiOperationItem::query()->where('status', 'running')->count() === 150)) {
                return FakeAiDriver::text('Done.');
            }

            return FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => $open->map(fn (AiOperationItem $item) => $item->position % 1000 === 0
                ? ['id' => $item->target_id, 'outcome' => 'skipped', 'reason' => 'Archived page.']
                : ['id' => $item->target_id, 'outcome' => 'unchanged'])->all()]);
        }));

        $operation = $this->approved($this->createGeneral());

        $this->assertSame(AiOperationState::Paused, $operation->state);
        $this->assertSame('batch_failed', $operation->pause_reason->value);
        $this->postJson($this->url("ai/operations/{$operation->id}/resume"))->assertOk()->assertJsonPath('data.state', 'completed');

        $this->assertSame(10_000, AiOperationItem::query()->distinct()->count('target_id'));
        $this->assertSame(['skipped' => 10, 'unchanged' => 9_990], AiOperationItem::query()->get(['status'])->countBy(fn (AiOperationItem $item) => $item->status->value)->sortKeys()->all());
        // The failed batch and the 150 entries the lazy batch left were retried once, nothing more.
        $this->assertSame(400, AiOperationItem::query()->where('attempts', 1)->count());
        $this->assertSame(0, AiOperationItem::query()->where('attempts', '>', 1)->count());
        $runs = AiRun::query()->where('operation_id', $operation->id)->get();
        $this->assertSame(42, $runs->count());
        $this->assertLessThanOrEqual(250, $runs->max(fn (AiRun $run) => \count($run->setup['targets']['ids'])));
        $this->assertLessThan(150_000, $runs->max(fn (AiRun $run) => \strlen(json_encode($run->transcript))));
        $this->getJson($this->url("ai/operations/{$operation->id}"))->assertJsonPath('data.progress.owed', 0);
    }

    #[Test]
    public function a_thread_shows_its_operation_running_then_settled_with_the_shared_changeset(): void
    {
        $this->pages(2);
        Queue::fake();
        $operation = $this->createGeneral();
        // The planning turn is the thread's only run; the batches run outside it.
        $this->postJson($this->url("ai/operations/{$operation->id}/plan"))->assertStatus(202);
        $planning = AiRun::query()->where('operation_id', $operation->id)->sole();
        $planning->forceFill(['state' => AiRunState::Completed])->save();
        $operation = $this->approved($operation);
        $status = fn () => AiConversation::query()->withStatus()->findOrFail($operation->conversation_id)->status();

        $this->assertSame(AiThreadStatus::Running, $status());

        $this->driver->push(...array_fill(0, 10, $this->agent(fn (AiOperationItem $item) => $this->propose(Content::query()->findOrFail($item->target_id), 'New'))));
        (new RunAgentJob($this->space->id, AiRun::query()->where('operation_id', $operation->id)->whereKeyNot($planning->id)->sole()->id))->handle();

        $this->assertSame(AiOperationState::Completed, $operation->refresh()->state);
        $this->assertNotSame(AiThreadStatus::NeedsReview, $status());
        $this->getJson($this->url("ai/threads/{$operation->conversation_id}"))
            ->assertOk()
            ->assertJsonPath('data.turns.0.changeset.operation_id', $operation->id)
            ->assertJsonPath('data.turns.0.changeset.item_count', 2)
            ->assertJsonPath('data.turns.0.changeset.pending_count', 0)
            ->assertJsonMissingPath('data.turns.0.changeset.items');
    }

    #[Test]
    public function an_operation_without_a_planning_turn_lists_its_changeset_summary_without_items(): void
    {
        $this->pages(2);
        $this->driver->push(...array_fill(0, 10, $this->agent(fn (AiOperationItem $item) => $this->propose(Content::query()->findOrFail($item->target_id), 'New'))));
        $operation = $this->approved($this->createGeneral());

        $this->getJson($this->url("ai/operations?conversation_id={$operation->conversation_id}"))
            ->assertOk()
            ->assertJsonPath('data.0.changeset.id', $operation->changeset->id)
            ->assertJsonPath('data.0.changeset.operation_id', $operation->id)
            ->assertJsonPath('data.0.changeset.item_count', 2)
            ->assertJsonPath('data.0.changeset.pending_count', 0)
            ->assertJsonMissingPath('data.0.changeset.items');
    }

    #[Test]
    public function a_large_group_decision_runs_on_the_queue_and_takes_whole_entries(): void
    {
        config(['ai.operations.review_sync_limit' => 1]);
        $operation = $this->withProposals(3);
        $group = $this->getJson($this->url("ai/operations/{$operation->id}/review"))->json('data.0.key');

        Queue::fake();
        $this->postJson($this->url("ai/operations/{$operation->id}/review/accept"), ['group' => $group])
            ->assertStatus(202)->assertJsonPath('data.queued', 3)->assertJsonPath('data.accepted', 0);
        $this->assertSame(0, AiChangesetItem::query()->where('status', 'accepted')->count());
        Queue::assertPushed(DecideAiOperationGroupJob::class, fn (DecideAiOperationGroupJob $job) => $job->accept && $job->group === $group);
        $this->getJson($this->url("ai/operations/{$operation->id}/review"))->assertJsonPath('data.0.queued', 'accept');

        (new DecideAiOperationGroupJob($this->space->id, $operation->id, $this->user->id, $group, true))->handle();

        $this->assertSame(3, AiChangesetItem::query()->where('status', 'accepted')->count());
        $this->getJson($this->url("ai/operations/{$operation->id}/review"))->assertJsonPath('data.0.queued', null)->assertJsonPath('data.0.bulk', 0);
    }

    /** An operation whose changeset holds proposals for data entries, which have no draft. */
    private function withProposals(int $count): AiOperation
    {
        $this->pages(1);
        $this->driver->push(...array_fill(0, 3, $this->agent(fn (AiOperationItem $item) => FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $item->target_id, 'outcome' => 'unchanged']]]))));
        $operation = $this->approved($this->createGeneral());
        $source = DataSource::factory()->create();
        $changeset = AiChangeset::query()->firstOrCreate(['operation_id' => $operation->id], ['space_id' => $this->space->id, 'actor_id' => $this->user->id, 'title' => 'Operation']);

        foreach (range(1, $count) as $index) {
            $entry = DataEntry::factory()->create(['data_source_id' => $source->id, 'key' => "key{$index}", 'value' => 'Old']);
            $changeset->items()->create([
                'operation' => AiChangesetItem::OPERATION_UPDATE, 'target_type' => AiChangesetItem::TARGET_DATA_ENTRY, 'target_id' => $entry->id, 'target_label' => $entry->key,
                'path' => 'value', 'field_type' => 'text', 'before' => 'Old', 'after' => "New {$index}", 'status' => AiChangesetItemStatus::Pending,
            ]);
        }
        $changeset->refreshCounts();

        return $operation->refresh();
    }

    /** A scripted agent turn for the first entry of the running batch that has no outcome yet. */
    private function agent(Closure $decide): Closure
    {
        return function () use ($decide): TurnResult {
            $item = AiOperationItem::query()->where('status', 'running')->orderBy('position')->first();

            return $item ? $decide($item) : FakeAiDriver::text('Batch done.');
        };
    }

    private function propose(Content $content, string $summary, ?float $cost = null): TurnResult
    {
        return new TurnResult('', [new ToolCall('call_'.Str::random(6), 'propose_update', [
            'target_type' => 'content',
            'id' => $content->id,
            'version_id' => $content->current_version_id,
            'changes' => [['path' => 'summary', 'value' => $summary]],
        ])], new TurnUsage(10, 5, $cost));
    }

    /** @return array<string, mixed> */
    private function generalPayload(): array
    {
        return ['kind' => 'general', 'prompt' => 'Rewrite the summaries.', 'scope' => ['type' => 'content', 'filters' => ['block' => 'page']]];
    }

    private function createGeneral(): AiOperation
    {
        return AiOperation::query()->findOrFail($this->postJson($this->url('ai/operations'), $this->generalPayload())->assertCreated()->json('data.id'));
    }

    private function approved(AiOperation $operation, float $maxUsd = 1.0): AiOperation
    {
        $hash = $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => ['summary' => 'Rewrite each summary in one sentence.']])->assertOk()->json('data.plan_hash');
        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $hash, 'max_usd' => $maxUsd, 'scope_hash' => $operation->refresh()->counts['scope_hash']])->assertOk();

        return $operation->refresh();
    }

    /** @return list<Content> */
    private function pages(int $count): array
    {
        return array_map(fn () => $this->publishedPage('Page '.++$this->made), range(1, $count));
    }

    private function publishedPage(string $title): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => 'en',
        ]);
        $content->id = strtolower((string) Str::ulid());

        $version = ContentVersion::query()->forceCreate([
            'content_id' => $content->id,
            'content' => ['title' => $title, 'summary' => ''],
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

    private function folder(string $name, ?string $parent = null): string
    {
        $id = strtolower((string) Str::ulid());
        AssetFolder::query()->toBase()->insert(['id' => $id, 'name' => $name, 'parent_id' => $parent, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function asset(string $filename, string $mime, string $folder): void
    {
        Asset::query()->toBase()->insert([
            'id' => strtolower((string) Str::ulid()), 'filename' => $filename, 'extension' => pathinfo($filename, PATHINFO_EXTENSION),
            'mime_type' => $mime, 'storage_id' => strtolower((string) Str::ulid()), 'folder_id' => $folder, 'size' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }
}
