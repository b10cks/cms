<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PublishAiKnowledgeDocument;
use App\Enums\AiKnowledgeType;
use App\Enums\AiRunState;
use App\Jobs\RunAgentJob;
use App\Models\Management\AiKnowledgeDocument;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\Space\AiRun;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Knowledge\KnowledgeResolver;
use App\Services\Ai\Run\RunBusy;
use App\Services\Ai\Run\RunEvent;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\Transcript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiRunServiceTest extends TestCase
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
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
    }

    #[Test]
    public function a_tool_calling_run_stops_at_the_step_budget_and_reports_partial(): void
    {
        config(['ai.runs.max_steps' => 3]);
        for ($i = 0; $i < 10; $i++) {
            $this->driver->push(FakeAiDriver::toolCall('lookup_glossary', ['text' => 'hello']));
        }

        $run = $this->service()->start($this->start());
        $events = $this->drain($this->service()->advance($run));

        $run->refresh();
        $this->assertSame(3, $this->driver->calls);
        $this->assertSame(AiRunState::Completed, $run->state);
        $this->assertTrue($run->partial);
        $this->assertSame('max_steps', $run->setup['stopped_by']);
        $this->assertTrue(end($events)->payload['partial']);
    }

    #[Test]
    public function a_run_resumes_after_a_crash_from_its_last_checkpoint_without_repeating_a_provider_call(): void
    {
        $run = $this->service()->start($this->start());

        // The worker dies while running the tool, after the provider turn was checkpointed.
        $this->partialMock(KnowledgeResolver::class, function ($mock) {
            $mock->shouldReceive('glossaryTerms')->once()->andThrow(new RuntimeException('worker died'));
            $mock->shouldReceive('glossaryTerms')->andReturn([]);
        });
        $this->driver->push(FakeAiDriver::toolCall('lookup_glossary', ['text' => 'hello'], 'call_1'), FakeAiDriver::text('Done.'));

        try {
            $this->drain($this->service()->advance($run));
            $this->fail('The tool should have crashed the worker.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $this->driver->calls);
        $this->assertSame(AiRunState::Running, $run->refresh()->state);
        $this->assertSame('call_1', $run->transcriptObject()->pendingToolCalls()[0]->id);

        $this->drain($this->service()->advance($run));

        $this->assertSame(2, $this->driver->calls);
        $this->assertSame(AiRunState::Completed, $run->refresh()->state);
        $this->assertSame('Done.', $run->result['text']);
        $this->assertSame(1, collect($run->transcript)->where('role', 'tool')->count());
    }

    #[Test]
    public function a_paused_run_resumes_only_with_valid_answers_from_its_actor_once_and_takes_own_answers(): void
    {
        $this->driver->push(
            FakeAiDriver::toolCall('ask_questions', ['questions' => [[
                'id' => 'tone',
                'title' => 'Which tone?',
                'options' => [['id' => 'friendly', 'label' => 'Friendly'], ['id' => 'formal', 'label' => 'Formal']],
            ]]], 'call_q'),
            FakeAiDriver::text('Formal copy.'),
        );

        $run = $this->service()->start($this->start());
        $events = $this->drain($this->service()->advance($run));
        $this->assertSame('questionnaire', end($events)->type->value);
        $this->assertSame(AiRunState::AwaitingInput, $run->refresh()->state);

        $stranger = User::factory()->create();
        $this->assignSpaceRole($this->space, $stranger, 'owner');
        try {
            $this->service()->answer($run, $stranger, ['tone' => 'formal']);
            $this->fail('Another user must not answer.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            $this->service()->answer($run, $this->user, ['mood' => 'calm']);
            $this->fail('An unknown question must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('answers.mood', $e->errors());
        }

        $stale = AiRun::query()->findOrFail($run->id);
        $this->service()->answer($run, $this->user, ['tone' => 'Formal, but warm']);

        $this->expectException(RunBusy::class);
        try {
            $this->service()->answer($stale, $this->user, ['tone' => 'formal']);
        } finally {
            $this->drain($this->service()->advance($run));
            $this->assertSame('Formal copy.', $run->refresh()->result['text']);
            $this->assertSame(2, $this->driver->calls);
            $this->assertSame(
                ['answers' => [['id' => 'tone', 'question' => 'Which tone?', 'answer' => 'Formal, but warm', 'skipped' => false, 'own_answer' => true]]],
                json_decode(collect($run->transcript)->firstWhere('role', 'tool')['content'], true),
            );
        }
    }

    #[Test]
    public function an_inline_run_past_its_wall_time_continues_in_the_background_with_ordered_events(): void
    {
        Queue::fake();
        $this->driver->push(
            function () {
                usleep(300_000);

                return FakeAiDriver::toolCall('lookup_glossary', ['text' => 'hello']);
            },
            FakeAiDriver::text('Finished in the background.'),
        );

        $run = $this->service()->start($this->start());
        $inline = $this->drain($this->service()->advance($run, microtime(true) + 0.2));

        $this->assertSame('moved_to_background', end($inline)->type->value);
        $this->assertSame(AiRun::MODE_BACKGROUND, $run->refresh()->mode);
        Queue::assertPushed(RunAgentJob::class, fn (RunAgentJob $job) => $job->runId === $run->id);

        (new RunAgentJob($run->space_id, $run->id))->handle();

        $lastInline = end($inline)->seq;
        $stored = $this->service()->eventsAfter($run->refresh(), $lastInline);
        $seqs = [...array_map(fn (RunEvent $e) => $e->seq, $inline), ...$stored->pluck('seq')->all()];

        $this->assertSame(range(1, \count($seqs)), $seqs);
        $this->assertSame('completed', $stored->last()->type);
        $this->assertSame('Finished in the background.', $run->result['text']);
    }

    #[Test]
    public function a_run_keeps_the_knowledge_revisions_it_started_with(): void
    {
        $document = $this->referenceDocument($this->space, 'pricing', "# Pricing\n\nThe alpha plan costs 10 euros.");
        $run = $this->service()->start($this->start());

        $document->update(['draft' => "# Pricing\n\nThe beta plan costs 20 euros."]);
        app(PublishAiKnowledgeDocument::class)->handle($document, $this->user);

        $this->driver->push(
            FakeAiDriver::toolCall('search_knowledge', ['query' => 'plan costs']),
            FakeAiDriver::text('ok'),
        );
        $this->drain($this->service()->advance($run));

        $result = collect($run->refresh()->transcript)->firstWhere('role', 'tool')['content'];
        $this->assertStringContainsString('alpha plan', $result);
        $this->assertStringNotContainsString('beta plan', $result);
    }

    #[Test]
    public function search_knowledge_only_returns_published_enabled_documents_in_the_actors_scopes(): void
    {
        $team = Team::factory()->create();
        $this->space->forceFill(['team_id' => $team->id])->save();
        $otherSpace = Space::factory()->create();
        $otherTeam = Team::factory()->create();

        $this->referenceDocument($this->space, 'space-doc', 'Shipping takes three days from the space doc.');
        $this->referenceDocument($team, 'team-doc', 'Shipping is free from the team doc.');
        $this->referenceDocument($this->space, 'disabled-doc', 'Shipping secret from the disabled doc.')->update(['enabled' => false]);
        $this->referenceDocument($otherSpace, 'foreign-space', 'Shipping leak from another space.');
        $this->referenceDocument($otherTeam, 'foreign-team', 'Shipping leak from another team.');
        AiKnowledgeDocument::create([
            ...AiKnowledgeDocument::ownerAttributes($this->space),
            'slug' => 'draft-only', 'title' => 'Draft', 'type' => AiKnowledgeType::Reference,
            'draft' => 'Shipping draft that was never published.',
        ]);

        $this->driver->push(FakeAiDriver::toolCall('search_knowledge', ['query' => 'shipping', 'limit' => 8]), FakeAiDriver::text('ok'));
        $run = $this->service()->start($this->start());
        $this->drain($this->service()->advance($run));

        $hits = json_decode(collect($run->refresh()->transcript)->firstWhere('role', 'tool')['content'], true)['results'];
        $this->assertEqualsCanonicalizing(['space-doc', 'team-doc'], array_column($hits, 'slug'));
    }

    private function referenceDocument(Space|Team $owner, string $slug, string $markdown): AiKnowledgeDocument
    {
        $document = AiKnowledgeDocument::create([
            ...AiKnowledgeDocument::ownerAttributes($owner),
            'slug' => $slug,
            'title' => ucfirst($slug),
            'type' => AiKnowledgeType::Reference,
            'draft' => $markdown,
        ]);
        app(PublishAiKnowledgeDocument::class)->handle($document, $this->user);

        return $document->refresh();
    }

    private function start(): StartRun
    {
        // The general agent: every tool, plain text output.
        return new StartRun($this->space, $this->user, 'cmdk', prompt: 'Write something.');
    }

    private function service(): AiRunService
    {
        return app(AiRunService::class);
    }

    /**
     * @return list<RunEvent>
     */
    private function drain(\Generator $events): array
    {
        return iterator_to_array($events, false);
    }
}
