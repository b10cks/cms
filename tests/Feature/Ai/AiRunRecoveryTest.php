<?php

namespace Tests\Feature\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiOperationItemStatus;
use App\Enums\AiOperationPauseReason;
use App\Enums\AiOperationState;
use App\Enums\AiRunState;
use App\Events\Ai\AiRunEventsAvailable;
use App\Jobs\RunAgentJob;
use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Models\Space\AiRunEvent;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Operations\AiOperations;
use App\Services\Ai\Run\RunEventType;
use App\Services\Ai\Run\RunLease;
use App\Services\Ai\Run\RunLeaseLost;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use App\Services\Auth\MembershipService;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

/**
 * A worker's death is simulated at each boundary: a faked queue is a dispatch
 * that never arrived, a generator left suspended is a worker holding its lease,
 * and traveling past the lease is that worker being gone.
 */
class AiRunRecoveryTest extends TestCase
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
    public function a_batch_whose_dispatch_never_arrived_recovers_once_and_finishes_within_its_frozen_scope(): void
    {
        $this->pages(3);
        $operation = $this->approvedWithLostDispatches();
        $approvedAt = $operation->approved_at;
        $this->publishedPage('Joined after approval');

        $this->sweep();
        Queue::assertPushed(RunAgentJob::class, 1);

        $this->pastGrace();
        $this->sweep();
        $this->sweep();
        Queue::assertPushed(RunAgentJob::class, 2);

        $this->restoreQueue();
        $this->scriptProposals();
        $this->pastGrace();
        $this->sweep();

        $operation->refresh();
        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->assertEquals($approvedAt, $operation->approved_at);
        $this->assertSame(3, AiOperationItem::query()->count());
        $this->assertSame(3, AiOperationItem::query()->where('status', 'written')->count());
        $this->assertSame(3, AiChangesetItem::query()->count());
        $this->assertSame(2, AiRun::query()->where('operation_id', $operation->id)->count());
    }

    #[Test]
    public function a_crash_between_claiming_a_batch_and_starting_its_run_recovers_the_same_claim(): void
    {
        $this->pages(3);
        $operation = $this->createGeneral();
        $hash = $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => ['summary' => 'Rewrite.']])->json('data.plan_hash');
        $this->partialMock(AiRunService::class, fn ($mock) => $mock->shouldReceive('start')->once()->andThrow(new RuntimeException('worker died')));
        $this->app->forgetInstance(AiOperations::class);

        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $operation->refresh()->counts['scope_hash']])->assertServerError();

        $this->assertSame(0, AiRun::query()->count());
        $this->assertSame(2, AiOperationItem::query()->where('status', 'running')->where('batch', 1)->count());

        $this->app->forgetInstance(AiRunService::class);
        $this->app->forgetInstance(AiOperations::class);
        $this->scriptProposals();
        $this->sweep();

        $this->assertSame(AiOperationState::Completed, $operation->refresh()->state);
        $this->assertSame(2, $operation->batch_count);
        $this->assertSame(3, AiChangesetItem::query()->count());
    }

    #[Test]
    public function a_finished_run_nobody_settled_and_a_settled_batch_nobody_followed_both_continue(): void
    {
        config(['ai.operations.batch_size' => 1]);
        $this->pages(3);
        $operation = $this->approvedWithLostDispatches();
        $this->scriptProposals();

        // The worker dies after the run finished, before it hands back to the operation.
        $first = AiRun::query()->findOrFail($operation->current_run_id);
        $this->drain(app(AiRunService::class)->advance($first));
        $this->assertSame(AiRunState::Completed, $first->refresh()->state);
        $this->assertSame(0.03, $this->settledSpendAfterSweep($operation));
        $second = $operation->refresh()->current_run_id;
        $this->assertNotSame($first->id, $second);

        // The worker dies after settling, before it starts the next batch.
        $this->drain(app(AiRunService::class)->advance(AiRun::query()->findOrFail($second)));
        $operation->forceFill(['current_run_id' => null])->save();

        $this->restoreQueue();
        $this->sweep();

        $this->assertSame(AiOperationState::Completed, $operation->refresh()->state);
        $this->assertSame(3, $operation->batch_count);
        $this->assertSame(3, AiOperationItem::query()->where('status', 'written')->where('attempts', 0)->count());
        $this->assertSame(3, AiChangesetItem::query()->count());
    }

    #[Test]
    public function a_live_worker_is_left_alone_and_a_dead_one_is_continued_from_its_checkpoint(): void
    {
        [$first, $second] = $this->pages(2);
        $operation = $this->approvedWithLostDispatches();
        $run = AiRun::query()->findOrFail($operation->current_run_id);
        $this->driver->push($this->propose($first), $this->propose($second), FakeAiDriver::text('Batch done.'));

        // Suspended right after the first proposal: this worker holds the lease.
        $worker = app(AiRunService::class)->advance($run);
        while ($worker->current()->type !== RunEventType::Proposal) {
            $worker->next();
        }

        $this->pastGrace();
        $this->sweep();
        Queue::assertPushed(RunAgentJob::class, 1);
        $this->assertSame(1, $this->driver->calls);

        $this->travel((int) config('ai.runs.lease_seconds') + 1)->seconds();
        $this->restoreQueue();
        $this->sweep();

        $run->refresh();
        $this->assertSame(AiRunState::Completed, $run->state);
        $this->assertSame(1, $run->interruptions);
        $this->assertSame(3, $this->driver->calls);
        $this->assertSame(2, AiChangesetItem::query()->count());
        $this->assertSame(AiOperationState::Completed, $operation->refresh()->state);
    }

    #[Test]
    public function a_late_redelivery_of_a_dead_workers_job_does_not_fail_the_recovered_run(): void
    {
        $this->pages(1);
        $operation = $this->approvedWithLostDispatches();
        $run = AiRun::query()->findOrFail($operation->current_run_id);

        (new RunAgentJob($run->space_id, $run->id))->failed(new MaxAttemptsExceededException('Attempted too many times.'));

        $this->assertSame(AiRunState::Running, $run->refresh()->state);
        $this->assertSame(AiOperationState::Running, $operation->refresh()->state);
    }

    #[Test]
    public function an_ordinary_job_failure_cannot_fail_a_run_another_worker_is_advancing(): void
    {
        $this->pages(1);
        $operation = $this->approvedWithLostDispatches();
        $run = AiRun::query()->findOrFail($operation->current_run_id);
        $run->forceFill(['lease_owner' => 'other-worker', 'lease_expires_at' => now()->addMinute()])->save();

        (new RunAgentJob($run->space_id, $run->id))->failed(new RuntimeException('Something else broke.'));

        $this->assertSame(AiRunState::Running, $run->refresh()->state);
    }

    #[Test]
    public function a_run_that_keeps_losing_its_worker_fails_without_another_provider_call_and_keeps_proposals(): void
    {
        [$first] = $this->pages(2);
        $operation = $this->approvedWithLostDispatches();
        $run = AiRun::query()->findOrFail($operation->current_run_id);
        $this->driver->push($this->propose($first), function () {
            throw new RuntimeException('The worker hit its timeout here.');
        });

        try {
            $this->drain(app(AiRunService::class)->advance($run));
            $this->fail('The second turn should have stopped the worker.');
        } catch (RuntimeException) {
        }
        $this->assertSame(2, $this->driver->calls);

        // The worker's timeout handler frees the run; each kill counts.
        foreach (range(1, (int) config('ai.runs.max_interruptions')) as $kill) {
            RunLease::acquire($run->refresh(), 60);
            (new RunAgentJob($run->space_id, $run->id))->failed(new TimeoutExceededException('Timed out.'));
            $this->assertNull($run->refresh()->lease_owner);
            $this->assertSame(AiRunState::Running, $run->state);
        }

        $this->restoreQueue();
        $this->pastGrace();
        $this->sweep();

        $run->refresh();
        $this->assertSame(AiRunState::Failed, $run->state);
        $this->assertSame('background_failed', $run->error);
        $this->assertSame(2, $this->driver->calls);
        $operation->refresh();
        $this->assertSame(AiOperationState::Paused, $operation->state);
        $this->assertSame(AiOperationPauseReason::BatchFailed, $operation->pause_reason);
        $this->assertSame(1, AiOperationItem::query()->where('status', 'written')->count());
        $this->assertSame(1, AiChangesetItem::query()->count());
    }

    #[Test]
    public function a_stalled_workers_timeout_leaves_the_lease_of_the_worker_that_took_over(): void
    {
        $this->pages(1);
        $operation = $this->approvedWithLostDispatches();
        $run = AiRun::query()->findOrFail($operation->current_run_id);
        RunLease::acquire($run, 60);

        // This process stalled past its lease, and another one took the run over.
        $run->forceFill(['lease_owner' => 'new-owner', 'lease_expires_at' => now()->addMinutes(5)])->save();
        (new RunAgentJob($run->space_id, $run->id))->failed(new TimeoutExceededException('Timed out.'));

        $run->refresh();
        $this->assertSame('new-owner', $run->lease_owner);
        $this->assertSame(0, $run->interruptions);
    }

    #[Test]
    public function a_crash_while_storing_an_event_leaves_no_event_above_the_counter(): void
    {
        $this->pages(1);
        $operation = $this->approvedWithLostDispatches();
        $run = AiRun::query()->findOrFail($operation->current_run_id);
        AiRunEvent::created(fn () => throw new RuntimeException('The worker died here.'));

        try {
            $this->drain(app(AiRunService::class)->advance($run));
            $this->fail('Storing the event should have stopped the worker.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $run->refresh()->event_seq);
        $this->assertSame(0, AiRunEvent::query()->where('run_id', $run->id)->count());
    }

    #[Test]
    public function a_worker_that_lost_its_lease_as_a_tool_starts_writes_nothing_of_it(): void
    {
        [$page] = $this->pages(1);
        $run = AiRun::query()->findOrFail($this->approvedWithLostDispatches()->current_run_id);
        $this->driver->push($this->propose($page));

        // No time passes, so no heartbeat could notice. The worker wakes with its proposal still to write.
        Event::listen(AiRunEventsAvailable::class, function (AiRunEventsAvailable $event) use ($run) {
            if ($event->type === RunEventType::ToolStarted->value) {
                $this->takeOver($run);
            }
        });

        $this->assertLeaseLost($run);

        $this->assertSame(0, AiChangesetItem::query()->count());
        $this->assertSame(AiOperationItemStatus::Running, AiOperationItem::query()->sole()->status);
        $this->assertSame('assistant', collect($run->refresh()->transcript)->last()['role']);
        $this->assertSame('new-owner', $run->lease_owner);
    }

    #[Test]
    public function a_response_that_arrives_after_a_takeover_leaves_the_new_owners_run_untouched(): void
    {
        [$page] = $this->pages(1);
        $run = AiRun::query()->findOrFail($this->approvedWithLostDispatches()->current_run_id);
        $this->driver->push(function () use ($run, $page) {
            $this->takeOver($run);

            return $this->propose($page);
        });

        $this->assertLeaseLost($run);

        $stored = $run->refresh();
        $this->assertSame(0, $stored->steps);
        $this->assertSame(0.0, $stored->cost_usd);
        $this->assertSame($stored->event_seq, (int) AiRunEvent::query()->where('run_id', $run->id)->max('seq'));
        $this->assertNotContains(RunEventType::Usage->value, AiRunEvent::query()->where('run_id', $run->id)->pluck('type')->all());
    }

    #[Test]
    public function a_tool_write_its_ledger_entry_and_its_checkpoint_commit_together_and_broadcasts_follow(): void
    {
        [$page] = $this->pages(1);
        $run = AiRun::query()->findOrFail($this->approvedWithLostDispatches()->current_run_id);
        $this->driver->push($this->propose($page), FakeAiDriver::text('Batch done.'));

        $outside = $run->getConnection()->transactionLevel();
        $writes = [];
        $broadcasts = [];
        AiChangesetItem::creating(function () use ($run, &$writes) {
            $writes[] = $run->getConnection()->transactionLevel();
        });
        Event::listen(AiRunEventsAvailable::class, function () use ($run, &$broadcasts) {
            $broadcasts[] = $run->getConnection()->transactionLevel();
        });

        $this->drain(app(AiRunService::class)->advance($run));

        // The fence, and the savepoint that lets a refused write leave nothing behind.
        $this->assertSame([$outside + 2], $writes);
        $this->assertNotEmpty($broadcasts);
        $this->assertSame([$outside], array_values(array_unique($broadcasts)));

        $this->assertSame(AiRunState::Completed, $run->refresh()->state);
        $this->assertSame(AiChangesetItemStatus::Accepted, AiChangesetItem::query()->sole()->status);
        $this->assertSame(AiOperationItemStatus::Written, AiOperationItem::query()->sole()->status);
        $this->assertSame(1, collect($run->transcript)->where('role', 'tool')->count());
    }

    #[Test]
    public function a_crash_in_the_checkpoint_of_a_tool_rolls_back_its_write_and_the_next_owner_repeats_only_the_tool(): void
    {
        [$page] = $this->pages(1);
        $run = AiRun::query()->findOrFail($this->approvedWithLostDispatches()->current_run_id);
        $this->driver->push($this->propose($page), FakeAiDriver::text('Batch done.'));

        $crash = true;
        AiRun::updating(function (AiRun $model) use (&$crash) {
            if ($crash && $model->isDirty('transcript') && collect($model->transcript)->last()['role'] === 'tool') {
                $crash = false;

                throw new RuntimeException('The worker died here.');
            }
        });

        try {
            $this->drain(app(AiRunService::class)->advance($run));
            $this->fail('The checkpoint should have stopped the worker.');
        } catch (RuntimeException) {
        }

        // The draft rolled back with its record: nothing to double write.
        $versions = ContentVersion::query()->where('content_id', $page->id)->count();
        $this->assertSame(1, $versions);
        $this->assertSame($page->current_version_id, $page->fresh()->current_version_id);
        $this->assertSame(0, AiChangesetItem::query()->count());
        $this->assertSame(AiOperationItemStatus::Running, AiOperationItem::query()->sole()->status);
        $this->assertSame(1, $this->driver->calls);
        $this->assertNotSame(AiRunState::Completed, $run->refresh()->state);

        $this->drain(app(AiRunService::class)->advance($run));

        $this->assertSame(2, $this->driver->calls);
        $this->assertSame(AiRunState::Completed, $run->refresh()->state);
        $this->assertSame(AiChangesetItemStatus::Accepted, AiChangesetItem::query()->sole()->status);
        $this->assertSame(1, collect($run->transcript)->where('role', 'tool')->count());
        // The repeat wrote it once, on top of the same version.
        $this->assertSame(2, ContentVersion::query()->where('content_id', $page->id)->count());
    }

    #[Test]
    public function a_deadlock_inside_a_write_rolls_it_back_and_stops_the_worker_without_failing_the_run(): void
    {
        [$page] = $this->pages(1);
        $run = AiRun::query()->findOrFail($this->approvedWithLostDispatches()->current_run_id);
        $this->driver->push($this->propose($page), FakeAiDriver::text('Batch done.'));

        $deadlock = true;
        AiChangesetItem::creating(function () use (&$deadlock) {
            if ($deadlock) {
                $deadlock = false;

                throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
        });

        (new RunAgentJob($run->space_id, $run->id))->handle();

        $this->assertSame(AiRunState::Running, $run->refresh()->state);
        $this->assertNull($run->lease_owner);
        $this->assertSame(0, AiChangesetItem::query()->count());
        $this->assertSame('assistant', collect($run->transcript)->last()['role']);

        $this->drain(app(AiRunService::class)->advance($run));

        $this->assertSame(AiRunState::Completed, $run->refresh()->state);
        $this->assertSame(1, AiChangesetItem::query()->count());
    }

    #[Test]
    public function a_failure_outside_the_loop_leaves_a_run_another_worker_owns(): void
    {
        $this->pages(1);
        $run = AiRun::query()->findOrFail($this->approvedWithLostDispatches()->current_run_id);
        $lease = RunLease::acquire($run, 60);

        $this->assertSame([], $this->drain(app(AiRunService::class)->fail($run, null, 'background_failed', 'The background run failed.')));

        $this->assertSame(AiRunState::Running, $run->refresh()->state);
        $this->assertNull($run->error);

        $lease->release();
        $this->assertCount(1, $this->drain(app(AiRunService::class)->fail($run, null, 'background_failed', 'The background run failed.')));
        $this->assertSame(AiRunState::Failed, $run->refresh()->state);
        $this->assertNull($run->lease_owner);
    }

    #[Test]
    public function recovery_leaves_paused_cancelled_draft_and_budget_stopped_operations_alone(): void
    {
        $this->pages(1);
        $draft = $this->createGeneral();
        $paused = $this->pausedOperation(AiOperationPauseReason::User, AiOperationState::Paused);
        $budget = $this->pausedOperation(AiOperationPauseReason::Budget, AiOperationState::Paused);
        $revoked = $this->pausedOperation(AiOperationPauseReason::AccessRevoked, AiOperationState::Paused);
        $cancelled = $this->pausedOperation(null, AiOperationState::Cancelled);
        Queue::fake();

        $this->pastGrace();
        $this->sweep();

        Queue::assertNothingPushed();
        $this->assertSame(0, AiRun::query()->count());
        $this->assertSame(AiOperationState::Draft, $draft->refresh()->state);
        $this->assertSame(AiOperationState::Paused, $paused->refresh()->state);
        $this->assertSame(AiOperationState::Paused, $budget->refresh()->state);
        $this->assertSame(AiOperationState::Paused, $revoked->refresh()->state);
        $this->assertSame(AiOperationState::Cancelled, $cancelled->refresh()->state);
    }

    #[Test]
    public function a_pause_asked_for_during_an_abandoned_batch_stops_it_without_a_provider_call_or_an_attempt(): void
    {
        $this->pages(1);
        $operation = $this->approvedWithLostDispatches();
        $run = AiRun::query()->findOrFail($operation->current_run_id);
        $this->postJson($this->url("ai/operations/{$operation->id}/pause"))->assertOk();
        $this->assertTrue($operation->refresh()->pause_requested);

        $this->pastGrace();
        $this->sweep();

        $this->assertSame(AiRunState::Cancelled, $run->refresh()->state);
        $operation->refresh();
        $this->assertSame(AiOperationState::Paused, $operation->state);
        $this->assertSame(AiOperationPauseReason::User, $operation->pause_reason);
        $this->assertNull($operation->current_run_id);
        $this->assertSame(0, $this->driver->calls);
        $this->assertSame(1, AiOperationItem::query()->where('status', 'pending')->where('attempts', 0)->count());
    }

    #[Test]
    public function a_cancellation_that_never_reached_its_abandoned_run_is_completed_by_recovery(): void
    {
        $this->pages(1);
        $operation = $this->approvedWithLostDispatches();
        $run = AiRun::query()->findOrFail($operation->current_run_id);
        // The worker died between cancelling the operation and cancelling its run.
        $operation->forceFill(['state' => AiOperationState::Cancelled, 'finished_at' => now()])->save();

        $this->pastGrace();
        $this->sweep();

        $this->assertSame(AiRunState::Cancelled, $run->refresh()->state);
        $this->assertSame(AiOperationState::Cancelled, $operation->refresh()->state);
        $this->assertNull($operation->current_run_id);
        $this->assertSame(0, $this->driver->calls);
    }

    #[Test]
    public function an_abandoned_batch_of_a_revoked_actor_makes_no_provider_call_and_pauses(): void
    {
        $this->pages(1);
        $operation = $this->approvedWithLostDispatches();
        $run = AiRun::query()->findOrFail($operation->current_run_id);
        app(MembershipService::class)->removeSpaceMembership($this->space, $this->user);

        $this->restoreQueue();
        $this->pastGrace();
        $this->sweep();

        $this->assertSame('access_revoked', $run->refresh()->error);
        $this->assertSame(0, $this->driver->calls);
        $this->assertSame(AiOperationPauseReason::AccessRevoked, $operation->refresh()->pause_reason);
        $this->assertSame(1, AiOperationItem::query()->where('status', 'pending')->where('attempts', 0)->count());
    }

    #[Test]
    public function a_reconciled_cost_above_the_budget_stops_the_next_batch_until_it_is_extended(): void
    {
        config(['ai.operations.batch_size' => 1]);
        $this->pages(2);
        $operation = $this->approvedWithLostDispatches();
        $this->scriptProposals();
        $run = AiRun::query()->findOrFail($operation->current_run_id);
        $this->drain(app(AiRunService::class)->advance($run));

        $run->refresh()->update(['cost_usd' => 5.0]);
        app(AiOperations::class)->syncSpend($run);
        $this->sweep();

        $operation->refresh();
        $this->assertSame(AiOperationState::Paused, $operation->state);
        $this->assertSame(AiOperationPauseReason::Budget, $operation->pause_reason);
        $this->assertEqualsWithDelta(5.0, $operation->spent_usd, 1e-6);
        $this->assertEqualsWithDelta(1.0, $operation->max_usd, 1e-6);
        $this->assertSame(1, AiRun::query()->where('operation_id', $operation->id)->count());
    }

    private function takeOver(AiRun $run): void
    {
        AiRun::query()->whereKey($run->id)->update(['lease_owner' => 'new-owner', 'lease_expires_at' => now()->addMinutes(5)]);
    }

    private function assertLeaseLost(AiRun $run): void
    {
        try {
            $this->drain(app(AiRunService::class)->advance($run));
            $this->fail('The worker should have found its lease gone.');
        } catch (RunLeaseLost) {
        }
    }

    private function sweep(): void
    {
        $this->artisan('ai:recover-runs')->assertSuccessful();
    }

    private function settledSpendAfterSweep(AiOperation $operation): float
    {
        $this->sweep();

        return round($operation->refresh()->spent_usd, 6);
    }

    private function pastGrace(): void
    {
        $this->travel((int) config('ai.runs.recovery_grace_seconds') + 1)->seconds();
    }

    /** Approve with a queue that loses what is dispatched; {@see restoreQueue()} brings the real one back. */
    private function approvedWithLostDispatches(): AiOperation
    {
        $this->realQueue = Queue::getFacadeRoot();
        Queue::fake();
        $operation = $this->createGeneral();
        $hash = $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => ['summary' => 'Rewrite each summary.']])->assertOk()->json('data.plan_hash');
        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $operation->refresh()->counts['scope_hash']])->assertOk();

        return $operation->refresh();
    }

    private mixed $realQueue = null;

    private function restoreQueue(): void
    {
        Queue::swap($this->realQueue);
    }

    private function pausedOperation(?AiOperationPauseReason $reason, AiOperationState $state): AiOperation
    {
        $operation = $this->createGeneral();
        $operation->forceFill(['state' => $state, 'pause_reason' => $reason, 'approved_plan' => ['summary' => 'Rewrite.'], 'max_usd' => 1, 'spent_usd' => $reason === AiOperationPauseReason::Budget ? 1 : 0])->save();

        return $operation;
    }

    /** Every provider call proposes for the first entry of the running batch without an outcome. */
    private function scriptProposals(): void
    {
        $this->driver->push(...array_fill(0, 20, function (): TurnResult {
            $item = AiOperationItem::query()->where('status', AiOperationItemStatus::Running->value)->orderBy('position')->first();

            return $item ? $this->propose(Content::query()->findOrFail($item->target_id)) : FakeAiDriver::text('Batch done.');
        }));
    }

    private function propose(Content $content): TurnResult
    {
        return new TurnResult('', [new ToolCall('call_'.Str::random(6), 'propose_update', [
            'target_type' => 'content',
            'id' => $content->id,
            'version_id' => $content->current_version_id,
            'changes' => [['path' => 'summary', 'value' => "About {$content->name}"]],
        ])], new TurnUsage(10, 5, 0.03));
    }

    private function createGeneral(): AiOperation
    {
        return AiOperation::query()->findOrFail($this->postJson($this->url('ai/operations'), [
            'kind' => 'general',
            'prompt' => 'Rewrite the summaries.',
            'scope' => ['type' => 'content', 'filters' => ['block' => 'page']],
        ])->assertCreated()->json('data.id'));
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

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    /** @return list<mixed> */
    private function drain(Generator $events): array
    {
        return iterator_to_array($events, false);
    }
}
