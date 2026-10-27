<?php

namespace Tests\Feature\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiChangesetStatus;
use App\Enums\AiRunState;
use App\Enums\AiThreadKind;
use App\Models\Management\AiChangeset;
use App\Models\Management\AiChangesetItem;
use App\Models\Management\AiConversation;
use App\Models\Management\AiMessage;
use App\Models\Management\AiRun;
use App\Models\Management\AiSchedule;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\Schedules\ScheduleRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiThreadTest extends TestCase
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
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);
    }

    #[Test]
    public function a_run_without_a_thread_opens_one_for_its_actor_and_a_selection_run_opens_a_quiet_one(): void
    {
        $this->driver->push(FakeAiDriver::text('Fresh coffee, daily.'), FakeAiDriver::text('Better text'));

        $chat = $this->sseEvents($this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'prompt' => "Write a tagline for the shop\nKeep it short."])->streamedContent());
        $this->postJson($this->url('ai/runs'), ['surface' => 'selection', 'prompt' => 'Improve this.', 'context' => ['selection' => 'Old text']])->streamedContent();

        [$first, $second] = AiConversation::query()->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame($first->id, $chat[0]['conversation_id']);
        $this->assertSame(['run_started', $first->id], [$chat[0]['type'], AiRun::query()->where('surface', 'cmdk')->value('conversation_id')]);
        $this->assertSame([$this->user->id, AiThreadKind::Chat, 'Write a tagline for the shop', false], [$first->user_id, $first->kind, $first->title, $first->quiet]);
        $this->assertSame(['selection' => 'Old text'], $second->context);
        $this->assertTrue($second->quiet);
        $this->assertSame(['user', 'assistant'], $first->messages()->pluck('role')->all());
    }

    #[Test]
    public function a_private_thread_is_hidden_until_shared_and_only_its_owner_sends_follow_ups(): void
    {
        $thread = $this->thread([AiRunState::Completed]);
        $editor = User::factory()->create();
        $this->assignSpaceRole($this->space, $editor, 'editor');

        $this->actingAs($editor)->getJson($this->url('ai/threads'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url("ai/threads/{$thread->id}"))->assertNotFound();

        $this->actingAs($this->user)->patchJson($this->url("ai/threads/{$thread->id}"), ['shared' => true])->assertOk()->assertJsonPath('data.shared', true);

        $this->actingAs($editor)->getJson($this->url('ai/threads'))
            ->assertJsonPath('data.0.id', $thread->id)
            ->assertJsonPath('data.0.is_owner', false);
        $this->getJson($this->url("ai/threads/{$thread->id}"))->assertOk()->assertJsonCount(1, 'data.turns');
        $this->patchJson($this->url("ai/threads/{$thread->id}"), ['title' => 'Mine now'])->assertForbidden();
        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'prompt' => 'And now?', 'conversation_id' => $thread->id])->assertNotFound();
        $this->assertSame(0, $this->driver->calls);
    }

    #[Test]
    public function a_schedules_second_run_appends_to_the_same_thread(): void
    {
        $this->page();
        $this->driver->push(FakeAiDriver::text('Nothing to change.'), FakeAiDriver::text('Still nothing.'));
        $schedule = AiSchedule::create([
            'space_id' => $this->space->id,
            'created_by' => $this->user->id,
            'name' => 'Weekly translation',
            'skill' => 'translate-fields',
            'scope' => ['type' => 'content', 'filters' => ['block' => 'page']],
            'cron' => '0 9 * * 1',
            'max_usd' => 0.5,
        ]);

        $runner = app(ScheduleRunner::class);
        $first = $runner->run($schedule);
        $second = $runner->run($schedule->refresh());

        $thread = AiConversation::query()->sole();
        $this->assertSame([AiThreadKind::Schedule, $schedule->id, null], [$thread->kind, $thread->schedule_id, $thread->user_id]);
        $this->assertSame([$thread->id, $thread->id], [$first->conversation_id, $second->conversation_id]);
        $this->getJson($this->url("ai/threads/{$thread->id}"))
            ->assertJsonCount(2, 'data.turns')
            ->assertJsonPath('data.turns.1.answer', 'Still nothing.');
    }

    #[Test]
    public function the_status_of_a_thread_follows_its_latest_runs_and_open_changesets(): void
    {
        $threads = [
            'needs_input' => $this->thread([AiRunState::Completed, AiRunState::AwaitingInput]),
            'needs_review' => $this->thread([AiRunState::Completed], openChangeset: true),
            'running' => $this->thread([AiRunState::Running]),
            'failed' => $this->thread([AiRunState::Completed, AiRunState::Failed]),
            'done' => $this->thread([AiRunState::Failed, AiRunState::Completed]),
        ];

        $rows = collect($this->getJson($this->url('ai/threads'))->assertOk()->json('data'));

        $this->assertSame(array_keys($threads), array_map(fn (AiConversation $thread) => $rows->firstWhere('id', $thread->id)['status'], array_values($threads)));
        $this->assertSame(['needs_input', 'needs_review'], $rows->take(2)->pluck('status')->all());
        $this->assertSame(1, $rows->firstWhere('status', 'needs_review')['entry_count']);
    }

    #[Test]
    public function a_follow_up_sees_earlier_turns_and_the_proposal_ledger_and_drops_the_oldest_turns_past_the_budget(): void
    {
        $thread = $this->thread([AiRunState::Completed, AiRunState::Completed], openChangeset: true);
        $runs = $thread->runs()->orderBy('started_at')->get();
        $this->message($runs[0], 'user', 'First question '.str_repeat('x', 400));
        $this->message($runs[0], 'assistant', 'First answer');
        $this->message($runs[1], 'user', 'Second question');
        $this->message($runs[1], 'assistant', 'Second answer');
        AiChangesetItem::query()->update(['status' => AiChangesetItemStatus::Accepted->value, 'final' => json_encode('Kept')]);
        config(['ai.threads.history_tokens' => 100]);
        $this->driver->push(FakeAiDriver::text('Third answer'));

        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'prompt' => 'Third question', 'conversation_id' => $thread->id])->streamedContent();

        $messages = $this->driver->transcripts[0];
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame(['Second question', 'Second answer'], [$messages[1]['content'], $messages[2]['content']]);
        $this->assertMatchesRegularExpression('/<proposals-(\w{8})>\n\{"entry":"Home","field":"title","status":"accepted_edited"\}\n<\/proposals-\1>/', $messages[3]['content']);
        $this->assertStringStartsWith('Third question', $messages[3]['content']);
    }

    #[Test]
    public function attention_counts_threads_that_need_you_but_not_archived_or_quiet_ones(): void
    {
        $this->thread([AiRunState::AwaitingInput]);
        $this->thread([AiRunState::Completed], openChangeset: true);
        $this->thread([AiRunState::AwaitingInput])->forceFill(['quiet' => true])->save();
        $archived = $this->thread([AiRunState::AwaitingInput]);
        $this->thread([AiRunState::Running]);

        $this->patchJson($this->url("ai/threads/{$archived->id}"), ['archived' => true])->assertOk()->assertJsonPath('data.archived', true);

        $this->getJson($this->url('ai/threads'))
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.attention', 2);
        $this->getJson($this->url('ai/threads?archived=1'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $archived->id);
    }

    /**
     * A chat thread of the acting owner with one run per state, oldest first.
     *
     * @param  list<AiRunState>  $states
     */
    private function thread(array $states, bool $openChangeset = false): AiConversation
    {
        $thread = AiConversation::create([
            'space_id' => $this->space->id,
            'user_id' => $this->user->id,
            'surface' => 'cmdk',
            'title' => 'Thread '.Str::random(4),
            'last_activity_at' => now(),
        ]);

        foreach ($states as $index => $state) {
            $run = AiRun::create([
                'space_id' => $this->space->id,
                'actor_id' => $this->user->id,
                'conversation_id' => $thread->id,
                'surface' => 'cmdk',
                'state' => $state,
                'mode' => AiRun::MODE_BACKGROUND,
                'setup' => [],
                'transcript' => [],
                'questionnaire' => $state === AiRunState::AwaitingInput ? ['tool_call_id' => 'call_1', 'questions' => []] : null,
                'started_at' => now()->subMinutes(10 - $index),
            ]);
        }

        if ($openChangeset) {
            $changeset = AiChangeset::create(['space_id' => $this->space->id, 'run_id' => $run->id, 'actor_id' => $this->user->id, 'title' => 'Edits', 'inline' => false, 'status' => AiChangesetStatus::Open]);
            $changeset->items()->create(['operation' => 'update', 'target_type' => 'content', 'target_id' => 'c1', 'target_label' => 'Home', 'path' => 'title', 'after' => 'New', 'status' => AiChangesetItemStatus::Pending]);
        }

        return $thread;
    }

    private function message(AiRun $run, string $role, string $content): void
    {
        AiMessage::create(['conversation_id' => $run->conversation_id, 'run_id' => $run->id, 'role' => $role, 'content' => $content]);
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

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }
}
