<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\DecidableItems;
use App\Enums\AiChangesetItemStatus;
use App\Enums\AiOperationState;
use App\Models\Management\AiGlossary;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Run\TurnResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;
use Tests\Traits\WritesGlossaryRows;

class AiAcceptanceReviewTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WritesGlossaryRows;

    private Space $space;

    private FakeAiDriver $driver;

    private AiGlossary $glossary;

    private Block $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => [
            'default_language' => 'de',
            'i18n_mode' => 'overlay',
            'languages' => [['code' => 'en', 'name' => 'English', 'fallback_language' => null]],
        ]]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        Sanctum::actingAs($this->user);

        $this->glossary = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Shop', 'enabled' => true]);
        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
                'summary' => ['type' => 'text', 'name' => 'Summary', 'translatable' => true],
            ],
        ]);
    }

    #[Test]
    public function a_finding_on_content_is_stored_on_the_written_item_and_blocks_nothing(): void
    {
        $this->glossaryRow($this->glossary, ['de' => ['use' => 'Matratze', 'avoid' => ['Polsterauflage']]]);
        $entry = $this->entry(['title' => 'Alt']);
        $this->driver->push($this->propose($entry, 'Die beste Polsterauflage.'), FakeAiDriver::text('Done.'));
        $operation = $this->approve();

        $item = $this->itemFor($entry);
        $this->assertSame(AiChangesetItemStatus::Accepted, $item->status);
        $this->assertSame('forbidden_variant', $item->glossary_findings[0]['type']);
        $this->assertSame('Die beste Polsterauflage.', $entry->fresh()->getCurrentContent()['title']);
        $this->assertArrayNotHasKey('overrides', $item->review);
        $this->assertSame(AiOperationState::Completed, $operation->state);

        $this->getJson($this->url("ai/operations/{$operation->id}/changes"))
            ->assertJsonPath('summary', ['changed' => 1, 'conflicts' => 0, 'flagged' => 1])
            ->assertJsonPath('data.0.flagged', 1);
        $this->getJson($this->url("ai/operations/{$operation->id}/review/items?target_id={$entry->id}"))->assertJsonPath('data.0.glossary_findings.0.term', 'Matratze');
    }

    #[Test]
    public function a_conflict_on_content_is_resolved_without_a_reason_for_its_finding(): void
    {
        $this->glossaryRow($this->glossary, ['de' => ['use' => 'Matratze', 'avoid' => ['Polsterauflage']]]);
        $entry = $this->entry(['title' => 'Alt']);
        $read = $entry->current_version_id;
        $this->driver->push(function () use ($entry, $read) {
            $this->entryVersion($entry, ['title' => 'Inzwischen anders']);

            return $this->propose($entry->fresh()->setAttribute('current_version_id', $read), 'Die beste Polsterauflage.');
        }, FakeAiDriver::text('Done.'));
        $this->approve();
        $item = $this->itemFor($entry);
        $this->assertSame(AiChangesetItemStatus::Conflict, $item->status);

        $this->accept($item->changeset, ['item_ids' => [$item->id], 'overwrite' => true])->assertOk()->assertJsonPath('accepted', 1);
        $this->assertSame('Die beste Polsterauflage.', $entry->fresh()->getCurrentContent()['title']);
    }

    #[Test]
    public function a_deterministic_finding_on_a_proposal_needs_a_reason_recorded_with_the_decision(): void
    {
        $entry = DataEntry::factory()->create(['data_source_id' => DataSource::factory()->create()->id, 'key' => 'yes', 'value' => 'Alt']);
        $item = $this->proposal($entry, 'Die beste Polsterauflage.', [['type' => 'forbidden_variant', 'term' => 'Matratze', 'found' => 'Polsterauflage', 'expected' => 'Matratze']]);
        $findingId = GlossaryChecker::findingId($item->glossary_findings[0]);
        $this->assertSame($findingId, $this->getJson($this->url("ai/changesets/{$item->changeset_id}"))->json('data.items.0.glossary_findings.0.id'));

        $this->accept($item->changeset, ['item_ids' => [$item->id]])->assertUnprocessable()->assertJsonValidationErrors(["overrides.{$item->id}.{$findingId}"]);
        $this->accept($item->changeset, ['item_ids' => [$item->id], 'overrides' => [$item->id => [['finding_id' => $findingId, 'reason' => '   ']]]])->assertUnprocessable();
        $this->assertSame(AiChangesetItemStatus::Pending, $item->refresh()->status);
        $this->assertSame('Alt', $entry->fresh()->value);

        // A decision that does not apply records no override either.
        $overrides = [$item->id => [['finding_id' => $findingId, 'reason' => 'Wording of the supplier contract.']]];
        $entry->forceFill(['value' => 'Inzwischen anders'])->save();
        $this->accept($item->changeset, ['item_ids' => [$item->id], 'overrides' => $overrides])->assertOk()->assertJsonPath('conflicts', 1);
        $this->assertArrayNotHasKey('overrides', $item->refresh()->review ?? []);

        $this->accept($item->changeset, ['item_ids' => [$item->id], 'overwrite' => true, 'overrides' => $overrides])->assertOk()->assertJsonPath('accepted', 1);
        $record = $item->refresh()->review['overrides'][0];
        $this->assertSame([$findingId, 'Wording of the supplier contract.', $this->user->id], [$record['finding_id'], $record['reason'], $record['reviewer_id']]);
        $this->assertSame($item->glossary_findings[0], $record['finding']);
        $this->assertNotNull($record['overridden_at']);
    }

    #[Test]
    public function a_hint_asks_no_reason_records_none_and_does_not_stop_a_bulk_accept(): void
    {
        $entry = DataEntry::factory()->create(['data_source_id' => DataSource::factory()->create()->id, 'key' => 'yes', 'value' => 'Alt']);
        // A stored finding from before the rule, read by its type.
        $item = $this->proposal($entry, 'Neu', [['type' => 'missing_term', 'term' => 'Partner', 'expected' => 'specialist partner']]);

        $this->assertFalse(DecidableItems::needsIndividualLook($item));
        $this->accept($item->changeset, ['bulk' => true])->assertOk()->assertJsonPath('accepted', 1);

        $this->assertSame(AiChangesetItemStatus::Accepted, $item->refresh()->status);
        $this->assertArrayNotHasKey('overrides', $item->review ?? []);
    }

    #[Test]
    public function an_edited_proposal_is_checked_under_the_frozen_policy_and_applies_on_the_first_accept(): void
    {
        $row = $this->glossaryRow($this->glossary, ['de' => ['use' => 'Matratze', 'avoid' => ['Polsterauflage']]]);
        $page = $this->entry(['title' => 'Alt']);
        $this->driver->push($this->propose($page, 'Die Matratze.'), FakeAiDriver::text('Done.'));
        $this->approve();
        $written = $this->itemFor($page);
        $this->assertSame(['source' => 'de', 'target' => 'de'], $written->review['locales']);
        $entry = DataEntry::factory()->create(['data_source_id' => DataSource::factory()->create()->id, 'key' => 'yes', 'value' => 'Die Matratze.']);
        $item = $this->proposal($entry, 'Die neue Matratze.', [], $written->review);

        // Knowledge changes after approval. The edit stays under what was approved.
        $this->changeGlossaryRow($this->glossary, $row, ['de' => ['avoid' => ['Polsterfläche']]]);

        $response = $this->accept($item->changeset, ['item_ids' => [$item->id], 'values' => [$item->id => 'Die Polsterauflage.']])->assertUnprocessable();
        $this->assertStringContainsString("overrides.{$item->id}.", (string) array_key_first($response->json('errors')));
        $this->assertSame(AiChangesetItemStatus::Pending, $item->refresh()->status);

        $edit = 'Die Polsterfläche.';
        $this->accept($item->changeset, ['item_ids' => [$item->id], 'values' => [$item->id => $edit]])->assertOk()->assertJsonPath('accepted', 1);
        $this->assertSame($edit, $item->refresh()->final);
        $this->assertSame($edit, $entry->fresh()->value);
    }

    /**
     * @param  list<array<string, string>>  $findings
     * @param  array<string, mixed>|null  $review
     */
    private function proposal(DataEntry $entry, string $value, array $findings, ?array $review = null): AiChangesetItem
    {
        $changeset = AiChangeset::query()->create(['space_id' => $this->space->id, 'actor_id' => $this->user->id, 'title' => 'Run']);

        return $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_UPDATE, 'target_type' => AiChangesetItem::TARGET_DATA_ENTRY, 'target_id' => $entry->id, 'target_label' => $entry->key,
            'path' => 'value', 'field_type' => 'text', 'before' => $entry->value, 'after' => $value, 'status' => AiChangesetItemStatus::Pending,
            'glossary_findings' => $findings ?: null, 'review' => $review,
        ]);
    }

    private function approve(): AiOperation
    {
        $id = $this->postJson($this->url('ai/operations'), ['kind' => 'general', 'prompt' => 'Rewrite.', 'scope' => ['type' => 'content', 'filters' => ['block' => 'page']]])->assertCreated()->json('data.id');
        $operation = AiOperation::query()->findOrFail($id);
        $hash = $this->patchJson($this->url("ai/operations/{$id}"), ['plan' => ['summary' => 'Rewrite each title.']])->assertOk()->json('data.plan_hash');
        $this->postJson($this->url("ai/operations/{$id}/approve"), ['plan_hash' => $hash, 'max_usd' => 1, 'scope_hash' => $operation->refresh()->counts['scope_hash']])->assertOk();

        return $operation->refresh();
    }

    private function propose(Content $entry, string $title): TurnResult
    {
        return FakeAiDriver::toolCall('propose_update', [
            'target_type' => 'content', 'id' => $entry->id, 'version_id' => $entry->current_version_id,
            'changes' => [['path' => 'title', 'value' => $title]],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function accept(AiChangeset $changeset, array $payload): TestResponse
    {
        return $this->postJson($this->url("ai/changesets/{$changeset->id}/accept"), $payload);
    }

    private function itemFor(Content $entry): AiChangesetItem
    {
        return AiChangesetItem::query()->where('target_id', $entry->id)->sole();
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }

    /** @param array<string, mixed> $values */
    private function entry(array $values, string $language = 'de'): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => 'Entry '.Str::random(4),
            'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => $language,
        ]);
        $content->id = strtolower((string) Str::ulid());
        $this->entryVersion($content, $values);

        return $content->fresh();
    }

    /** @param array<string, mixed> $values */
    private function entryVersion(Content $content, array $values): void
    {
        $content->current_version_id = ContentVersion::query()->forceCreate(['content_id' => $content->id, 'content' => $values, 'created_by_id' => $this->user->id])->id;
        $content->save();
    }
}
