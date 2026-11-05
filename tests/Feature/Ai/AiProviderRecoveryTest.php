<?php

namespace Tests\Feature\Ai;

use App\Enums\AiOperationPauseReason;
use App\Enums\AiOperationState;
use App\Enums\AiRunState;
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
use App\Services\Ai\Assets\InspectionImages;
use App\Services\Ai\Drivers\BedrockDriver;
use App\Services\Ai\Drivers\StreamsOpenAiTurns;
use App\Services\Ai\Exceptions\ProviderCallFailed;
use App\Services\Ai\Exceptions\ProviderFailure;
use App\Services\Ai\Exceptions\ProviderFailureKind;
use App\Services\Ai\Run\RunOptions;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\ToolSet;
use App\Services\Ai\Run\Transcript;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use App\Services\Auth\MembershipService;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Exception\EventStreamDataException;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result;
use Closure;
use Generator;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use OpenAI;
use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

/**
 * Failures come from the real provider exception classes, through the real
 * classification. A faked queue is a retry job that never arrived; calling the
 * job by hand is a job arriving, early or late.
 */
class AiProviderRecoveryTest extends TestCase
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
    public function a_retryable_failure_waits_for_its_backoff_then_finishes_without_repeating_work_or_costing_an_attempt(): void
    {
        $this->freezeTime();
        [$first, $second] = $this->pages(2);
        [$operation, $run] = $this->approved();
        $this->driver->push($this->propose($first), $this->unavailable(), $this->propose($second), FakeAiDriver::text('Batch done.'));

        $this->work($run);

        // The completed proposal and its tool result are kept; the failed call added nothing to the transcript.
        $this->assertSame(2, $this->driver->calls);
        $run->refresh();
        $this->assertSame(AiRunState::Running, $run->state);
        $checkpoint = $run->transcript;
        $this->assertSame('tool', end($checkpoint)['role']);
        $this->assertSame(1, AiChangesetItem::query()->count());
        $this->assertSame(1, $run->setup['provider_retry']['attempts']);
        $this->assertSame(['kind' => 'unavailable', 'status' => 503, 'code' => null], $run->setup['provider_retry']['failure']);
        Queue::assertPushed(RunAgentJob::class, fn (RunAgentJob $job) => $job->delay !== null && $job->delay->equalTo(now()->addSeconds(60)));
        $this->assertSame('provider_retry_scheduled', AiRunEvent::query()->where('run_id', $run->id)->orderByDesc('seq')->value('type'));
        $this->getJson("/mgmt/v1/spaces/{$this->space->id}/ai/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.provider_retry.attempts', 1)
            ->assertJsonPath('data.provider_retry.failure.kind', 'unavailable')
            ->assertJsonPath('data.provider_retry.unconfirmed_calls', 0)
            ->assertJsonPath('data.usage.cost_confirmed_at', null);

        // Woken 59 seconds in: no call, nothing changes.
        $this->travel(59)->seconds();
        $this->work($run);
        $this->assertSame(2, $this->driver->calls);
        $this->assertSame($checkpoint, $run->refresh()->transcript);

        $this->travel(2)->seconds();
        $this->work($run);

        $this->assertSame(4, $this->driver->calls);
        $this->assertSame($checkpoint, \array_slice($this->driver->transcripts[2], 0, \count($checkpoint)));
        $this->assertCount(\count($checkpoint), $this->driver->transcripts[2]);
        $this->assertSame(AiRunState::Completed, $run->refresh()->state);
        $this->assertNull($run->setup['provider_retry']['not_before']);
        $operation->refresh();
        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->assertSame(1, $operation->counts['provider_retries']);
        $this->assertArrayNotHasKey('unconfirmed_provider_calls', $operation->counts);
        $this->assertSame(2, AiChangesetItem::query()->count());
        $this->assertSame(2, AiOperationItem::query()->where('status', 'written')->where('attempts', 0)->count());
    }

    #[Test]
    public function the_sweep_replaces_a_lost_retry_job_only_when_it_is_due_and_only_once(): void
    {
        config(['ai.runs.provider_retries.backoff_seconds' => 600, 'ai.runs.provider_retries.max_backoff_seconds' => 600]);
        [$page] = $this->pages(1);
        [$operation, $run] = $this->approved();
        $this->driver->push($this->unavailable(), $this->propose($page), FakeAiDriver::text('Batch done.'));
        $this->work($run);

        // Past the recovery grace but not past the backoff: recovery and resume both leave it waiting.
        $jobs = $this->pushedJobs();
        $this->travel((int) config('ai.runs.recovery_grace_seconds') + 1)->seconds();
        $this->sweep();
        $this->assertSame($jobs, $this->pushedJobs());
        $this->postJson($this->url("ai/operations/{$operation->id}/resume"))->assertOk();
        $this->assertSame(1, $this->driver->calls);
        // The resume's own job gets nowhere: the backoff holds for whoever arrives.
        $this->work($run);
        $this->assertSame(1, $this->driver->calls);

        // A fresh process knows all of this from the row alone.
        $this->app->forgetInstance(AiRunService::class);
        $jobsBefore = $this->pushedJobs();
        $this->travel(301)->seconds();
        $this->sweep();
        $this->sweep();
        $this->assertSame($jobsBefore + 1, $this->pushedJobs());
        $this->assertSame(1, $this->driver->calls);

        $this->work(AiRun::query()->findOrFail($run->id));
        $this->assertSame(3, $this->driver->calls);
        $this->assertSame(AiOperationState::Completed, $operation->refresh()->state);
    }

    #[Test]
    public function duplicate_jobs_for_a_due_retry_make_one_provider_call(): void
    {
        [$page] = $this->pages(1);
        [$operation, $run] = $this->approved();
        $this->driver->push($this->unavailable(), $this->propose($page), FakeAiDriver::text('Batch done.'));
        $this->work($run);
        $this->travel(61)->seconds();

        // One worker holds the run, the second job drops out.
        $run->forceFill(['lease_owner' => 'other-worker', 'lease_expires_at' => now()->addMinute()])->save();
        $this->work($run);
        $this->assertSame(1, $this->driver->calls);
        $run->forceFill(['lease_owner' => null, 'lease_expires_at' => null])->save();

        $this->work($run);
        $this->work($run);

        $this->assertSame(3, $this->driver->calls);
        $this->assertSame(AiOperationState::Completed, $operation->refresh()->state);
    }

    #[Test]
    #[DataProvider('permanentFailures')]
    public function a_permanent_failure_stops_at_once_and_only_a_rejected_request_costs_an_attempt(int $status, int $attempts): void
    {
        $this->pages(1);
        [$operation, $run] = $this->approved();
        $this->driver->push($this->failing(new ErrorException(['message' => 'No.'], new Response($status))));

        $this->work($run);

        $this->assertSame(1, $this->driver->calls);
        $this->assertSame(AiRunState::Failed, $run->refresh()->state);
        $this->assertStringStartsWith('provider_error: ', $run->error);
        $this->assertNull($run->setup['provider_retry']['not_before']);
        $this->assertSame(AiOperationPauseReason::BatchFailed, $operation->refresh()->pause_reason);
        $this->assertSame($attempts, AiOperationItem::query()->value('attempts'));
    }

    /** @return array<string, list<int>> */
    public static function permanentFailures(): array
    {
        return ['bad key' => [401, 0], 'no credits' => [402, 0], 'bad request' => [400, 1]];
    }

    #[Test]
    public function a_failure_nobody_classified_is_terminal_and_treated_as_before(): void
    {
        $this->pages(1);
        [$operation, $run] = $this->approved();
        $this->driver->push(function () {
            throw new ProviderCallFailed('The provider is down.');
        });

        $this->work($run);

        $this->assertSame(1, $this->driver->calls);
        $this->assertSame(AiRunState::Failed, $run->refresh()->state);
        $this->assertSame(AiOperationPauseReason::BatchFailed, $operation->refresh()->pause_reason);
        $this->assertSame(1, AiOperationItem::query()->value('attempts'));
    }

    #[Test]
    public function exhausted_retries_pause_visibly_keep_proposals_and_a_resume_finishes_the_rest(): void
    {
        [$first, $second] = $this->pages(2);
        [$operation, $run] = $this->approved();
        $this->driver->push($this->propose($first), ...array_fill(0, 4, $this->unavailable()));

        $this->work($run);
        foreach ([60, 120, 240] as $wait) {
            $this->assertSame(AiRunState::Running, $run->refresh()->state);
            $this->travel($wait)->seconds();
            $this->work($run);
        }

        $this->assertSame(5, $this->driver->calls);
        $this->assertSame(AiRunState::Failed, $run->refresh()->state);
        $this->assertStringStartsWith('provider_unavailable: ', $run->error);
        $operation->refresh();
        $this->assertSame(AiOperationState::Paused, $operation->state);
        $this->assertSame(AiOperationPauseReason::BatchFailed, $operation->pause_reason);
        $this->assertStringStartsWith('provider_unavailable: ', $operation->error);
        $this->assertSame(3, $operation->counts['provider_retries']);
        $this->assertSame(1, AiChangesetItem::query()->count());
        $this->assertSame(0, AiOperationItem::query()->sum('attempts'));
        $this->assertSame($first->id, AiOperationItem::query()->where('status', 'written')->value('target_id'));

        // The next call would answer, but nothing happens until the editor resumes.
        $this->sweep();
        $this->assertSame(5, $this->driver->calls);

        $this->driver->push($this->propose($second), FakeAiDriver::text('Batch done.'));
        $this->postJson($this->url("ai/operations/{$operation->id}/resume"))->assertOk();
        $this->work(AiRun::query()->findOrFail($operation->refresh()->current_run_id));

        $this->assertSame(AiOperationState::Completed, $operation->refresh()->state);
        $this->assertSame(2, AiChangesetItem::query()->count());
        $this->assertSame(0, AiOperationItem::query()->sum('attempts'));
    }

    #[Test]
    public function interrupted_paid_streams_keep_their_evidence_and_get_a_tighter_bound_than_rejected_requests(): void
    {
        $this->pages(1);
        [$operation, $run] = $this->approved();
        $interrupted = fn (string $generation) => function () use ($generation) {
            throw new ProviderCallFailed('Stream broke.', ProviderFailure::truncated(new TurnUsage(100, 20, 0.01, generationId: $generation)));
        };
        $this->driver->push($interrupted('gen-1'), FakeAiDriver::text('Answered.'));
        config(['ai.runs.max_output_corrections' => 0]);

        $this->work($run);
        $run->refresh();
        $this->assertSame(['gen-1'], $run->setup['generations']);
        $this->assertSame(100, $run->input_tokens);
        $this->assertEqualsWithDelta(0.01, $run->cost_usd, 1e-6);
        $this->assertSame(1, $run->setup['provider_retry']['unconfirmed_calls']);
        $event = AiRunEvent::query()->where('run_id', $run->id)->orderByDesc('seq')->first();
        $this->assertTrue($event->payload['possibly_billed']);

        // An answered call resets consecutive retries, not the unconfirmed calls.
        $this->travel(61)->seconds();
        $this->work($run);
        $this->assertSame(1, $operation->refresh()->counts['unconfirmed_provider_calls']);
        $this->assertSame(1, $operation->counts['provider_retries']);
        $this->assertEqualsWithDelta(0.01, $operation->spent_usd, 1e-6);

        // With a fresh run: the third interrupted call stops though only two retries were used.
        AiOperation::query()->update(['state' => AiOperationState::Cancelled]);
        $this->pages(1);
        [, $next] = $this->approved();
        $this->driver->push($interrupted('gen-2'), $interrupted('gen-3'), $interrupted('gen-4'));
        $this->work($next);
        $this->travel(61)->seconds();
        $this->work($next);
        $this->travel(121)->seconds();
        $this->work($next);

        $next->refresh();
        $this->assertSame(AiRunState::Failed, $next->state);
        $this->assertStringStartsWith('provider_unavailable: ', $next->error);
        $this->assertSame(['gen-2', 'gen-3', 'gen-4'], $next->setup['generations']);
        $this->assertSame(2, $next->setup['provider_retry']['attempts']);
        $this->assertSame(3, $next->setup['provider_retry']['unconfirmed_calls']);
    }

    #[Test]
    public function a_pause_during_the_backoff_stops_the_retry_with_no_call_and_no_attempt(): void
    {
        $this->pages(1);
        [$operation, $run] = $this->approved();
        $this->driver->push($this->unavailable());
        $this->work($run);
        $this->postJson($this->url("ai/operations/{$operation->id}/pause"))->assertOk();

        $this->travel(61)->seconds();
        $this->work($run);

        $this->assertSame(1, $this->driver->calls);
        $this->assertSame(AiRunState::Cancelled, $run->refresh()->state);
        $operation->refresh();
        $this->assertSame(AiOperationState::Paused, $operation->state);
        $this->assertSame(AiOperationPauseReason::User, $operation->pause_reason);
        $this->assertSame(0, AiOperationItem::query()->value('attempts'));
    }

    #[Test]
    public function a_cancellation_during_the_backoff_is_immediate_and_the_retry_job_finds_nothing_to_do(): void
    {
        $this->pages(1);
        [$operation, $run] = $this->approved();
        $this->driver->push($this->unavailable());
        $this->work($run);

        $this->postJson($this->url("ai/operations/{$operation->id}/cancel"))->assertOk();
        $this->assertSame(AiRunState::Cancelled, $run->refresh()->state);

        $this->travel(61)->seconds();
        $this->work($run);

        $this->assertSame(1, $this->driver->calls);
        $this->assertSame(AiOperationState::Cancelled, $operation->refresh()->state);
        $this->assertSame(0, AiOperationItem::query()->value('attempts'));
    }

    #[Test]
    public function access_revoked_during_the_backoff_makes_no_call(): void
    {
        $this->pages(1);
        [$operation, $run] = $this->approved();
        $this->driver->push($this->unavailable());
        $this->work($run);
        app(MembershipService::class)->removeSpaceMembership($this->space, $this->user);

        $this->travel(61)->seconds();
        $this->work($run);

        $this->assertSame(1, $this->driver->calls);
        $this->assertSame('access_revoked', $run->refresh()->error);
        $this->assertSame(AiOperationPauseReason::AccessRevoked, $operation->refresh()->pause_reason);
        $this->assertSame(0, AiOperationItem::query()->value('attempts'));
    }

    #[Test]
    public function usage_reported_by_a_failed_call_counts_against_the_budget_before_the_retry(): void
    {
        $this->pages(1);
        [$operation, $run] = $this->approved(maxUsd: 0.05);
        $this->driver->push(function () {
            throw new ProviderCallFailed('Stream broke.', ProviderFailure::truncated(new TurnUsage(1000, 500, 0.05)));
        });
        $this->work($run);

        $this->travel(61)->seconds();
        $this->work($run);

        $this->assertSame(1, $this->driver->calls);
        $operation->refresh();
        $this->assertSame(AiOperationPauseReason::Budget, $operation->pause_reason);
        $this->assertEqualsWithDelta(0.05, $operation->spent_usd, 1e-6);
        $this->assertEqualsWithDelta(0.05, $operation->max_usd, 1e-6);
        $this->assertSame(0, AiOperationItem::query()->value('attempts'));
    }

    #[Test]
    public function backoff_does_not_use_up_the_runs_time_budget(): void
    {
        config(['ai.runs.max_seconds' => 100]);
        $this->pages(1);
        [, $run] = $this->approved();
        $this->driver->push($this->rateLimited(retryAfter: 200), FakeAiDriver::text('Batch done.'));
        $this->work($run);

        $this->travel(201)->seconds();
        $this->work($run);

        $this->assertSame(2, $this->driver->calls);
        $run->refresh();
        $this->assertSame(AiRunState::Completed, $run->state);
        $this->assertFalse($run->partial);
    }

    #[Test]
    public function images_awaiting_their_call_are_resent_as_they_were_and_count_as_delivered_only_once_answered(): void
    {
        $this->pages(1);
        [, $run] = $this->approved();
        $image = ['type' => 'image', 'mime_type' => 'image/png', 'data' => 'abc', 'asset_id' => 'asset-1', 'sha256' => 'hash-1'];
        $run->forceFill(['transcript' => $run->transcriptObject()->appendUser([$image])->toArray()])->save();
        $checkpoint = $run->transcript;
        $this->driver->push($this->unavailable(), FakeAiDriver::text('Batch done.'));

        $this->work($run);
        $this->assertSame($checkpoint, $run->refresh()->transcript);
        $this->assertSame([], InspectionImages::delivered($run->transcript));

        $this->travel(61)->seconds();
        $this->work($run);

        $this->assertSame($checkpoint, $this->driver->transcripts[1]);
        $this->assertSame('hash-1', InspectionImages::delivered($run->refresh()->transcript)['asset-1']['sha256']);
        $this->assertSame('assistant', collect($run->transcript)->last()['role']);
    }

    #[Test]
    public function an_inline_run_fails_right_away_because_its_caller_is_waiting(): void
    {
        $this->driver->push($this->unavailable());

        $run = app(AiRunService::class)->runToCompletion(new StartRun(space: $this->space, actor: $this->user, surface: 'cmdk', skill: null, prompt: 'Hello'));

        $this->assertSame(1, $this->driver->calls);
        $this->assertSame(AiRunState::Failed, $run->state);
        $this->assertStringStartsWith('provider_error: ', $run->error);
    }

    /**
     * Real Guzzle responses through the real OpenAI client and its streaming path.
     *
     * @param  list<mixed>  $replies
     */
    #[Test]
    #[DataProvider('openAiFailures')]
    public function the_openai_stream_preserves_what_the_provider_said(array $replies, ProviderFailureKind $kind, ?int $status, ?string $code, ?int $retryAfter, ?string $generation): void
    {
        $failure = $this->failureOf($this->openAiTurn($replies));

        $this->assertSame($kind, $failure->kind);
        $this->assertSame($status, $failure->status);
        $this->assertSame($code, $failure->code);
        $this->assertSame($retryAfter, $failure->retryAfterSeconds);
        $this->assertSame($generation, $failure->usage?->generationId);
    }

    /** @return array<string, list<mixed>> */
    public static function openAiFailures(): array
    {
        $json = ['Content-Type' => 'application/json'];
        $request = new Request('POST', 'https://provider.test');
        $start = 'data: '.json_encode(self::chunk('gen-9', 'Hel'))."\n\n";

        return [
            '503 before the stream' => [[new Response(503, ['Retry-After' => '120'])], ProviderFailureKind::Unavailable, 503, null, 120, null],
            '429 rate limit' => [[new Response(429, ['Retry-After' => '30', ...$json], '{"error":{"message":"Slow down","code":"rate_limit_exceeded"}}')], ProviderFailureKind::RateLimited, 429, 'rate_limit_exceeded', 30, null],
            '429 spent quota' => [[new Response(429, $json, '{"error":{"message":"No money","code":"insufficient_quota"}}')], ProviderFailureKind::Quota, 429, 'insufficient_quota', null, null],
            '401' => [[new Response(401, $json, '{"error":{"message":"Bad key","code":"invalid_api_key"}}')], ProviderFailureKind::Auth, 401, 'invalid_api_key', null, null],
            '402 OpenRouter credits' => [[new Response(402, $json, '{"error":{"message":"Credits","code":402}}')], ProviderFailureKind::Quota, 402, '402', null, null],
            '400 schema' => [[new Response(400, $json, '{"error":{"message":"Too long","code":"context_length_exceeded"}}')], ProviderFailureKind::Rejected, 400, 'context_length_exceeded', null, null],
            'could not connect' => [[new ConnectException('Refused.', $request, null, ['errno' => \CURLE_COULDNT_CONNECT])], ProviderFailureKind::Connection, null, null, null, null],
            'timeout may have reached the provider' => [[new ConnectException('Timed out.', $request, null, ['errno' => \CURLE_OPERATION_TIMEOUTED])], ProviderFailureKind::Interrupted, null, null, null, null],
            'error inside the stream' => [[new Response(200, [], $start.'data: {"error":{"message":"Upstream gone","code":502}}'."\n\n")], ProviderFailureKind::Interrupted, 502, '502', null, 'gen-9'],
            'permanent error inside the stream' => [[new Response(200, [], $start.'data: {"error":{"message":"Blocked","code":403}}'."\n\n")], ProviderFailureKind::Auth, 403, '403', null, 'gen-9'],
            'unrecognized error inside the stream' => [[new Response(200, [], $start.'data: {"error":{"message":"Hm","code":"odd_thing"}}'."\n\n")], ProviderFailureKind::Unknown, 200, 'odd_thing', null, 'gen-9'],
            'body cut off without a finish reason' => [[new Response(200, [], $start."data: [DONE]\n\n")], ProviderFailureKind::Interrupted, null, null, null, 'gen-9'],
            'body cut inside a JSON line' => [[new Response(200, [], $start.'data: {"id":"gen-9","cho')], ProviderFailureKind::Interrupted, null, null, null, 'gen-9'],
        ];
    }

    #[Test]
    public function a_finished_openai_stream_still_returns_its_turn(): void
    {
        $body = 'data: '.json_encode(self::chunk('gen-9', 'Hello'))."\n\n"
            .'data: '.json_encode(self::chunk('gen-9', '', 'stop'))."\n\n"
            ."data: [DONE]\n\n";

        $result = $this->drain($this->openAiTurn([new Response(200, [], $body)]));

        $this->assertSame('Hello', $result->text);
        $this->assertSame('gen-9', $result->usage->generationId);
    }

    /**
     * @param  Closure(): (AwsException|Result)  $reply
     */
    #[Test]
    #[DataProvider('bedrockFailures')]
    public function bedrock_preserves_what_the_provider_said(Closure $reply, ProviderFailureKind $kind, ?int $status, ?string $code, int $inputTokens): void
    {
        $failure = $this->failureOf($this->bedrockTurn($reply()));

        $this->assertSame($kind, $failure->kind);
        $this->assertSame($status, $failure->status);
        $this->assertSame($code, $failure->code);
        $this->assertSame($inputTokens, $failure->usage?->inputTokens);
    }

    /** @return array<string, list<mixed>> */
    public static function bedrockFailures(): array
    {
        $aws = fn (string $code, int $status) => fn () => new AwsException('No.', new Command('InvokeModelWithResponseStream'), ['code' => $code, 'response' => new Response($status)]);
        $start = ['chunk' => ['bytes' => '{"type":"message_start","message":{"usage":{"input_tokens":70}}}']];

        return [
            'throttled' => [$aws('ThrottlingException', 429), ProviderFailureKind::RateLimited, 429, 'ThrottlingException', 0],
            'unavailable' => [$aws('ServiceUnavailableException', 503), ProviderFailureKind::Unavailable, 503, 'ServiceUnavailableException', 0],
            'access denied' => [$aws('AccessDeniedException', 403), ProviderFailureKind::Auth, 403, 'AccessDeniedException', 0],
            'bad request' => [$aws('ValidationException', 400), ProviderFailureKind::Rejected, 400, 'ValidationException', 0],
            'quota' => [$aws('ServiceQuotaExceededException', 400), ProviderFailureKind::Quota, 400, 'ServiceQuotaExceededException', 0],
            'no connection' => [fn () => new AwsException('Gone.', new Command('InvokeModelWithResponseStream'), ['connection_error' => true]), ProviderFailureKind::Interrupted, null, null, 0],
            'throttled inside the stream' => [fn () => new Result(['body' => (function () use ($start) {
                yield $start;

                throw new EventStreamDataException('throttlingException', 'Slow down.');
            })()]), ProviderFailureKind::Interrupted, null, 'throttlingException', 70],
            'rejected inside the stream' => [fn () => new Result(['body' => (function () use ($start) {
                yield $start;

                throw new EventStreamDataException('validationException', 'Bad.');
            })()]), ProviderFailureKind::Rejected, null, 'validationException', 70],
            'ended without message_stop' => [fn () => new Result(['body' => [$start]]), ProviderFailureKind::Interrupted, null, null, 70],
        ];
    }

    #[Test]
    public function a_finished_bedrock_stream_still_returns_its_turn(): void
    {
        $result = $this->drain($this->bedrockTurn(new Result(['body' => [
            ['chunk' => ['bytes' => '{"type":"message_start","message":{"usage":{"input_tokens":70}}}']],
            ['chunk' => ['bytes' => '{"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hi"}}']],
            ['chunk' => ['bytes' => '{"type":"message_delta","usage":{"output_tokens":3}}']],
            ['chunk' => ['bytes' => '{"type":"message_stop"}']],
        ]])));

        $this->assertSame('Hi', $result->text);
        $this->assertSame(70, $result->usage->inputTokens);
    }

    #[Test]
    public function bedrock_agent_turns_do_not_add_sdk_retries_to_the_runs_retry_limit(): void
    {
        $mock = new AwsMockHandler([
            new AwsException('Unavailable.', new Command('InvokeModelWithResponseStream'), ['code' => 'ServiceUnavailableException', 'response' => new Response(503)]),
            new Result(['body' => [['chunk' => ['bytes' => '{"type":"message_stop"}']]]]),
        ]);
        $client = new BedrockRuntimeClient([
            'region' => 'eu-central-1',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'retries' => 3,
            'handler' => $mock,
        ]);
        $driver = new class($client) extends BedrockDriver
        {
            public function __construct(BedrockRuntimeClient $client)
            {
                parent::__construct(['key' => 'k']);
                $this->client = $client;
            }
        };

        $failure = $this->failureOf($driver->turn(new Transcript([['role' => 'user', 'content' => 'Hi']]), new ToolSet, new RunOptions('model', 10, 0.1)));

        $this->assertSame(ProviderFailureKind::Unavailable, $failure->kind);
        $this->assertCount(1, $mock);
        $this->assertSame(0, $mock->getLastCommand()['@retries']);
    }

    /** @return array<string, mixed> */
    private static function chunk(string $id, string $text, ?string $finish = null): array
    {
        return ['id' => $id, 'object' => 'chat.completion.chunk', 'created' => 1, 'model' => 'm', 'choices' => [
            ['index' => 0, 'delta' => $text !== '' ? ['content' => $text] : [], 'finish_reason' => $finish],
        ]];
    }

    /** @param  list<mixed>  $replies */
    private function openAiTurn(array $replies): Generator
    {
        $client = OpenAI::factory()
            ->withApiKey('key')
            ->withHttpClient(new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($replies))]))
            ->make();

        $driver = new class
        {
            use StreamsOpenAiTurns {
                streamOpenAiTurn as public;
            }

            public function getName(): string
            {
                return 'openrouter';
            }

            protected function getClient(): Client
            {
                throw new \LogicException('Not used.');
            }
        };

        return $driver->streamOpenAiTurn($client, ['model' => 'm', 'messages' => []]);
    }

    private function bedrockTurn(AwsException|Result $reply): Generator
    {
        $driver = new class(['key' => 'k', 'secret' => 's', 'region' => 'eu-central-1']) extends BedrockDriver
        {
            public AwsException|Result $reply;

            protected function getClient(): BedrockRuntimeClient
            {
                return new BedrockRuntimeClient([
                    'region' => 'eu-central-1',
                    'version' => 'latest',
                    'credentials' => ['key' => 'k', 'secret' => 's'],
                    'retries' => 0,
                    'handler' => new AwsMockHandler([$this->reply]),
                ]);
            }
        };
        $driver->reply = $reply;

        return $driver->turn(new Transcript([['role' => 'user', 'content' => 'Hi']]), new ToolSet, new RunOptions('model', 10, 0.1));
    }

    private function failureOf(Generator $turn): ProviderFailure
    {
        try {
            $this->drain($turn);
        } catch (ProviderCallFailed $e) {
            return $e->failure;
        }

        $this->fail('The call should have failed.');
    }

    private function drain(Generator $turn): TurnResult
    {
        foreach ($turn as $delta) {
            // text is not what these tests look at
        }

        return $turn->getReturn();
    }

    /** A job arriving for the run, in this process and with whatever the database says now. */
    private function work(AiRun $run): void
    {
        (new RunAgentJob($run->space_id, $run->id))->handle();
    }

    private function sweep(): void
    {
        $this->artisan('ai:recover-runs')->assertSuccessful();
    }

    private function pushedJobs(): int
    {
        return Queue::pushed(RunAgentJob::class)->count();
    }

    /** @return array{AiOperation, AiRun} an approved operation whose batch run waits for {@see work()} */
    private function approved(float $maxUsd = 1): array
    {
        Queue::fake();
        $operation = AiOperation::query()->findOrFail($this->postJson($this->url('ai/operations'), [
            'kind' => 'general',
            'prompt' => 'Rewrite the summaries.',
            'scope' => ['type' => 'content', 'filters' => ['block' => 'page']],
        ])->assertCreated()->json('data.id'));
        $hash = $this->patchJson($this->url("ai/operations/{$operation->id}"), ['plan' => ['summary' => 'Rewrite each summary.']])->assertOk()->json('data.plan_hash');
        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $hash, 'max_usd' => $maxUsd, 'scope_hash' => $operation->refresh()->counts['scope_hash']])->assertOk();
        $operation->refresh();

        return [$operation, AiRun::query()->findOrFail($operation->current_run_id)];
    }

    private function unavailable(): Closure
    {
        return $this->failing(new ServerException(new Response(503)));
    }

    private function rateLimited(int $retryAfter): Closure
    {
        return $this->failing(new RateLimitException(new Response(429, ['Retry-After' => (string) $retryAfter])));
    }

    private function failing(\Throwable $e): Closure
    {
        return function () use ($e) {
            throw new ProviderCallFailed('The provider failed.', ProviderFailure::classify($e), $e);
        };
    }

    private function propose(Content $content): TurnResult
    {
        return new TurnResult('', [new ToolCall('call_'.Str::random(6), 'propose_update', [
            'target_type' => 'content',
            'id' => $content->id,
            'version_id' => $content->current_version_id,
            'changes' => [['path' => 'summary', 'value' => "About {$content->name}"]],
        ])], new TurnUsage(10, 5, 0.01));
    }

    /** @return list<Content> */
    private function pages(int $count): array
    {
        return array_map(function () {
            $title = 'Page '.++$this->made;
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
            ]);
            $content->current_version_id = $version->id;
            $content->save();

            return $content->fresh();
        }, range(1, $count));
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }
}
