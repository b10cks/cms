<?php

namespace Tests\Feature\Mcp;

use App\Enums\AiChangesetItemStatus;
use App\Enums\AiOperationPauseReason;
use App\Enums\AiOperationState;
use App\Mcp\Servers\ManagementServer;
use App\Mcp\Support\OperationRegistry;
use App\Mcp\Tools\MgmtCallTool;
use App\Models\Management\AiGlossary;
use App\Models\Management\AssetClassificationRun;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Services\Ai\Knowledge\GlossaryChecker;
use App\Services\Ai\Operations\OperationPlan;
use App\Services\Ai\Operations\OperationQuestions;
use App\Services\Ai\Operations\Tools\ReportItemsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;
use Tests\Traits\WritesGlossaryRows;

/**
 * The content operations lifecycle through b10cks_mgmt_call: each call passes the
 * real routes, FormRequests, policies and controllers on a sub-request.
 */
class AiContentOperationsMcpTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;
    use WritesGlossaryRows;

    private Space $space;

    private AiGlossary $glossary;

    private Block $page;

    private FakeAiDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->space = Space::factory()->create(['settings' => [
            'default_language' => 'en',
            'i18n_mode' => 'overlay',
            'languages' => [['code' => 'de', 'name' => 'German', 'fallback_language' => null]],
        ]]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();

        $this->glossary = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Shop', 'enabled' => true]);
        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
                'seo' => ['type' => 'meta', 'name' => 'SEO', 'translatable' => true],
            ],
        ]);
    }

    #[Test]
    public function concept_rows_read_write_and_check_through_the_registry(): void
    {
        $rowId = $this->glossaryRow($this->glossary, ['de' => 'Altbett']);

        $this->mcp('ai.glossaries.conceptRows', [...$this->glossaryArgs(), 'params' => ['search' => 'altbett']])->assertOk()->assertSee($rowId);
        $this->mcp('ai.glossaries.updateConceptRow', [...$this->glossaryArgs(), 'rowId' => $rowId, 'payload' => ['note' => 'Beds']])->assertOk()->assertSee('Beds');
        $this->mcp('ai.glossaries.createConceptRow', [...$this->glossaryArgs(), 'payload' => ['languages' => ['de' => ['use' => 'ALTBETT']]]])
            ->assertHasErrors(['wording_conflict']);
        $this->mcp('ai.glossaries.checkText', [...$this->glossaryArgs(), 'payload' => ['text' => 'Ein Altbett', 'source_locale' => 'de']])->assertOk()->assertSee('"start": 4');
        $this->mcp('ai.glossaries.bulkConceptRows', [...$this->glossaryArgs(), 'payload' => ['row_ids' => [$rowId], 'action' => 'delete']])->assertOk();
        $this->assertSame(0, $this->glossary->concepts()->count());
    }

    #[Test]
    public function identifiers_stay_separate_and_another_owners_rows_fail_as_over_http(): void
    {
        $rowId = $this->glossaryRow($this->glossary, ['de' => 'Bett']);
        $rowArgs = [...$this->glossaryArgs(), 'rowId' => $rowId, 'payload' => ['note' => 'Beds']];

        // `id` never stands in for the glossary or the row.
        $this->mcp('ai.glossaries.updateConceptRow', ['spaceId' => $this->space->id, 'id' => $this->glossary->id, 'rowId' => $rowId, 'payload' => ['category' => 'Beds']])
            ->assertHasErrors(['Missing required string argument: glossaryId']);
        $this->mcp('ai.glossaries.updateConceptRow', [...$this->glossaryArgs(), 'id' => $rowId, 'payload' => ['category' => 'Beds']])
            ->assertHasErrors(['Missing required string argument: rowId']);

        // A team's glossary is not reachable under a space, and a space's row not under a glossary of a team.
        $team = Team::factory()->create();
        $this->assignTeamRole($team, $this->user, 'owner');
        $foreign = AiGlossary::create([...AiGlossary::ownerAttributes($team), 'name' => 'Team']);
        $other = AiGlossary::create([...AiGlossary::ownerAttributes($this->space), 'name' => 'Other']);

        $this->mcp('ai.glossaries.updateConceptRow', [...$rowArgs, 'glossaryId' => $foreign->id])->assertHasErrors(['"statusCode": 404']);
        $this->mcp('ai.glossaries.updateConceptRow', [...$rowArgs, 'glossaryId' => $other->id])->assertHasErrors(['"statusCode": 404']);
        $this->mcp('ai.teamGlossaries.conceptRows', ['teamId' => $team->id, 'glossaryId' => $foreign->id])->assertOk();
        $this->mcp('ai.teamGlossaries.updateConceptRow', ['teamId' => $team->id, 'glossaryId' => $foreign->id, 'rowId' => $rowId, 'payload' => ['category' => 'Beds']])
            ->assertHasErrors(['"statusCode": 404']);

        $outsider = User::factory()->create();
        ManagementServer::actingAs($outsider)->tool(MgmtCallTool::class, ['operation' => 'ai.glossaries.updateConceptRow', ...$rowArgs])
            ->assertHasErrors(['Management API request failed']);
    }

    #[Test]
    public function the_lifecycle_needs_explicit_approval_and_budget_and_an_answer_raises_neither(): void
    {
        $entry = $this->entry('en', ['title' => 'Alt']);
        $this->driver->push(
            FakeAiDriver::toolCall(ReportItemsTool::NAME, ['items' => [['id' => $entry->id, 'outputs' => [
                ['part' => 'en.seo.title', 'outcome' => 'blocked', 'reason' => 'Not shown.', 'question' => 'Which model is it?'],
            ]]]]),
            FakeAiDriver::text('Blocked.'),
        );

        $this->mcp('ai.operations.create', ['spaceId' => $this->space->id, 'payload' => [
            'kind' => 'general', 'prompt' => 'Write metadata.', 'scope' => ['type' => 'content', 'ids' => [$entry->id]],
        ]])->assertOk();
        $operation = AiOperation::query()->sole();
        $args = ['spaceId' => $this->space->id, 'id' => $operation->id];
        $this->assertSame(0, $this->driver->calls);

        $this->mcp('ai.operations.updatePlan', [...$args, 'payload' => ['plan' => ['summary' => 'Fill titles.', 'metadata' => ['keys' => ['title'], 'overwrite_keys' => []]]]])
            ->assertOk()->assertSee('"overwrite_keys": []');
        $planHash = OperationPlan::hash($operation->refresh()->plan);

        $this->mcp('ai.operations.approve', [...$args, 'payload' => ['plan_hash' => $planHash, 'scope_hash' => $operation->counts['scope_hash']]])
            ->assertHasErrors(['max usd']);
        $this->mcp('ai.operations.approve', [...$args, 'payload' => ['plan_hash' => str_repeat('0', 64), 'scope_hash' => $operation->counts['scope_hash'], 'max_usd' => 1]])
            ->assertHasErrors(['plan_changed']);
        $this->assertSame(AiOperationState::Draft, $operation->refresh()->state);

        $this->mcp('ai.operations.approve', [...$args, 'payload' => ['plan_hash' => $planHash, 'scope_hash' => $operation->counts['scope_hash'], 'max_usd' => 1]])
            ->assertOk()->assertSee(['policy_snapshot_id', 'coverage']);
        $operation->refresh();
        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->assertNotNull($operation->policy_snapshot_id);

        $this->mcp('ai.operations.items', $args)->assertOk()->assertSee(['expected_parts', 'en.seo.title', '"outcome": "blocked"']);

        $this->mcp('ai.operations.pause', $args)->assertHasErrors(['Only a running operation can pause.']);
        $this->mcp('ai.operations.resume', $args)->assertHasErrors(['Only a paused operation can resume.']);
        $this->mcp('ai.operations.extendBudget', [...$args, 'payload' => ['max_usd' => 2]])->assertHasErrors(['"reason": "state"']);

        $group = app(OperationQuestions::class)->overview($operation)['groups'][0];
        $this->mcp('ai.operations.questions', $args)->assertOk()->assertSee([$group['id'], $group['fingerprint'], '"accepting": true']);
        $this->mcp('ai.operations.questionMembers', [...$args, 'questionId' => $group['id']])->assertOk()->assertSee('en.seo.title');

        $this->driver->push(FakeAiDriver::text('Answered.'));
        $answer = ['answers' => [['question_id' => $group['id'], 'answer' => 'The Wolke 5.', 'fingerprint' => $group['fingerprint']]]];
        $this->mcp('ai.operations.answerQuestions', [...$args, 'payload' => $answer])->assertOk()->assertSee('"status": "recorded"');
        $this->assertEquals(1, $operation->refresh()->max_usd);
        $this->assertSame('The Wolke 5.', AiOperationItem::query()->where('operation_id', $operation->id)->sole()->detail['answers']['en.seo.title'][0]['answer']);

        $this->mcp('ai.operations.answerQuestions', [...$args, 'payload' => $answer])->assertOk()->assertSee('"status": "duplicate"');
        $this->mcp('ai.operations.answeredQuestions', $args)->assertOk()->assertSee('The Wolke 5.');

        // A spent budget stays spent until the one call that raises it.
        $operation->refresh()->forceFill(['state' => AiOperationState::Paused, 'pause_reason' => AiOperationPauseReason::Budget, 'spent_usd' => 1, 'finished_at' => null])->save();
        $this->mcp('ai.operations.resume', $args)->assertHasErrors(['"reason": "budget"']);
        $this->mcp('ai.operations.extendBudget', [...$args, 'payload' => ['max_usd' => 0.5]])->assertHasErrors(['max_usd']);
        $this->assertEquals(1, $operation->refresh()->max_usd);

        $this->driver->push(FakeAiDriver::text('Resumed.'));
        $this->mcp('ai.operations.extendBudget', [...$args, 'payload' => ['max_usd' => 2]])->assertOk();
        $this->assertEquals(2, $operation->refresh()->max_usd);
    }

    #[Test]
    public function content_goes_to_a_draft_and_its_changes_read_through_mcp(): void
    {
        $this->glossaryRow($this->glossary, ['en' => ['use' => 'Mattress', 'avoid' => ['Sleeping pad']]]);
        $entry = $this->entry('en', ['title' => 'Old']);
        $this->driver->push(
            FakeAiDriver::toolCall('propose_update', ['target_type' => 'content', 'id' => $entry->id, 'version_id' => $entry->current_version_id, 'changes' => [['path' => 'title', 'value' => 'The best Sleeping pad']]]),
            FakeAiDriver::text('Done.'),
        );
        $operation = $this->approve(['kind' => 'general', 'prompt' => 'Rewrite.', 'scope' => ['type' => 'content', 'ids' => [$entry->id]]], ['summary' => 'Rewrite titles.']);

        $item = AiChangesetItem::query()->sole();
        $this->assertSame(AiChangesetItemStatus::Accepted, $item->status);
        $args = ['spaceId' => $this->space->id, 'id' => $operation->id];
        $this->mcp('ai.operations.changes', $args)->assertOk()->assertSee(['"changed": 1', '"flagged": 1', $entry->id]);
        $this->mcp('ai.operations.reviewItems', [...$args, 'params' => ['target_id' => $entry->id]])->assertOk()->assertSee(['glossary_findings', 'Sleeping pad']);
        $this->assertNull($entry->refresh()->published_at);
        $this->assertNull($entry->published_version_id);
    }

    #[Test]
    public function an_override_reason_reaches_the_accept_of_a_proposal(): void
    {
        $entry = DataEntry::factory()->create(['data_source_id' => DataSource::factory()->create()->id, 'key' => 'bed', 'value' => 'Old']);
        $changeset = AiChangeset::query()->create(['space_id' => $this->space->id, 'actor_id' => $this->user->id, 'title' => 'Run']);
        $item = $changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_UPDATE, 'target_type' => AiChangesetItem::TARGET_DATA_ENTRY, 'target_id' => $entry->id, 'target_label' => $entry->key,
            'path' => 'value', 'field_type' => 'text', 'before' => 'Old', 'after' => 'The best Sleeping pad', 'status' => AiChangesetItemStatus::Pending,
            'glossary_findings' => [['type' => 'forbidden_variant', 'term' => 'Mattress', 'found' => 'Sleeping pad', 'expected' => 'Mattress']],
        ]);
        $findingId = GlossaryChecker::findingId($item->glossary_findings[0]);
        $args = ['spaceId' => $this->space->id, 'id' => $item->changeset_id];

        $this->mcp('ai.changesets.get', $args)->assertOk()->assertSee(['glossary_findings', $findingId, 'review']);
        $this->mcp('ai.changesets.accept', [...$args, 'payload' => ['item_ids' => [$item->id]]])
            ->assertHasErrors(["overrides.{$item->id}.{$findingId}"]);
        $this->mcp('ai.changesets.accept', [...$args, 'payload' => ['item_ids' => [$item->id], 'overrides' => [$item->id => [['finding_id' => $findingId, 'reason' => '   ']]]]])
            ->assertHasErrors(['overrides']);

        $this->mcp('ai.changesets.accept', [...$args, 'payload' => ['item_ids' => [$item->id], 'overrides' => [$item->id => [['finding_id' => $findingId, 'reason' => 'Supplier wording.']]]]])
            ->assertOk()->assertSee('"accepted": 1');

        $item->refresh();
        $this->assertSame(AiChangesetItemStatus::Accepted, $item->status);
        $this->assertSame('Supplier wording.', $item->review['overrides'][0]['reason']);
    }

    #[Test]
    public function a_classification_report_reads_through_its_query_parameter(): void
    {
        $run = AssetClassificationRun::query()->create(['space_id' => $this->space->id, 'queued' => 0, 'enqueuing' => false]);

        $this->mcp('ai.assets.classifyStatus', ['runId' => $run->id, 'params' => ['spaceId' => $this->space->id]])
            ->assertOk()->assertSee(['field_report', '"truncated": false']);
        $this->mcp('ai.assets.classifyStatus', ['runId' => $run->id, 'params' => ['spaceId' => (string) Str::ulid()]])
            ->assertHasErrors(['"statusCode": 404']);
        // A write reads the same query parameter, so a missing asset list is a validation error of that space.
        $this->mcp('ai.assets.classify', ['params' => ['spaceId' => $this->space->id], 'payload' => ['scope' => 'selection']])
            ->assertHasErrors(['asset ids']);
    }

    #[Test]
    public function every_lifecycle_operation_is_listed_with_its_paid_or_free_nature(): void
    {
        $operations = OperationRegistry::all();

        foreach (['ai.operations.startPlanning', 'ai.operations.approve', 'ai.operations.resume', 'ai.operations.extendBudget', 'ai.operations.answerQuestions', 'ai.assets.classify'] as $paid) {
            $this->assertStringContainsString('PAID', $operations[$paid]['description'], $paid);
        }

        foreach (['ai.operations.create', 'ai.operations.updatePlan'] as $free) {
            $this->assertStringNotContainsString('PAID', $operations[$free]['description'], $free);
            $this->assertStringContainsStringIgnoringCase('no provider call', $operations[$free]['description'], $free);
        }
    }

    /** @param  array<string, mixed>  $operation */
    private function approve(array $operation, array $plan): AiOperation
    {
        $args = ['spaceId' => $this->space->id];
        $this->mcp('ai.operations.create', [...$args, 'payload' => $operation])->assertOk();
        $model = AiOperation::query()->latest('id')->firstOrFail();
        $args['id'] = $model->id;
        $this->mcp('ai.operations.updatePlan', [...$args, 'payload' => ['plan' => $plan]])->assertOk();
        $model->refresh();
        $this->mcp('ai.operations.approve', [...$args, 'payload' => ['plan_hash' => OperationPlan::hash($model->plan), 'scope_hash' => $model->counts['scope_hash'], 'max_usd' => 1]])->assertOk();

        return $model->refresh();
    }

    /** @return array{spaceId: string, glossaryId: string} */
    private function glossaryArgs(): array
    {
        return ['spaceId' => $this->space->id, 'glossaryId' => $this->glossary->id];
    }

    /** @param  array<string, mixed>  $arguments */
    private function mcp(string $operation, array $arguments): TestResponse
    {
        return $this->tool(['operation' => $operation, ...$arguments]);
    }

    /** @param  array<string, mixed>  $arguments */
    private function tool(array $arguments): TestResponse
    {
        return ManagementServer::actingAs($this->user)->tool(MgmtCallTool::class, $arguments);
    }

    /** @param  array<string, mixed>  $values */
    private function entry(string $language, array $values, ?Content $source = null): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id,
            'name' => $values['title'] ?? 'Untitled',
            'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)),
            'language_iso' => $language,
            'i18n_parent_id' => $source?->id,
        ]);
        $content->id = strtolower((string) Str::ulid());
        $content->current_version_id = ContentVersion::query()->forceCreate(['content_id' => $content->id, 'content' => $values, 'created_by_id' => $this->user->id])->id;
        $content->save();

        return $content->fresh();
    }
}
