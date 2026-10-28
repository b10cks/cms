<?php

namespace Tests\Feature\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiChangesetStatus;
use App\Enums\AiRunState;
use App\Enums\AiThreadKind;
use App\Jobs\RunAgentJob;
use App\Models\Management\AiSchedule;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiConversation;
use App\Models\Space\AiGlossarySuggestion;
use App\Models\Space\AiMessage;
use App\Models\Space\AiRun;
use App\Models\Space\AiRunEvent;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Run\StartRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

/** AI threads and runs on a separate, prefixed space database, reached from requests and the queue. */
class AiSpaceDatabaseTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private FakeAiDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSeparateSpaceDatabase($this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);
    }

    #[Test]
    public function the_thread_list_derives_status_and_entry_counts_in_the_space_database(): void
    {
        $waiting = $this->thread(AiRunState::AwaitingInput);
        $reviewing = $this->thread(AiRunState::Completed);
        $changeset = AiChangeset::create(['space_id' => $this->space->id, 'run_id' => $reviewing->runs()->value('id'), 'actor_id' => $this->user->id, 'title' => 'Edits', 'status' => AiChangesetStatus::Open]);
        $changeset->items()->create(['target_type' => 'content', 'target_id' => 'c1', 'path' => 'title', 'after' => 'New', 'status' => AiChangesetItemStatus::Pending]);
        $suggestions = AiConversation::create(['space_id' => $this->space->id, 'kind' => AiThreadKind::Suggestions, 'surface' => 'learning', 'last_activity_at' => now()]);
        AiGlossarySuggestion::create(['space_id' => $this->space->id, 'source_locale' => 'en', 'term' => 'Checkout', 'status' => AiGlossarySuggestion::STATUS_PENDING]);

        $rows = collect($this->getJson("/mgmt/v1/spaces/{$this->space->id}/ai/threads")->assertOk()->assertJsonPath('meta.attention', 3)->json('data'))->keyBy('id');

        $this->assertSame(['needs_input', 0], [$rows[$waiting->id]['status'], $rows[$waiting->id]['entry_count']]);
        $this->assertSame(['needs_review', 1], [$rows[$reviewing->id]['status'], $rows[$reviewing->id]['entry_count']]);
        $this->assertSame(['needs_review', 1], [$rows[$suggestions->id]['status'], $rows[$suggestions->id]['entry_count']]);
        $this->assertFalse(Schema::hasTable('ai_runs'));
    }

    #[Test]
    public function a_background_run_continues_on_the_queue_without_an_ambient_space(): void
    {
        $this->driver->push(FakeAiDriver::text('Done in the background.'));
        $run = app(AiRunService::class)->start(new StartRun($this->space, $this->user, 'cmdk', prompt: 'Summarise.'));
        $run->forceFill(['mode' => AiRun::MODE_BACKGROUND])->save();
        app()->offsetUnset('currentSpace');

        (new RunAgentJob($this->space->id, $run->id))->handle();

        app()->offsetSet('currentSpace', $this->space);
        $this->assertSame(AiRunState::Completed, $run->refresh()->state);
        $this->assertSame('Done in the background.', $run->result['text']);
    }

    #[Test]
    public function a_due_schedule_runs_into_its_space_thread_which_outlives_the_schedule(): void
    {
        $this->page();
        $this->driver->push(FakeAiDriver::text('Nothing to change.'));
        $schedule = AiSchedule::create([
            'space_id' => $this->space->id,
            'created_by' => $this->user->id,
            'name' => 'Weekly translation',
            'skill' => 'translate-fields',
            'scope' => ['type' => 'content', 'filters' => ['block' => 'page']],
            'cron' => '* * * * *',
            'max_usd' => 0.5,
        ]);
        app()->offsetUnset('currentSpace');

        $this->artisan('ai:run-schedules')->assertSuccessful();

        app()->offsetSet('currentSpace', $this->space);
        $run = AiRun::query()->sole();
        $this->assertSame([AiRun::MODE_BACKGROUND, AiRunState::Completed], [$run->mode, $run->state]);
        $this->assertSame($run->id, $schedule->refresh()->last_run_id);
        $this->assertSame($schedule->id, $run->conversation->schedule_id);

        $this->deleteJson("/mgmt/v1/spaces/{$this->space->id}/ai/schedules/{$schedule->id}")->assertNoContent();

        $this->assertNull($run->conversation->refresh()->schedule_id);
    }

    #[Test]
    public function pruning_drops_only_the_events_of_runs_finished_past_retention(): void
    {
        $old = $this->thread(AiRunState::Completed)->runs()->sole();
        $recent = $this->thread(AiRunState::Failed)->runs()->sole();
        $waiting = $this->thread(AiRunState::AwaitingInput)->runs()->sole();
        $running = $this->thread(AiRunState::Running)->runs()->sole();
        $old->forceFill(['finished_at' => now()->subDays(31), 'transcript' => [['role' => 'user', 'content' => 'Hi']]])->save();
        $recent->forceFill(['finished_at' => now()->subDays(29)])->save();
        // A run back in progress keeps its events, whatever an earlier finish left behind.
        $waiting->forceFill(['finished_at' => now()->subDays(40)])->save();
        $running->forceFill(['finished_at' => now()->subDays(40)])->save();
        foreach ([$old, $recent, $waiting, $running] as $run) {
            $run->events()->create(['seq' => 1, 'type' => 'text', 'payload' => ['text' => 'Hi']]);
        }
        $message = AiMessage::create(['conversation_id' => $old->conversation_id, 'run_id' => $old->id, 'role' => 'assistant', 'content' => 'Hi']);
        $changeset = AiChangeset::create(['space_id' => $this->space->id, 'run_id' => $old->id, 'actor_id' => $this->user->id, 'title' => 'Edits', 'status' => AiChangesetStatus::Applied]);
        app()->offsetUnset('currentSpace');

        $this->artisan('ai:prune-run-events')->expectsOutputToContain('1 run event(s) pruned')->assertSuccessful();

        app()->offsetSet('currentSpace', $this->space);
        $this->assertSame([$recent->id, $waiting->id, $running->id], AiRunEvent::query()->orderBy('id')->pluck('run_id')->all());
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $old->refresh()->transcript);
        $this->assertModelExists($message);
        $this->assertModelExists($changeset);
    }

    private function thread(AiRunState $state): AiConversation
    {
        $thread = AiConversation::create(['space_id' => $this->space->id, 'user_id' => $this->user->id, 'surface' => 'cmdk', 'last_activity_at' => now()]);
        AiRun::create([
            'space_id' => $this->space->id,
            'actor_id' => $this->user->id,
            'conversation_id' => $thread->id,
            'surface' => 'cmdk',
            'state' => $state,
            'setup' => [],
            'transcript' => [],
            'started_at' => now(),
        ]);

        return $thread;
    }

    private function page(): void
    {
        $block = Block::query()->create(['external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root', 'schema' => ['title' => ['type' => 'text', 'name' => 'Title']]]);
        $content = new Content;
        $content->forceFill(['block_id' => $block->id, 'name' => 'Home', 'slug' => 'home', 'full_slug' => '/home', 'language_iso' => 'en']);
        $content->id = strtolower((string) Str::ulid());
        $version = ContentVersion::query()->forceCreate(['content_id' => $content->id, 'content' => ['title' => 'Home'], 'created_by_id' => $this->user->id]);
        $content->current_version_id = $version->id;
        $content->save();
    }
}
