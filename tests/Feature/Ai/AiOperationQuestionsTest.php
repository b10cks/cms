<?php

namespace Tests\Feature\Ai;

use App\Enums\AiOperationItemStatus;
use App\Enums\AiOperationState;
use App\Jobs\RunAgentJob;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Operations\AiOperations;
use App\Services\Ai\Operations\OperationContext;
use App\Services\Ai\Operations\OperationQuestions;
use App\Services\Ai\Operations\Tools\ReportItemsTool;
use App\Services\Ai\Run\RunRecovery;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Run\Transcript;
use App\Services\Ai\Run\TurnResult;
use App\Services\Auth\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiOperationQuestionsTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private const MODEL = 'Which mattress model is shown?';

    private const WARRANTY = 'Does it qualify for the extended warranty?';

    private const PLAN = ['summary' => 'Fill metadata.', 'metadata' => ['keys' => ['title', 'description']]];

    private Space $space;

    private FakeAiDriver $driver;

    private Block $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => ['default_language' => 'en', 'languages' => [['code' => 'de', 'name' => 'German']]]]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);

        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root',
            'schema' => ['seo' => ['type' => 'meta', 'name' => 'SEO']],
        ]);
    }

    #[Test]
    public function independent_work_finishes_first_then_the_questions_collect_together(): void
    {
        config(['ai.operations.batch_size' => 1]);
        [$first, $second, $third] = [$this->entry(), $this->entry(), $this->entry()];
        $midway = null;

        $this->driver->push(
            $this->propose($first, ['title' => 'First title']),
            $this->block($first, ['en.seo.description' => self::MODEL]),
            function () use (&$midway, $first) {
                // Mid-batch nothing stops for a person, and nothing can be answered yet.
                $midway = $this->getJson($this->url($this->operationId($first).'/questions'))->assertOk()->json();

                return FakeAiDriver::text('First done.');
            },
            $this->propose($second, ['title' => 'Second title']),
            $this->block($second, ['en.seo.description' => '  which MATTRESS model   is shown? ']),
            FakeAiDriver::text('Second done.'),
            $this->propose($third, ['title' => 'Third title', 'description' => 'Third description']),
            FakeAiDriver::text('Third done.'),
        );

        $operation = $this->approve(self::PLAN);

        $this->assertFalse($midway['summary']['accepting']);
        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->assertSame(3, AiChangesetItem::query()->count());
        $this->assertFalse($operation->counts['coverage']['complete']);
        $this->assertSame(AiOperationItemStatus::Written, $this->item($third)->status);

        $response = $this->getJson($this->url("{$operation->id}/questions"))->assertOk();
        $response->assertJsonPath('summary.accepting', true)
            ->assertJsonPath('summary.questions', 1)
            ->assertJsonPath('summary.outputs', 2)
            ->assertJsonPath('summary.entries', 2)
            ->assertJsonPath('summary.answered', 0)
            ->assertJsonPath('data.0.question', self::MODEL)
            ->assertJsonPath('data.0.outputs', 2)
            ->assertJsonPath('meta.total', 1);

        $members = $this->getJson($this->url("{$operation->id}/questions/{$response->json('data.0.id')}/members"))->assertOk();
        $members->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.target_id', $first->id)
            ->assertJsonPath('data.0.part', 'en.seo.description')
            ->assertJsonPath('data.1.target_id', $second->id);
    }

    #[Test]
    public function a_partial_answer_reopens_only_its_outputs_and_keeps_proposals_plan_and_budget(): void
    {
        $entry = $this->entry();
        $this->driver->push(
            $this->propose($entry, ['title' => 'Kept title']),
            $this->block($entry, ['en.seo.description' => self::MODEL, 'en.seo.ogTitle' => self::WARRANTY]),
            FakeAiDriver::text('Done with what I can.'),
        );
        $operation = $this->approve(['summary' => 'Fill metadata.', 'metadata' => ['keys' => ['title', 'description', 'ogTitle']]]);
        app(KnowledgeResolver::class)->pinOperationPolicy($operation);
        $operation->save();
        $frozen = $operation->refresh()->only(['approved_plan', 'approved_plan_hash', 'approved_at', 'max_usd', 'policy_snapshot_id', 'scope', 'actor_id']);
        $spent = $operation->spent_usd;
        $calls = $this->driver->calls;
        $this->assertNotNull($frozen['policy_snapshot_id']);

        $this->driver->push(
            // It tries to fill the unanswered part and rewrite the proposal, and neither may land.
            $this->propose($entry, ['description' => 'The Comfort 200 mattress.', 'ogTitle' => 'Inferred after all', 'title' => 'Rewritten title']),
            FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $entry->id, 'outputs' => [['part' => 'en.seo.ogTitle', 'outcome' => 'uninferable', 'reason' => 'Gave up on it.']]]]]),
            function (Transcript $transcript) {
                $result = json_decode((string) collect($transcript->toArray())->last(fn (array $message) => $message['role'] === 'tool')['content'], true);
                $this->assertStringContainsString('waits for an editor', $result['errors'][0]);

                return FakeAiDriver::text('Answered part done.');
            },
        );

        $this->answerGroup($operation, self::MODEL, 'The Comfort 200.')->assertOk()
            ->assertJsonPath('data.results.0.status', 'recorded')
            ->assertJsonPath('data.results.0.recorded', 1);

        $operation->refresh();
        $item = $this->item($entry);
        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->assertSame(2, $operation->batch_count);
        $this->assertEquals($frozen, $operation->only(array_keys($frozen)));
        $this->assertSame('written', $item->detail['parts']['en.seo.title']['outcome']);
        $this->assertSame(1, $item->detail['parts']['en.seo.title']['batch']);
        $this->assertSame('written', $item->detail['parts']['en.seo.description']['outcome']);
        $this->assertSame(['outcome' => 'blocked', 'question' => self::WARRANTY], array_intersect_key($item->detail['parts']['en.seo.ogTitle'], ['outcome' => 1, 'question' => 1]));

        // Written over the field the earlier batch wrote, so the draft holds both.
        $this->assertSame(2, AiChangesetItem::query()->where('status', 'accepted')->count());
        $seo = $entry->fresh()->getCurrentContent()['seo'];
        $this->assertSame('Kept title', $seo['title']);
        $this->assertSame('The Comfort 200 mattress.', $seo['description']);
        $this->assertArrayNotHasKey('ogTitle', $seo);

        $stored = $item->detail['answers']['en.seo.description'][0];
        $this->assertSame([self::MODEL, 'The Comfort 200.', (string) $this->user->id], [$stored['question'], $stored['answer'], $stored['answered_by']]);
        $this->assertGreaterThan($calls, $this->driver->calls);
        $this->assertGreaterThanOrEqual($spent, $operation->spent_usd);

        $this->getJson($this->url("{$operation->id}/questions/answered"))->assertOk()
            ->assertJsonPath('data.0.answer', 'The Comfort 200.')
            ->assertJsonPath('data.0.answered_by.name', $this->user->name)
            ->assertJsonPath('data.0.outputs', 1);
        $this->getJson($this->url("{$operation->id}/questions"))->assertOk()
            ->assertJsonPath('summary.questions', 1)
            ->assertJsonPath('data.0.question', self::WARRANTY)
            ->assertJsonPath('summary.answered', 1);
    }

    #[Test]
    public function the_next_batch_gets_answers_as_context_for_the_answered_part_only(): void
    {
        $entry = $this->entry();
        $this->driver->push(
            $this->block($entry, ['en.seo.title' => self::MODEL, 'en.seo.description' => self::WARRANTY]),
            FakeAiDriver::text('Blocked.'),
        );
        $operation = $this->approve(self::PLAN);

        $seen = null;
        $this->driver->push(
            function (Transcript $transcript) use ($entry, &$seen) {
                $seen = json_encode($transcript->toArray());

                return $this->propose($entry, ['title' => 'Comfort 200']);
            },
            FakeAiDriver::text('Done.'),
        );
        $this->answerGroup($operation, self::MODEL, 'The Comfort 200.')->assertOk();

        $this->assertStringContainsString('The Comfort 200.', $seen);
        $this->assertStringContainsString('\\"owed\\":[\\"en.seo.title\\"]', $seen);
        $this->assertStringNotContainsString(self::WARRANTY, $seen);
    }

    #[Test]
    public function an_open_item_of_another_changeset_does_not_hold_up_an_answered_content_output(): void
    {
        $entry = $this->entry();
        $operation = $this->settledWith([$entry->id => ['en.seo.title' => self::MODEL]]);
        $changeset = AiChangeset::query()->create([
            'space_id' => $this->space->id, 'actor_id' => $this->user->id, 'title' => 'Another task',
        ]);
        $changeset->items()->create([
            'target_type' => 'content', 'target_id' => $entry->id, 'operation' => 'update',
            'path' => 'seo', 'before' => null, 'after' => ['title' => 'Other'], 'status' => 'conflict',
        ]);

        $this->answerGroup($operation, self::MODEL, 'The Comfort 200.')->assertOk();

        $this->assertSame(AiOperationState::Running, $operation->refresh()->state);
        $item = $this->item($entry);
        $this->assertArrayNotHasKey('en.seo.title', $item->detail['parts']);
        $this->assertSame('The Comfort 200.', $item->detail['answers']['en.seo.title'][0]['answer']);
        Queue::assertPushed(RunAgentJob::class);
    }

    #[Test]
    public function duplicate_stale_and_foreign_answers_change_nothing(): void
    {
        [$first, $second] = [$this->entry(), $this->entry()];
        $operation = $this->settledWith([$first->id => ['en.seo.title' => self::MODEL], $second->id => ['en.seo.title' => self::MODEL]]);
        $group = $this->group($operation, self::MODEL);
        $members = $this->getJson($this->url("{$operation->id}/questions/{$group['id']}/members"))->json('data');

        $this->postAnswers($operation, [['question_id' => $group['id'], 'answer' => 'Late.', 'fingerprint' => str_repeat('a', 64)]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'stale')
            ->assertJsonPath('data.operation.state', 'completed');
        $this->postAnswers($operation, [['question_id' => $group['id'], 'answer' => 'Foreign.', 'member_ids' => [str_repeat('b', 32)]]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'stale');
        $this->postAnswers($operation, [['question_id' => str_repeat('c', 32), 'answer' => 'Foreign.', 'fingerprint' => $group['fingerprint']]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'stale');
        $this->postAnswers($operation, [['question_id' => $group['id'], 'answer' => 'Both ways.', 'member_ids' => [$members[0]['id']], 'fingerprint' => $group['fingerprint']]])->assertUnprocessable();
        Queue::assertNotPushed(RunAgentJob::class);

        $one = [['question_id' => $group['id'], 'answer' => 'Comfort 200.', 'member_ids' => [$members[0]['id']]]];
        $this->postAnswers($operation, $one)->assertOk()->assertJsonPath('data.results.0.status', 'recorded');
        $batches = $operation->refresh()->batch_count;
        $this->assertSame(AiOperationState::Running, $operation->state);
        $this->assertSame(1, AiRun::query()->where('operation_id', $operation->id)->where('state', 'running')->count());
        Queue::assertPushed(RunAgentJob::class, 1);

        // The same delivery again, while its batch runs and with a different text: first answer stands.
        $this->postAnswers($operation, [[...$one[0], 'answer' => 'Something else.']])->assertOk()
            ->assertJsonPath('data.results.0.status', 'duplicate');
        // The group's membership moved on, so the fingerprint the editor saw is stale.
        $this->postAnswers($operation, [['question_id' => $group['id'], 'answer' => 'For all.', 'fingerprint' => $group['fingerprint']]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'stale');

        Queue::assertPushed(RunAgentJob::class, 1);
        $this->assertSame($batches, $operation->refresh()->batch_count);
        $this->assertSame('Comfort 200.', $this->item($first)->detail['answers']['en.seo.title'][0]['answer']);
        $this->assertSame('blocked', $this->item($second)->detail['parts']['en.seo.title']['outcome']);
        $this->assertCount(1, $this->item($first)->detail['answers']['en.seo.title']);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson($this->url("{$operation->id}/questions"))->assertForbidden();
        $this->postAnswers($operation, $one)->assertForbidden();

        $elsewhere = Space::factory()->create();
        $this->assignSpaceRole($elsewhere, $this->user, 'owner');
        $this->actingAs($this->user)->getJson("/mgmt/v1/spaces/{$elsewhere->id}/ai/operations/{$operation->id}/questions")->assertNotFound();
    }

    #[Test]
    public function the_same_question_asked_again_after_an_answer_is_new_and_the_old_delivery_stays_a_duplicate(): void
    {
        $entry = $this->entry();
        $this->driver->push($this->block($entry, ['en.seo.title' => self::MODEL, 'en.seo.description' => self::MODEL]), FakeAiDriver::text('Blocked.'));
        $operation = $this->approve(self::PLAN);
        $group = $this->group($operation, self::MODEL);
        $submission = [['question_id' => $group['id'], 'answer' => 'Comfort.', 'fingerprint' => $group['fingerprint']]];

        $this->driver->push(
            $this->propose($entry, ['title' => 'Comfort']),
            $this->block($entry, ['en.seo.description' => self::MODEL]),
            FakeAiDriver::text('Still unsure.'),
        );
        $this->postAnswers($operation, $submission)->assertOk()->assertJsonPath('data.results.0.recorded', 2);

        $calls = $this->driver->calls;
        $this->postAnswers($operation, $submission)->assertOk()->assertJsonPath('data.results.0.status', 'duplicate');

        $this->assertSame($calls, $this->driver->calls);
        $this->assertSame(AiOperationState::Completed, $operation->refresh()->state);
        $again = $this->group($operation, self::MODEL);
        $this->assertSame($group['id'], $again['id']);
        $this->assertNotSame($group['fingerprint'], $again['fingerprint']);
        $this->assertSame('written', $this->item($entry)->detail['parts']['en.seo.title']['outcome']);
    }

    #[Test]
    public function an_answer_needs_the_work_to_have_finished_and_never_revives_a_cancelled_operation(): void
    {
        $entry = $this->entry();
        $operation = $this->settledWith([$entry->id => ['en.seo.title' => self::MODEL]]);
        $group = $this->group($operation, self::MODEL);
        $answer = [['question_id' => $group['id'], 'answer' => 'Comfort.', 'fingerprint' => $group['fingerprint']]];

        foreach ([
            [AiOperationState::Paused, 'questions_closed'],
            [AiOperationState::Running, 'questions_closed'],
            [AiOperationState::Cancelled, 'questions_closed'],
        ] as [$state, $reason]) {
            $operation->forceFill(['state' => $state])->save();
            $this->postAnswers($operation, $answer)->assertConflict()->assertJsonPath('reason', $reason);
            $this->assertSame($state, $operation->refresh()->state);
            $this->assertSame('blocked', $this->item($entry)->detail['parts']['en.seo.title']['outcome']);
        }

        $this->getJson($this->url("{$operation->id}/questions"))->assertOk()->assertJsonPath('summary.accepting', false)->assertJsonPath('summary.questions', 1);

        $operation->forceFill(['state' => AiOperationState::Completed])->save();
        config(['ai.runs.max_concurrent_background' => 0]);
        $this->postAnswers($operation, $answer)->assertStatus(429)->assertJsonPath('reason', 'concurrency_limit');
        $this->assertSame('blocked', $this->item($entry)->detail['parts']['en.seo.title']['outcome']);
        Queue::assertNotPushed(RunAgentJob::class);
    }

    #[Test]
    public function a_spent_budget_keeps_the_answer_and_pauses_until_someone_extends_it(): void
    {
        $entry = $this->entry();
        $operation = $this->settledWith([$entry->id => ['en.seo.title' => self::MODEL]]);
        $operation->forceFill(['spent_usd' => 1, 'max_usd' => 1])->save();
        $group = $this->group($operation, self::MODEL);

        $this->postAnswers($operation, [['question_id' => $group['id'], 'answer' => 'Comfort.', 'fingerprint' => $group['fingerprint']]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'recorded')
            ->assertJsonPath('data.operation.state', 'paused')
            ->assertJsonPath('data.operation.pause_reason', 'budget');

        $operation->refresh();
        $this->assertEquals(1, $operation->max_usd);
        $this->assertSame(1, $operation->batch_count);
        Queue::assertNotPushed(RunAgentJob::class);
        $this->assertSame('Comfort.', $this->item($entry)->detail['answers']['en.seo.title'][0]['answer']);
        $this->assertSame(['en.seo.title'], $this->item($entry)->openParts(null));
        $this->getJson($this->url("{$operation->id}/questions"))->assertJsonPath('summary.resuming', 1);

        app(AiOperations::class)->extendBudget($operation, 2);

        $this->assertSame(AiOperationState::Running, $operation->refresh()->state);
        $this->assertSame(2, $operation->batch_count);
        Queue::assertPushed(RunAgentJob::class, 1);
    }

    #[Test]
    public function lost_access_keeps_the_answer_and_starts_no_paid_work(): void
    {
        $entry = $this->entry();
        $operation = $this->settledWith([$entry->id => ['en.seo.title' => self::MODEL]]);
        $group = $this->group($operation, self::MODEL);
        app(MembershipService::class)->removeSpaceMembership($this->space, $this->user);

        $this->postAnswers($operation, [['question_id' => $group['id'], 'answer' => 'Comfort.', 'fingerprint' => $group['fingerprint']]])->assertForbidden();
        $this->assertSame(AiOperationState::Completed, $operation->refresh()->state);

        // A manager of a scheduled operation may still answer after its actor lost access; its batches stay stopped.
        app(OperationQuestions::class)->answer($operation, $this->user, [['question_id' => $group['id'], 'answer' => 'Comfort.', 'fingerprint' => $group['fingerprint']]]);

        $operation->refresh();
        $this->assertSame(AiOperationState::Paused, $operation->state);
        $this->assertSame('access_revoked', $operation->pause_reason->value);
        Queue::assertNotPushed(RunAgentJob::class);
    }

    #[Test]
    public function questions_page_predictably_and_members_keep_their_exact_identity(): void
    {
        $entries = [$this->entry(), $this->entry(), $this->entry()];
        $operation = $this->settledWith([
            $entries[0]->id => ['en.seo.title' => self::MODEL, 'en.seo.description' => self::WARRANTY],
            $entries[1]->id => ['en.seo.title' => self::MODEL],
            $entries[2]->id => ['en.seo.title' => self::MODEL],
        ]);

        $page = $this->getJson($this->url("{$operation->id}/questions?per_page=1"))->assertOk();
        $page->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data')->assertJsonPath('data.0.question', self::MODEL)->assertJsonPath('summary.outputs', 4)->assertJsonPath('summary.entries', 3);
        $this->assertSame(self::WARRANTY, $this->getJson($this->url("{$operation->id}/questions?per_page=1&page=2"))->json('data.0.question'));

        $id = $page->json('data.0.id');
        $members = $this->getJson($this->url("{$operation->id}/questions/{$id}/members?per_page=2"))->assertOk();
        $members->assertJsonPath('meta.total', 3)->assertJsonCount(2, 'data');
        $second = $this->getJson($this->url("{$operation->id}/questions/{$id}/members?per_page=2&page=2"))->assertJsonCount(1, 'data');

        $this->assertSame($entries[2]->id, $second->json('data.0.target_id'));
        $this->assertSame($members->json('data.0.id'), $this->getJson($this->url("{$operation->id}/questions/{$id}/members"))->json('data.0.id'));
    }

    #[Test]
    public function a_blocked_locale_refuses_a_translation_until_it_is_answered(): void
    {
        $entry = $this->entry();
        $operation = $this->settledWith([$entry->id => ['en.seo.title' => self::MODEL]]);
        $run = AiRun::query()->where('operation_id', $operation->id)->firstOrFail();
        $item = $this->item($entry);
        // A translation entry has no frozen parts, only the operation's locales.
        $item->forceFill(['status' => AiOperationItemStatus::Running, 'batch' => 2, 'run_id' => $run->id, 'detail' => ['parts' => ['de' => ['outcome' => 'blocked', 'reason' => 'agent', 'question' => self::MODEL, 'batch' => 1], 'fr' => ['outcome' => 'proposed']]]])->save();
        $context = OperationContext::forRun($run);

        $context?->ensureNotSettled('content', $entry->id, 'fr');

        $item->forceFill(['batch' => 1])->save();
        $context?->ensureNotSettled('content', $entry->id, 'de');

        $item->forceFill(['batch' => 2])->save();
        $this->expectException(ToolError::class);
        $context?->ensureNotSettled('content', $entry->id, 'de');
    }

    #[Test]
    public function recovery_after_a_crash_following_an_answer_starts_exactly_one_batch(): void
    {
        $entry = $this->entry();
        $operation = $this->settledWith([$entry->id => ['en.seo.title' => self::MODEL]]);
        $group = $this->group($operation, self::MODEL);
        $members = $this->getJson($this->url("{$operation->id}/questions/{$group['id']}/members"))->json('data');

        // What a crash right after the answer's commit leaves: a running operation and no batch.
        $this->item($entry)->answerBlocked(['en.seo.title' => ['id' => $members[0]['id'], 'question' => self::MODEL, 'answer' => 'Comfort.', 'answered_by' => (string) $this->user->id, 'answered_at' => now()->toIso8601String(), 'fingerprint' => $group['fingerprint']]]);
        $operation->forceFill(['state' => AiOperationState::Running, 'finished_at' => null])->save();

        app(RunRecovery::class)->sweep();
        app(RunRecovery::class)->sweep();

        $this->assertSame(2, $operation->refresh()->batch_count);
        $this->assertSame(1, AiRun::query()->where('operation_id', $operation->id)->where('state', 'running')->count());
        Queue::assertPushed(RunAgentJob::class, 1);

        // The delivery the crash swallowed arrives again and finds the answer already there.
        $this->postAnswers($operation, [['question_id' => $group['id'], 'answer' => 'Comfort.', 'member_ids' => [$members[0]['id']]]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'duplicate');
        $this->assertSame(2, $operation->refresh()->batch_count);
    }

    /** @param  array<string, array<string, string>>  $blocked  entry id => part => question */
    private function settledWith(array $blocked): AiOperation
    {
        Queue::fake();
        $operation = $this->approve(self::PLAN);
        AiRun::query()->where('operation_id', $operation->id)->update(['state' => 'completed', 'finished_at' => now()]);

        foreach (AiOperationItem::query()->where('operation_id', $operation->id)->get() as $item) {
            $parts = [];
            foreach ($blocked[$item->target_id] ?? [] as $part => $question) {
                $parts[$part] = ['outcome' => 'blocked', 'reason' => 'agent', 'note' => 'Unknown.', 'question' => $question, 'batch' => 1];
            }
            $item->forceFill([
                'status' => AiOperationItemStatus::Skipped,
                'run_id' => null,
                'detail' => ['expected_parts' => ['en.seo.title', 'en.seo.description'], 'parts' => [...$parts, 'en.seo.description' => $parts['en.seo.description'] ?? ['outcome' => 'proposed', 'batch' => 1]]],
            ])->save();
        }
        $operation->forceFill(['state' => AiOperationState::Completed, 'current_run_id' => null, 'finished_at' => now()])->save();
        Queue::fake();

        return $operation->refresh();
    }

    private function operationId(Content $entry): string
    {
        return $this->item($entry)->operation_id;
    }

    private function item(Content $entry): AiOperationItem
    {
        return AiOperationItem::query()->where('target_id', $entry->id)->sole();
    }

    /** @return array{id: string, fingerprint: string} */
    private function group(AiOperation $operation, string $question): array
    {
        return collect($this->getJson($this->url("{$operation->id}/questions"))->json('data'))->firstWhere('question', $question);
    }

    private function answerGroup(AiOperation $operation, string $question, string $answer): TestResponse
    {
        $group = $this->group($operation, $question);

        return $this->postAnswers($operation, [['question_id' => $group['id'], 'answer' => $answer, 'fingerprint' => $group['fingerprint']]]);
    }

    /** @param  list<array<string, mixed>>  $answers */
    private function postAnswers(AiOperation $operation, array $answers): TestResponse
    {
        return $this->postJson($this->url("{$operation->id}/questions/answers"), ['answers' => $answers]);
    }

    /** @param  array<string, string>  $parts */
    private function block(Content $entry, array $parts): TurnResult
    {
        return FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $entry->id, 'outputs' => array_map(
            fn (string $part, string $question) => ['part' => $part, 'outcome' => 'blocked', 'reason' => 'The image does not show it.', 'question' => $question],
            array_keys($parts),
            $parts,
        )]]]);
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/ai/operations/{$path}";
    }

    /** @param  array<string, mixed>  $plan */
    private function approve(array $plan): AiOperation
    {
        $id = $this->postJson("/mgmt/v1/spaces/{$this->space->id}/ai/operations", ['kind' => 'general', 'prompt' => 'Write metadata.', 'scope' => ['type' => 'content']])->assertCreated()->json('data.id');
        $operation = AiOperation::query()->findOrFail($id);
        $hash = $this->patchJson($this->url($id), ['plan' => $plan])->assertOk()->json('data.plan_hash');
        $this->postJson($this->url("{$id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $operation->counts['scope_hash']])->assertOk();

        return $operation->refresh();
    }

    /** @param  array<string, mixed>  $seo */
    private function propose(Content $entry, array $seo): TurnResult
    {
        return FakeAiDriver::toolCall('propose_update', ['target_type' => 'content', 'id' => $entry->id, 'version_id' => $entry->current_version_id, 'changes' => [['path' => 'seo', 'value' => $seo]]]);
    }

    private function entry(): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => 'Entry '.Str::random(4),
            'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => 'en',
        ]);
        $content->id = strtolower((string) Str::ulid());
        $content->current_version_id = ContentVersion::query()->forceCreate(['content_id' => $content->id, 'content' => [], 'created_by_id' => $this->user->id])->id;
        $content->save();

        return $content->fresh();
    }
}
