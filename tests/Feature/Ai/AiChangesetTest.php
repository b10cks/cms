<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\UpdateContent;
use App\Enums\AiChangesetItemStatus;
use App\Enums\AiRunState;
use App\Events\Space\ContentUpdated;
use App\Models\Management\AiChangeset;
use App\Models\Management\AiChangesetItem;
use App\Models\Management\AiRun;
use App\Models\Management\AiSkill;
use App\Models\Management\Space;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Changesets\EntryScope;
use App\Services\Ai\Changesets\PreflightEstimate;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiChangesetTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private FakeAiDriver $driver;

    private Block $page;

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

        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title', 'max_length' => 60],
                'summary' => ['type' => 'text', 'name' => 'Summary'],
            ],
        ]);
    }

    #[Test]
    public function an_invalid_patch_is_returned_to_the_model_and_never_stored(): void
    {
        $content = $this->publishedPage(['title' => 'Hello', 'summary' => 'World']);
        $this->driver->push(
            $this->propose($content, ['subtitle' => 'Unknown field']),
            $this->propose($content, ['title' => str_repeat('x', 200)]),
            FakeAiDriver::text('Gave up.'),
        );

        $run = $this->runToEnd();

        $results = collect($run->transcript)->where('role', 'tool')->pluck('content');
        $this->assertStringContainsString('Unknown field', $results[0] ?? $results->first());
        $this->assertStringContainsString('title', (string) $results->last());
        $this->assertStringContainsString('error', (string) $results->last());
        $this->assertSame(0, AiChangesetItem::query()->count());
    }

    #[Test]
    public function accepting_a_field_with_an_unchanged_base_creates_a_draft_and_publishes_nothing(): void
    {
        $content = $this->publishedPage(['title' => 'Hello', 'summary' => 'World']);
        $publishedVersionId = $content->published_version_id;
        $this->driver->push($this->propose($content, ['title' => 'Hello there']), FakeAiDriver::text('Done.'));
        $this->runToEnd();

        $changeset = AiChangeset::query()->sole();
        Event::fake([ContentUpdated::class]);
        $this->postJson($this->url("ai/changesets/{$changeset->id}/accept"), [], ['X-Socket-ID' => '1234.5678'])
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('data.status', 'applied');

        // The tab that accepted has the entry open, so the broadcast includes it.
        Event::assertDispatched(ContentUpdated::class, fn (ContentUpdated $event) => $event->socket === null);
        $content->refresh()->load('current_version');
        $this->assertSame(['title' => 'Hello there', 'summary' => 'World'], $content->current_version->content);
        $this->assertNotSame($publishedVersionId, $content->current_version_id);
        $this->assertSame($publishedVersionId, $content->published_version_id);
        $this->assertNull($content->current_version->published_at);
        $this->assertSame('Hello', ContentVersion::query()->find($publishedVersionId)->content['title']);
    }

    #[Test]
    public function a_field_edited_after_the_run_read_it_conflicts_while_other_fields_apply(): void
    {
        $content = $this->publishedPage(['title' => 'Hello', 'summary' => 'World']);
        $readVersion = $content->current_version_id;

        $this->driver->push(
            FakeAiDriver::toolCall('get_entry', ['id' => $content->id]),
            function () use ($content, $readVersion) {
                // An editor saves the summary while the run is thinking.
                app(UpdateContent::class)->execute(['content' => ['title' => 'Hello', 'summary' => 'Edited by hand'], 'force' => true], $content->fresh(), $this->space, $this->user);

                return $this->propose($content, ['title' => 'Hello there', 'summary' => 'AI summary'], $readVersion);
            },
            FakeAiDriver::text('Done.'),
        );
        $this->runToEnd();

        $changeset = AiChangeset::query()->sole();
        $this->postJson($this->url("ai/changesets/{$changeset->id}/accept"))
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('conflicts', 1)
            ->assertJsonPath('data.status', 'partially_applied');

        $summary = $changeset->items()->where('path', 'summary')->sole();
        $this->assertSame(AiChangesetItemStatus::Conflict, $summary->status);
        $this->assertSame('Edited by hand', $summary->current);
        $this->assertSame(['title' => 'Hello there', 'summary' => 'Edited by hand'], $content->refresh()->getCurrentContent());
    }

    #[Test]
    public function a_user_without_edit_rights_on_the_target_cannot_accept_its_items(): void
    {
        $content = $this->publishedPage(['title' => 'Hello', 'summary' => 'World']);
        $this->driver->push($this->propose($content, ['title' => 'Hello there']), FakeAiDriver::text('Done.'));
        $this->runToEnd();
        $changeset = AiChangeset::query()->sole();

        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');

        $this->actingAs($viewer)->postJson($this->url("ai/changesets/{$changeset->id}/accept"))->assertForbidden();

        $this->assertSame(AiChangesetItemStatus::Pending, $changeset->items()->sole()->status);
        $this->assertSame('Hello', $content->refresh()->getCurrentContent()['title']);
    }

    #[Test]
    public function a_background_run_hitting_its_usd_cap_stops_with_a_partial_changeset_in_its_thread(): void
    {
        config(['ai.runs.max_usd' => 0.05]);
        $pages = [
            $this->publishedPage(['title' => 'One', 'summary' => '']),
            $this->publishedPage(['title' => 'Two', 'summary' => '']),
            $this->publishedPage(['title' => 'Three', 'summary' => '']),
        ];
        foreach ($pages as $page) {
            $this->driver->push($this->propose($page, ['summary' => "About {$page->name}"], cost: 0.03));
        }

        $this->postJson($this->url('ai/runs'), [
            'surface' => 'bulk:content',
            'prompt' => 'Write summaries.',
            'background' => true,
            'targets' => ['type' => 'content', 'ids' => array_map(fn (Content $page) => $page->id, $pages)],
        ])->assertStatus(202);

        $run = AiRun::query()->sole();
        $this->assertSame(AiRunState::Completed, $run->state);
        $this->assertTrue($run->partial);
        $this->assertSame('max_usd', $run->setup['stopped_by']);
        $this->assertSame(2, $this->driver->calls);

        $this->getJson($this->url('ai/threads'))
            ->assertOk()
            ->assertJsonPath('meta.attention', 1)
            ->assertJsonPath('data.0.status', 'needs_review')
            ->assertJsonPath('data.0.entry_count', 2);
        $this->getJson($this->url("ai/threads/{$run->conversation_id}"))
            ->assertJsonPath('data.turns.0.changeset.item_count', 2)
            ->assertJsonPath('data.turns.0.changeset.run.partial', true)
            ->assertJsonPath('data.turns.0.changeset.run.stopped_by', 'max_usd');
    }

    #[Test]
    public function preflight_count_and_estimate_match_the_entries_the_run_then_touches(): void
    {
        $pages = [
            $this->publishedPage(['title' => 'One', 'summary' => 'First']),
            $this->publishedPage(['title' => 'Two', 'summary' => 'Second']),
            $this->publishedPage(['title' => 'Three', 'summary' => 'Third']),
        ];
        $other = Block::query()->create(['external_id' => (string) Str::uuid(), 'name' => 'Post', 'slug' => 'post', 'type' => 'root', 'schema' => $this->page->schema->toArray()]);
        $outside = $this->publishedPage(['title' => 'Elsewhere', 'summary' => ''], $other);

        $preflight = $this->postJson($this->url('ai/preflight'), [
            'surface' => 'bulk:content',
            'scope' => ['type' => 'content', 'filters' => ['block' => 'page']],
        ])->assertOk()->json('data');

        $this->assertSame(3, $preflight['count']);
        $this->assertFalse($preflight['background']);

        foreach ([...$pages, $outside] as $page) {
            $this->driver->push($this->propose($page, ['summary' => "New {$page->name}"]));
        }
        $this->driver->push(FakeAiDriver::text('Done.'));

        $this->postJson($this->url('ai/runs'), [
            'surface' => 'bulk:content',
            'prompt' => 'Rewrite the summaries.',
            'background' => true,
            'targets' => ['type' => 'content', 'ids' => $preflight['ids']],
        ])->assertStatus(202);

        $touched = AiChangesetItem::query()->pluck('target_id')->unique()->sort()->values()->all();
        $this->assertSame(collect($preflight['ids'])->sort()->values()->all(), $touched);

        $estimate = PreflightEstimate::for(app(EntryScope::class)->resolve('content', ['ids' => $touched]), null);
        $this->assertSame($estimate['input_tokens'], $preflight['input_tokens']);
        $this->assertSame($estimate['output_tokens'], $preflight['output_tokens']);
    }

    #[Test]
    public function a_schedule_over_a_built_in_skill_produces_a_changeset_without_any_space_skill(): void
    {
        $page = $this->publishedPage(['title' => 'One', 'summary' => 'First']);
        $this->driver->push($this->propose($page, ['summary' => 'Erste']));
        $this->driver->push(FakeAiDriver::text('Done.'));

        $this->postJson($this->url('ai/schedules'), [
            'name' => 'Translate pages to German',
            'skill' => 'translate-fields',
            'scope' => ['type' => 'content', 'filters' => ['block' => 'page']],
            'cron' => '* * * * *',
            'max_usd' => 0.5,
        ])->assertCreated();

        $this->artisan('ai:run-schedules')->assertSuccessful();

        $run = AiRun::query()->sole();
        $this->assertSame('translate-fields', $run->skill);
        $this->assertSame(AiRunState::Completed, $run->state);
        $this->assertSame('Erste', AiChangesetItem::query()->sole()->after);
        $this->assertSame(0, AiSkill::query()->count());
    }

    #[Test]
    public function preflight_uses_the_run_average_once_five_runs_finished_and_the_formula_before(): void
    {
        $this->publishedPage(['title' => 'One', 'summary' => 'First']);
        $this->publishedPage(['title' => 'Two', 'summary' => 'Second']);
        $payload = ['surface' => 'bulk:content', 'skill' => 'translate-fields', 'scope' => ['type' => 'content', 'filters' => ['block' => 'page']]];

        $formula = $this->postJson($this->url('ai/preflight'), $payload)->assertOk()->json('data.cost_usd');

        foreach (range(1, 5) as $i) {
            $run = AiRun::query()->forceCreate([
                'space_id' => $this->space->id,
                'actor_id' => $this->user->id,
                'surface' => 'bulk:content',
                'skill' => 'translate-fields',
                'state' => AiRunState::Completed,
                'setup' => '{}',
                'transcript' => '[]',
                'cost_usd' => 0.2,
            ]);
            $changeset = AiChangeset::query()->create(['space_id' => $this->space->id, 'run_id' => $run->id, 'actor_id' => $this->user->id, 'title' => 'Run']);
            foreach (['a', 'b'] as $entry) {
                AiChangesetItem::query()->create(['changeset_id' => $changeset->id, 'target_type' => 'content', 'target_id' => "{$entry}{$i}", 'path' => 'summary']);
            }
            if ($i === 4) {
                $this->assertSame($formula, $this->postJson($this->url('ai/preflight'), $payload)->json('data.cost_usd'));
            }
        }

        $this->postJson($this->url('ai/preflight'), $payload)->assertOk()->assertJsonPath('data.cost_usd', 0.2);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function propose(Content $content, array $changes, ?string $versionId = null, ?float $cost = null): TurnResult
    {
        return new TurnResult('', [new ToolCall('call_'.Str::random(6), 'propose_update', [
            'target_type' => 'content',
            'id' => $content->id,
            'version_id' => $versionId ?? $content->current_version_id,
            'changes' => array_map(fn ($path, $value) => ['path' => $path, 'value' => $value], array_keys($changes), $changes),
        ])], new TurnUsage(10, 5, $cost));
    }

    private function runToEnd(): AiRun
    {
        $service = app(AiRunService::class);
        $run = $service->start(new StartRun($this->space, $this->user, 'cmdk', prompt: 'Improve the page.'));
        iterator_to_array($service->advance($run), false);

        return $run->refresh();
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function publishedPage(array $values, ?Block $block = null): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => ($block ?? $this->page)->id,
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
