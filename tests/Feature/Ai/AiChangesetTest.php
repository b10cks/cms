<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\UpdateContent;
use App\Enums\AiChangesetItemStatus;
use App\Enums\AiRunState;
use App\Events\Space\ContentUpdated;
use App\Models\Management\AiSkill;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
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
    public function a_change_with_an_unchanged_base_goes_straight_to_a_draft_and_publishes_nothing(): void
    {
        $content = $this->publishedPage(['title' => 'Hello', 'summary' => 'World']);
        $publishedVersionId = $content->published_version_id;
        Event::fake([ContentUpdated::class]);
        $this->driver->push($this->propose($content, ['title' => 'Hello there']), FakeAiDriver::text('Done.'));
        $this->runToEnd();

        $changeset = AiChangeset::query()->sole();
        $this->assertSame('applied', $changeset->status->value);
        $this->assertSame(AiChangesetItemStatus::Accepted, $changeset->items()->sole()->status);

        // Whoever has the entry open gets the broadcast, also the tab that started the run.
        Event::assertDispatched(ContentUpdated::class, fn (ContentUpdated $event) => $event->socket === null);
        $content->refresh()->load('current_version');
        $this->assertSame(['title' => 'Hello there', 'summary' => 'World'], $content->current_version->content);
        $this->assertNotSame($publishedVersionId, $content->current_version_id);
        $this->assertSame($publishedVersionId, $content->published_version_id);
        $this->assertNull($content->current_version->published_at);
        $this->assertSame('Hello', ContentVersion::query()->find($publishedVersionId)->content['title']);
    }

    #[Test]
    public function a_bulk_accept_of_open_items_leaves_flags_and_invalid_entries_for_a_person(): void
    {
        $pages = array_map(fn (int $index) => $this->publishedPage(['title' => "Page {$index}", 'summary' => 'World']), range(1, 3));
        $changeset = AiChangeset::query()->create(['space_id' => $this->space->id, 'actor_id' => $this->user->id, 'title' => 'From before direct writes']);
        [$clean, $flagged, $invalid] = array_map(fn (Content $page) => $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_UPDATE, 'target_type' => 'content', 'target_id' => $page->id, 'target_label' => $page->name,
            'base_version_id' => $page->current_version_id, 'path' => 'summary', 'field_type' => 'text', 'before' => 'World', 'after' => "Summary of {$page->name}", 'status' => AiChangesetItemStatus::Pending,
        ]), $pages);
        $flagged->update(['flags' => [['code' => 'uncertain', 'message' => 'The page has two topics.']]]);
        // Longer than the title's 60 characters, as if the schema had changed since.
        $invalid->update(['path' => 'title', 'before' => 'Page 3', 'after' => str_repeat('x', 70)]);

        $this->postJson($this->url("ai/changesets/{$changeset->id}/accept"), ['bulk' => true])
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('failed', 1);

        $this->assertSame(AiChangesetItemStatus::Accepted, $clean->refresh()->status);
        foreach ([$flagged, $invalid] as $item) {
            $this->assertSame(AiChangesetItemStatus::Pending, $item->refresh()->status);
        }
        $this->assertSame('invalid', $invalid->flags[0]['code']);
    }

    #[Test]
    public function a_field_edited_after_the_run_read_it_conflicts_while_other_fields_apply_and_the_agent_is_told(): void
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
        $run = $this->runToEnd();

        $changeset = AiChangeset::query()->sole();
        $this->assertSame('partially_applied', $changeset->status->value);
        $summary = $changeset->items()->where('path', 'summary')->sole();
        $this->assertSame(AiChangesetItemStatus::Conflict, $summary->status);
        $this->assertSame('Edited by hand', $summary->current);
        $this->assertSame(['title' => 'Hello there', 'summary' => 'Edited by hand'], $content->refresh()->getCurrentContent());

        $result = json_decode((string) collect($run->transcript)->where('role', 'tool')->last()['content'], true);
        $this->assertSame(1, $result['written']);
        $this->assertSame('summary', $result['conflicts'][0]['path']);
        $this->assertStringContainsString('do not write them again', $result['conflict_note']);

        // The existing resolve flow: a person keeps the AI's text.
        $this->postJson($this->url("ai/changesets/{$changeset->id}/accept"), ['item_ids' => [$summary->id], 'overwrite' => true])->assertOk()->assertJsonPath('accepted', 1);
        $this->assertSame('AI summary', $content->refresh()->getCurrentContent()['summary']);
    }

    #[Test]
    public function a_user_without_edit_rights_on_the_target_cannot_resolve_its_items(): void
    {
        $content = $this->publishedPage(['title' => 'Hello', 'summary' => 'World']);
        $changeset = AiChangeset::query()->create(['space_id' => $this->space->id, 'actor_id' => $this->user->id, 'title' => 'Run']);
        $item = $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_UPDATE, 'target_type' => 'content', 'target_id' => $content->id, 'path' => 'title',
            'before' => 'Older', 'after' => 'Hello there', 'current' => 'Hello', 'status' => AiChangesetItemStatus::Conflict,
        ]);

        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');

        $this->actingAs($viewer)->postJson($this->url("ai/changesets/{$changeset->id}/accept"), ['overwrite' => true, 'item_ids' => [$item->id]])->assertForbidden();

        $this->assertSame(AiChangesetItemStatus::Conflict, $item->refresh()->status);
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
            // Written to drafts: nothing needs anyone.
            ->assertJsonPath('meta.attention', 0)
            ->assertJsonPath('data.0.entry_count', 0);
        $this->getJson($this->url("ai/threads/{$run->conversation_id}"))
            ->assertJsonPath('data.turns.0.changeset.item_count', 2)
            ->assertJsonPath('data.turns.0.changeset.run.partial', true)
            ->assertJsonPath('data.turns.0.changeset.run.stopped_by', 'max_usd');
    }

    #[Test]
    public function an_edited_selection_proposal_is_accepted_with_the_edited_value(): void
    {
        $this->driver->push(FakeAiDriver::text('Go to the cart'));
        $runs = app(AiRunService::class);
        $run = $runs->start(new StartRun($this->space, $this->user, 'selection', prompt: 'Rewrite', context: ['selection' => 'Go to checkout']));
        iterator_to_array($runs->advance($run), false);
        $changeset = AiChangeset::query()->sole();
        $item = $changeset->items()->sole();

        $this->postJson($this->url("ai/changesets/{$changeset->id}/accept"), [
            'item_ids' => [$item->id],
            'values' => [$item->id => 'Go to the basket'],
        ])->assertOk();

        $this->assertSame(['accepted', 'Go to the basket'], [$item->fresh()->status->value, $item->fresh()->final]);
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
        // The run writes to these entries, and the estimate is of what they held before it.
        $estimate = PreflightEstimate::for(app(EntryScope::class)->resolve('content', ['ids' => $preflight['ids']]), null);

        $this->postJson($this->url('ai/runs'), [
            'surface' => 'bulk:content',
            'prompt' => 'Rewrite the summaries.',
            'background' => true,
            'targets' => ['type' => 'content', 'ids' => $preflight['ids']],
        ])->assertStatus(202);

        $touched = AiChangesetItem::query()->pluck('target_id')->unique()->sort()->values()->all();
        $this->assertSame(collect($preflight['ids'])->sort()->values()->all(), $touched);

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
            'name' => 'Write page summaries',
            'skill' => 'meta-tags',
            'scope' => ['type' => 'content', 'filters' => ['block' => 'page']],
            'cron' => '* * * * *',
            'max_usd' => 0.5,
        ])->assertCreated();

        $this->artisan('ai:run-schedules')->assertSuccessful();

        $run = AiRun::query()->sole();
        $this->assertSame('meta-tags', $run->skill);
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
    #[Test]
    public function inserting_a_block_and_editing_another_in_the_same_list_become_one_written_item(): void
    {
        $page = $this->pageWithBlocks();
        $content = $this->publishedPage(['title' => 'Home', 'body' => [
            ['id' => 'b1', 'block' => 'hero', 'heading' => 'One'],
            ['id' => 'b2', 'block' => 'hero', 'heading' => 'Two'],
        ]], $page);
        $this->driver->push($this->proposeChanges($content, [
            ['path' => 'body.b2.heading', 'value' => 'Two, better'],
            ['path' => 'body', 'insert' => ['block' => 'hero', 'values' => ['heading' => 'New']], 'after' => 'b1'],
        ]), FakeAiDriver::text('Done.'));

        $run = $this->runToEnd();

        $item = AiChangesetItem::query()->sole();
        $this->assertSame(['body', 'blocks'], [$item->path, $item->field_type]);
        $this->assertSame(AiChangesetItemStatus::Accepted, $item->status);
        $result = json_decode((string) collect($run->transcript)->firstWhere('role', 'tool')['content'], true);
        $newId = $result['inserted_blocks'][0]['id'];

        $body = $content->refresh()->load('current_version')->current_version->content['body'];
        $this->assertSame(['b1', $newId, 'b2'], array_column($body, 'id'));
        $this->assertSame(['One', 'New', 'Two, better'], array_column($body, 'heading'));
    }

    #[Test]
    public function a_block_outside_the_whitelist_is_refused_and_a_removal_drops_the_block(): void
    {
        $page = $this->pageWithBlocks();
        $content = $this->publishedPage(['title' => 'Home', 'body' => [
            ['id' => 'b1', 'block' => 'hero', 'heading' => 'One'],
            ['id' => 'b2', 'block' => 'hero', 'heading' => 'Two'],
        ]], $page);
        $this->driver->push(
            $this->proposeChanges($content, [['path' => 'body', 'insert' => ['block' => 'quote', 'values' => ['text' => 'Hi']]]]),
            $this->proposeChanges($content, [['path' => 'body', 'remove' => 'b1']]),
            FakeAiDriver::text('Done.'),
        );

        $run = $this->runToEnd();

        $refusal = json_decode((string) collect($run->transcript)->where('role', 'tool')->first()['content'], true)['error'];
        $this->assertStringContainsString('"quote" is not allowed here. Allowed: hero.', $refusal);
        $this->assertSame(['b2'], array_column(AiChangesetItem::query()->sole()->after, 'id'));
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    private function proposeChanges(Content $content, array $changes): TurnResult
    {
        return new TurnResult('', [new ToolCall('call_'.Str::random(6), 'propose_update', [
            'target_type' => 'content',
            'id' => $content->id,
            'version_id' => $content->current_version_id,
            'changes' => $changes,
        ])], new TurnUsage(10, 5));
    }

    /** A page whose `body` block list allows only heroes; quotes exist but are not allowed there. */
    private function pageWithBlocks(): Block
    {
        foreach (['hero' => ['heading' => ['type' => 'text', 'name' => 'Heading']], 'quote' => ['text' => ['type' => 'text', 'name' => 'Text']]] as $slug => $schema) {
            Block::query()->create(['external_id' => (string) Str::uuid(), 'name' => ucfirst($slug), 'slug' => $slug, 'type' => 'nestable', 'schema' => $schema]);
        }

        return Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Landing',
            'slug' => 'landing',
            'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title'],
                'body' => ['type' => 'blocks', 'name' => 'Body', 'restrict_blocks' => true, 'block_whitelist' => ['hero']],
            ],
        ]);
    }

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
