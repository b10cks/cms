<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\PublishContent;
use App\Actions\Content\UpdateContent;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\Changesets\ContentFlags;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;
use Tests\Traits\WritesGlossaryRows;

/** Flagged wording shows where people publish, until it leaves the draft or the draft is published. */
class AiContentFlagsTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WritesGlossaryRows;

    private Space $space;

    private FakeAiDriver $driver;

    private Block $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => ['default_language' => 'de', 'languages' => []]]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        Sanctum::actingAs($this->user);

        $glossary = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Shop', 'enabled' => true]);
        $this->glossaryRow($glossary, ['de' => ['use' => 'Matratze', 'avoid' => ['Schlafunterlage']]]);
        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
                'summary' => ['type' => 'text', 'name' => 'Summary', 'translatable' => true],
            ],
        ]);
    }

    #[Test]
    public function flagged_wording_is_counted_for_the_tree_and_named_at_publish(): void
    {
        $entry = $this->flaggedEntry();

        $this->getJson($this->url('ai/content-flags'))->assertOk()->assertJsonPath("data.{$entry->id}", ['conflicts' => 0, 'flagged' => 1]);
        $this->getJson($this->url('ai/content-flags/details?ids[]='.$entry->id))
            ->assertOk()
            ->assertJsonPath('data.0.content_id', $entry->id)
            ->assertJsonPath('data.0.flags.0.kind', 'flagged')
            ->assertJsonPath('data.0.flags.0.field', 'Title')
            ->assertJsonPath('data.0.flags.0.wording', 'Schlafunterlage')
            ->assertJsonPath('data.0.flags.0.replacement', 'Matratze');
        $this->getJson($this->url('ai/threads'))->assertJsonPath('meta.attention', 1)->assertJsonPath('data.0.status', 'needs_review');
    }

    #[Test]
    public function an_edit_that_removes_the_wording_clears_the_flag_without_any_action(): void
    {
        $entry = $this->flaggedEntry();

        // A save that leaves the wording changes nothing.
        $this->save($entry, ['title' => 'Eine neue Schlafunterlage', 'summary' => 'Anders']);
        $this->getJson($this->url('ai/content-flags'))->assertJsonPath("data.{$entry->id}.flagged", 1);

        $this->save($entry, ['title' => 'Die beste Matratze', 'summary' => 'Anders']);

        $this->getJson($this->url('ai/content-flags'))->assertJsonMissingPath("data.{$entry->id}");
        $this->getJson($this->url('ai/content-flags/details?ids[]='.$entry->id))->assertJsonCount(0, 'data');
        $this->getJson($this->url('ai/threads'))->assertJsonPath('meta.attention', 0);
        $operation = AiOperation::query()->sole();
        $this->getJson($this->url("ai/operations/{$operation->id}/changes"))
            ->assertJsonPath('summary.flagged', 0)
            ->assertJsonPath('data.0.flagged_wording', []);
    }

    #[Test]
    public function publishing_the_draft_settles_its_flags_and_the_receipt_shows_the_wording_until_then(): void
    {
        $entry = $this->flaggedEntry();
        $operation = AiOperation::query()->sole();
        $this->getJson($this->url("ai/operations/{$operation->id}/changes"))->assertJsonPath('data.0.flagged_wording', ['Schlafunterlage']);

        // Publishing goes through with the wording in place: nothing blocks it.
        app(PublishContent::class)->execute([], $entry->fresh(), $this->space, $this->user);

        $this->assertSame('Die beste Schlafunterlage', $entry->fresh()->getCurrentContent()['title']);
        $this->assertSame(0, AiChangesetItem::query()->sole()->open_findings);
        $this->getJson($this->url('ai/content-flags'))->assertJsonMissingPath("data.{$entry->id}");
        $this->getJson($this->url('ai/threads'))->assertJsonPath('meta.attention', 0);
    }

    #[Test]
    public function a_later_ai_write_of_the_field_replaces_the_earlier_flag(): void
    {
        $entry = $this->flaggedEntry();
        $this->driver->push(
            $this->update($entry->fresh(), 'Noch eine Schlafunterlage'),
            FakeAiDriver::text('Done.'),
        );
        $this->approve([$entry->id]);

        $this->getJson($this->url('ai/content-flags'))->assertJsonPath("data.{$entry->id}.flagged", 1);
        $this->assertSame(2, AiChangesetItem::query()->count());
    }

    #[Test]
    public function the_figures_cost_the_same_two_queries_however_many_entries_are_flagged(): void
    {
        $this->flaggedEntry();
        $count = function () {
            DB::connection((new AiChangesetItem)->getConnectionName())->flushQueryLog();
            DB::connection((new AiChangesetItem)->getConnectionName())->enableQueryLog();
            app(ContentFlags::class)->counts();

            return \count(DB::connection((new AiChangesetItem)->getConnectionName())->getQueryLog());
        };

        $this->assertSame(2, $count());
        $this->flaggedEntry();
        $this->flaggedEntry();
        $this->assertSame(2, $count());
    }

    private function flaggedEntry(): Content
    {
        $entry = $this->entry(['title' => 'Alt', 'summary' => 'Kurz']);
        $this->driver->push($this->update($entry, 'Die beste Schlafunterlage'), FakeAiDriver::text('Done.'));
        $this->approve([$entry->id]);

        return $entry->fresh();
    }

    /** @param list<string> $ids */
    private function approve(array $ids): void
    {
        $id = $this->postJson($this->url('ai/operations'), ['kind' => 'general', 'prompt' => 'Improve.', 'scope' => ['type' => 'content', 'ids' => $ids]])->assertCreated()->json('data.id');
        $operation = AiOperation::query()->findOrFail($id);
        $hash = $this->patchJson($this->url("ai/operations/{$id}"), ['plan' => ['summary' => 'Improve each title.']])->assertOk()->json('data.plan_hash');
        $this->postJson($this->url("ai/operations/{$id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $operation->refresh()->counts['scope_hash']])->assertOk();
    }

    private function update(Content $entry, string $title): TurnResult
    {
        return new TurnResult('', [new ToolCall('call_'.Str::random(6), 'propose_update', [
            'target_type' => 'content', 'id' => $entry->id, 'version_id' => $entry->current_version_id,
            'changes' => [['path' => 'title', 'value' => $title]],
        ])], new TurnUsage(10, 5));
    }

    /** @param array<string, string> $document */
    private function save(Content $entry, array $document): void
    {
        app(UpdateContent::class)->execute(['content' => $document, 'force' => true], $entry->fresh(), $this->space, $this->user);
    }

    /** @param array<string, string> $values */
    private function entry(array $values): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id, 'name' => 'Entry '.Str::random(4), 'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)), 'language_iso' => 'de',
        ]);
        $content->id = strtolower((string) Str::ulid());
        $content->current_version_id = ContentVersion::query()->forceCreate(['content_id' => $content->id, 'content' => $values, 'created_by_id' => $this->user->id])->id;
        $content->save();

        return $content->fresh();
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }
}
