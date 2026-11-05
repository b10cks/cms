<?php

namespace Tests\Feature\Ai;

use App\Actions\Content\CreateContent;
use App\Actions\Content\UpdateContent;
use App\Enums\AiChangesetItemStatus;
use App\Enums\AiOperationItemStatus;
use App\Enums\AiOperationPauseReason;
use App\Enums\AiOperationState;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiOperation;
use App\Models\Space\AiOperationItem;
use App\Models\Space\AiRun;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\Space\DataEntry;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\ToolError;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use App\Services\Ai\Tools\GetTranslationTaskTool;
use App\Services\Ai\Tools\ProposeTranslationTool;
use App\Services\Ai\Tools\ProposeUpdateTool;
use App\Services\Auth\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

/** An approved agent writes content straight to drafts, and nothing waits for a person. */
class AiDirectWritesTest extends TestCase
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
        $this->space = Space::factory()->create(['settings' => [
            'default_language' => 'en',
            'i18n_mode' => 'standalone',
            'languages' => [['code' => 'de', 'name' => 'Deutsch', 'fallback_language' => null]],
        ]]);
        $this->assignSpaceRole($this->space, $this->user, 'owner');
        $this->setUpSpaceTesting($this->space);
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);
        config(['ai.operations.batch_size' => 2]);

        $this->page = Block::query()->create([
            'external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title', 'translatable' => true],
                'summary' => ['type' => 'text', 'name' => 'Summary', 'translatable' => true],
            ],
        ]);
    }

    #[Test]
    public function an_update_lands_in_a_draft_attributed_to_the_actor_and_marked_as_ai(): void
    {
        $page = $this->entry(['title' => 'Hello', 'summary' => 'World']);
        $this->driver->push($this->update($page, ['summary' => 'AI summary']), FakeAiDriver::text('Done.'));

        $operation = $this->approve([$page->id]);

        $page = $page->fresh();
        $this->assertSame('AI summary', $page->getCurrentContent()['summary']);
        $this->assertNull($page->published_version_id);

        $version = ContentVersion::query()->findOrFail($page->current_version_id);
        $this->assertSame($this->user->id, $version->created_by_id);
        $this->assertStringStartsWith('AI: ', (string) $version->message);

        $item = AiChangesetItem::query()->sole();
        $this->assertSame(AiChangesetItemStatus::Accepted, $item->status);
        $this->assertSame([$this->user->id, 'World', 'AI summary'], [$item->decided_by_id, $item->before, $item->after]);
        $this->assertSame(AiOperationItemStatus::Written, AiOperationItem::query()->sole()->status);
        $this->assertSame(AiOperationState::Completed, $operation->state);
    }

    #[Test]
    public function an_operation_finishes_on_its_own_with_no_person_involved(): void
    {
        $pages = [$this->entry(['title' => 'One']), $this->entry(['title' => 'Two']), $this->entry(['title' => 'Three'])];
        $this->driver->push(
            $this->update($pages[0], ['summary' => 'S1']),
            $this->update($pages[1], ['summary' => 'S2']),
            FakeAiDriver::text('Batch one.'),
            $this->update($pages[2], ['summary' => 'S3']),
            FakeAiDriver::text('Batch two.'),
        );

        $operation = $this->approve(array_map(fn (Content $page) => $page->id, $pages));

        $this->assertSame(AiOperationState::Completed, $operation->state);
        $this->assertSame(3, $operation->counts['coverage']['entries']['written']);
        $this->assertSame(0, AiChangesetItem::query()->where('status', AiChangesetItemStatus::Pending->value)->count());

        $this->getJson($this->url("ai/operations/{$operation->id}/changes"))
            ->assertOk()
            ->assertJsonPath('summary', ['changed' => 3, 'conflicts' => 0, 'flagged' => 0])
            ->assertJsonPath('meta.total', 3);
        // Nothing in the thread waits for anyone.
        $this->getJson($this->url('ai/threads'))->assertJsonPath('meta.attention', 0);
    }

    #[Test]
    public function a_new_locale_entry_is_created_in_its_family_and_a_later_call_fills_the_rest(): void
    {
        $source = $this->entry(['title' => 'Cart', 'summary' => 'Pay securely']);
        $run = $this->startRun();

        $first = $this->translation($run)->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id,
            'name' => 'Warenkorb', 'units' => ['title' => 'Warenkorb'],
        ]);

        $row = Content::query()->where('i18n_parent_id', $source->id)->where('language_iso', 'de')->sole();
        $this->assertSame([1, $row->id, ['summary']], [$first['written'], $first['destination_id'], $first['remaining_units']]);
        $this->assertSame('Warenkorb', $row->getCurrentContent()['title']);
        $this->assertNull($row->published_version_id);
        $version = ContentVersion::query()->findOrFail($row->current_version_id);
        $this->assertSame($this->user->id, $version->created_by_id);
        $this->assertStringStartsWith('AI: ', (string) $version->message);

        $create = AiChangesetItem::query()->where('operation', 'create')->sole();
        $this->assertSame([AiChangesetItemStatus::Accepted, $row->id], [$create->status, $create->target_id]);

        // Even under a plan for missing entries only: the row is this task's, and its empty fields still are the missing work.
        $run->forceFill(['setup' => [...$run->setup, 'translation' => ['locales' => ['de'], 'include' => ['missing']]]])->save();
        $second = $this->translation($run)->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id,
            'version_id' => $first['version_id'], 'units' => ['summary' => 'Sicher bezahlen'],
        ]);

        $this->assertSame(1, $second['written']);
        $this->assertArrayNotHasKey('remaining_units', $second);
        $this->assertSame(['title' => 'Warenkorb', 'summary' => 'Sicher bezahlen'], $row->fresh()->getCurrentContent());
    }

    #[Test]
    public function a_locale_entry_someone_created_while_the_tool_ran_refuses_the_write(): void
    {
        $source = $this->entry(['title' => 'Cart', 'summary' => '']);
        AiChangesetItem::creating(function () use ($source) {
            if (Content::query()->where('i18n_parent_id', $source->id)->doesntExist()) {
                app(CreateContent::class)->execute(['block_id' => $source->block_id, 'name' => 'Von Hand', 'slug' => 'von-hand', 'language_iso' => 'de', 'i18n_parent_id' => $source->id, 'content' => ['title' => 'Von Hand']], new Content, $this->space, $this->user);
            }
        });

        try {
            $this->translation($this->startRun())->execute(['source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id, 'name' => 'KI', 'units' => ['title' => 'KI']]);
            $this->fail('The write should have been refused.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('in the meantime', $e->getMessage());
        } finally {
            AiChangesetItem::flushEventListeners();
        }

        // One connection here, so the person's row rolls back with the refused write. The real race ran on MySQL, see w8-direct-writes.md.
        $this->assertSame(0, Content::query()->where('name', 'KI')->count());
        $this->assertSame(0, AiChangesetItem::query()->where('target_type', 'content')->count());
    }

    #[Test]
    public function a_translation_that_rightly_equals_its_source_is_done_and_not_owed_again(): void
    {
        $source = $this->entry(['title' => 'b10cks', 'summary' => '']);
        $run = $this->startRun();

        $result = $this->translation($run)->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->current_version_id,
            'name' => 'b10cks', 'units' => ['title' => 'b10cks'],
        ]);

        $this->assertArrayNotHasKey('remaining_units', $result);
        $task = app(GetTranslationTaskTool::class, ['run' => $run, 'space' => $this->space, 'actor' => $this->user])->execute(['id' => $source->id, 'language' => 'de']);
        $this->assertSame([], $task['units']);
    }

    #[Test]
    public function a_field_a_person_changed_stays_theirs_as_a_conflict_and_the_rest_of_the_operation_continues(): void
    {
        $raced = $this->entry(['title' => 'One', 'summary' => 'Original']);
        $other = $this->entry(['title' => 'Two', 'summary' => 'Original']);
        $read = $raced->current_version_id;

        $this->driver->push(
            function () use ($raced, $read) {
                // An editor saves while the agent is thinking, with `read` still the version it read.
                app(UpdateContent::class)->execute(['content' => ['title' => 'One', 'summary' => 'Typed by a person'], 'force' => true], $raced->fresh(), $this->space, $this->user);

                return $this->update($raced, ['summary' => 'AI text'], $read);
            },
            $this->update($other, ['summary' => 'AI text']),
            FakeAiDriver::text('Batch done.'),
        );

        $operation = $this->approve([$raced->id, $other->id]);

        $this->assertSame('Typed by a person', $raced->fresh()->getCurrentContent()['summary']);
        $this->assertSame('AI text', $other->fresh()->getCurrentContent()['summary']);
        $this->assertSame(AiOperationState::Completed, $operation->state);

        $conflict = AiChangesetItem::query()->where('target_id', $raced->id)->sole();
        $this->assertSame([AiChangesetItemStatus::Conflict, 'Typed by a person'], [$conflict->status, $conflict->current]);
        $this->assertSame(AiOperationItemStatus::Conflict, AiOperationItem::query()->where('target_id', $raced->id)->sole()->status);
        $this->assertSame(AiOperationItemStatus::Written, AiOperationItem::query()->where('target_id', $other->id)->sole()->status);

        // Conflicts come first in what a person sees.
        $this->getJson($this->url("ai/operations/{$operation->id}/changes"))
            ->assertJsonPath('summary', ['changed' => 1, 'conflicts' => 1, 'flagged' => 0])
            ->assertJsonPath('data.0.target_id', $raced->id)
            ->assertJsonPath('data.0.conflicts', 1);
        $this->getJson($this->url('ai/threads'))->assertJsonPath('data.0.status', 'needs_review');
    }

    #[Test]
    public function a_retry_on_a_conflict_is_refused_and_the_person_keeps_their_text_until_they_decide(): void
    {
        $page = $this->entry(['title' => 'One', 'summary' => 'Original']);
        $read = $page->current_version_id;
        $this->driver->push(
            function () use ($page, $read) {
                app(UpdateContent::class)->execute(['content' => ['title' => 'One', 'summary' => 'Typed by a person'], 'force' => true], $page->fresh(), $this->space, $this->user);

                return $this->update($page, ['summary' => 'AI text'], $read);
            },
            fn () => $this->update($page->fresh(), ['summary' => 'AI text built on what the person typed', 'title' => 'AI title']),
            FakeAiDriver::text('Batch done.'),
        );

        $this->approve([$page->id]);

        $content = $page->fresh()->getCurrentContent();
        $this->assertSame(['Typed by a person', 'AI title'], [$content['summary'], $content['title']]);
        $conflict = AiChangesetItem::query()->where('path', 'summary')->sole();
        $this->assertSame([AiChangesetItemStatus::Conflict, 'Typed by a person'], [$conflict->status, $conflict->current]);
        $this->assertSame(AiOperationItemStatus::Conflict, AiOperationItem::query()->sole()->status);

        $tool = fn () => app(ProposeUpdateTool::class, ['run' => $this->startRun(), 'space' => $this->space, 'actor' => $this->user]);
        $write = fn () => $tool()->execute(['target_type' => 'content', 'id' => $page->id, 'version_id' => $page->fresh()->current_version_id, 'changes' => [['path' => 'summary', 'value' => 'Another task']]]);

        // A new task is refused too, and told why.
        $refused = $write();
        $this->assertSame(0, $refused['written']);
        $this->assertSame([['item_id' => $conflict->id, 'path' => 'summary']], $refused['conflicts']);
        $this->assertStringContainsString('do not write them again', $refused['conflict_note']);
        $this->assertSame('Typed by a person', $page->fresh()->getCurrentContent()['summary']);

        // Keep current is a person's decision, so the field is free again.
        $this->postJson($this->url("ai/changesets/{$conflict->changeset_id}/reject"), ['item_ids' => [$conflict->id]])->assertOk();
        $this->assertSame(1, $write()['written']);
        $this->assertSame('Another task', $page->fresh()->getCurrentContent()['summary']);
    }

    #[Test]
    public function a_translation_write_leaves_a_conflicted_field_to_the_person_and_writes_the_others(): void
    {
        $source = $this->entry(['title' => 'Cart', 'summary' => 'Pay securely']);
        $run = $this->startRun();
        $created = $this->translation($run)->execute(['source_id' => $source->id, 'language' => 'de', 'name' => 'Warenkorb', 'source_version_id' => $source->current_version_id, 'units' => ['title' => 'Warenkorb', 'summary' => 'Sicher bezahlen']]);
        $translation = Content::query()->findOrFail($created['destination_id']);
        AiChangesetItem::query()->create([
            'changeset_id' => AiChangesetItem::query()->firstOrFail()->changeset_id, 'operation' => 'update', 'target_type' => 'content', 'target_id' => $translation->id,
            'path' => 'summary', 'before' => 'Sicher bezahlen', 'after' => 'KI', 'current' => 'Sicher bezahlen', 'status' => AiChangesetItemStatus::Conflict,
        ]);
        app(UpdateContent::class)->execute(['content' => ['title' => 'Cart new', 'summary' => 'Pay now'], 'force' => true], $source->fresh(), $this->space, $this->user);

        $result = $this->translation($this->startRun())->execute([
            'source_id' => $source->id, 'language' => 'de', 'source_version_id' => $source->fresh()->current_version_id, 'version_id' => $translation->fresh()->current_version_id,
            'units' => ['title' => 'Warenkorb neu', 'summary' => 'KI wieder'],
        ]);

        $this->assertSame(1, $result['written']);
        $this->assertSame(['summary'], array_column($result['conflicts'], 'path'));
        $document = $translation->fresh()->getCurrentContent();
        $this->assertSame(['Warenkorb neu', 'Sicher bezahlen'], [$document['title'], $document['summary']]);
    }

    #[Test]
    public function an_agent_may_correct_its_own_text_from_an_older_read_without_a_conflict(): void
    {
        $page = $this->entry(['title' => 'One', 'summary' => 'Original']);
        $read = $page->current_version_id;
        $this->driver->push($this->update($page, ['summary' => 'First try'], $read), $this->update($page, ['summary' => 'Better'], $read), FakeAiDriver::text('Done.'));

        $this->approve([$page->id]);

        $this->assertSame('Better', $page->fresh()->getCurrentContent()['summary']);
        $this->assertSame(0, AiChangesetItem::query()->where('status', AiChangesetItemStatus::Conflict->value)->count());
    }

    #[Test]
    public function a_content_change_without_the_version_it_read_is_refused(): void
    {
        $page = $this->entry(['title' => 'One', 'summary' => 'Original']);
        $this->driver->push(FakeAiDriver::toolCall('propose_update', [
            'target_type' => 'content', 'id' => $page->id, 'changes' => [['path' => 'summary', 'value' => 'Blind']],
        ]), FakeAiDriver::text('Stopped.'));

        $this->approve([$page->id]);

        $this->assertSame('Original', $page->fresh()->getCurrentContent()['summary']);
        $this->assertSame(0, AiChangesetItem::query()->count());
    }

    #[Test]
    public function a_data_entry_change_stays_a_proposal_while_content_of_the_same_run_is_written(): void
    {
        $page = $this->entry(['title' => 'One', 'summary' => 'Original']);
        $source = DataSource::factory()->create();
        $entry = DataEntry::factory()->create(['data_source_id' => $source->id, 'key' => 'yes', 'value' => 'Yes']);
        $this->driver->push(
            $this->update($page, ['summary' => 'AI text']),
            FakeAiDriver::toolCall('propose_update', ['target_type' => 'data_entry', 'id' => $entry->id, 'changes' => [['path' => 'value', 'value' => 'Yep']]]),
            FakeAiDriver::text('Done.'),
        );

        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'prompt' => 'Tidy up.', 'background' => true])->assertStatus(202);

        $this->assertSame('AI text', $page->fresh()->getCurrentContent()['summary']);
        $this->assertSame('Yes', $entry->fresh()->value);
        $proposal = AiChangesetItem::query()->where('target_type', 'data_entry')->sole();
        $this->assertSame(AiChangesetItemStatus::Pending, $proposal->status);
        $this->assertSame(AiChangesetItemStatus::Accepted, AiChangesetItem::query()->where('target_type', 'content')->sole()->status);
        $this->assertSame(1, AiChangeset::query()->sole()->pending_count);

        $this->postJson($this->url("ai/changesets/{$proposal->changeset_id}/accept"), ['item_ids' => [$proposal->id]])->assertOk()->assertJsonPath('accepted', 1);
        $this->assertSame('Yep', $entry->fresh()->value);
    }

    #[Test]
    public function an_operation_holding_both_finishes_its_content_and_only_its_proposals_wait(): void
    {
        $page = $this->entry(['title' => 'One', 'summary' => 'Original']);
        $this->driver->push($this->update($page, ['summary' => 'AI text']), FakeAiDriver::text('Done.'));
        $operation = $this->approve([$page->id]);

        // A proposal for a target without a draft in the same changeset. No tool mixes kinds inside one batch yet.
        $operation->changeset->items()->create([
            'operation' => AiChangesetItem::OPERATION_UPDATE, 'target_type' => 'data_entry', 'target_id' => 'entry', 'path' => 'value', 'field_type' => 'text', 'before' => 'Yes', 'after' => 'Yep', 'status' => AiChangesetItemStatus::Pending,
        ]);
        $operation->changeset->refreshCounts();

        $this->assertSame(AiOperationState::Completed, $operation->refresh()->state);
        $review = $this->getJson($this->url("ai/operations/{$operation->id}/review"))->assertOk()->json('data');
        $this->assertSame(['data_entry'], array_column($review, 'target_type'));
        $this->assertSame(1, $review[0]['bulk']);
        $this->getJson($this->url("ai/operations/{$operation->id}/changes"))->assertJsonPath('summary.changed', 1);
    }

    #[Test]
    public function an_actor_who_may_not_edit_gets_a_clear_error_and_nothing_is_written(): void
    {
        $page = $this->entry(['title' => 'One', 'summary' => 'Original']);
        $viewer = User::factory()->create();
        $this->assignSpaceRole($this->space, $viewer, 'viewer');
        $run = $this->startRun($viewer);
        $tool = app(ProposeTranslationTool::class, ['run' => $run, 'space' => $this->space, 'actor' => $viewer]);

        try {
            $tool->execute(['source_id' => $page->id, 'language' => 'de', 'source_version_id' => $page->current_version_id, 'name' => 'Eins', 'units' => ['title' => 'Eins']]);
            $this->fail('The write should have been refused.');
        } catch (ToolError $e) {
            $this->assertStringContainsString('may not edit', $e->getMessage());
        }

        $this->assertSame(0, Content::query()->where('i18n_parent_id', $page->id)->count());
        $this->assertSame(0, AiChangesetItem::query()->count());
    }

    #[Test]
    public function approving_content_work_needs_the_right_to_edit_content(): void
    {
        $page = $this->entry(['title' => 'One']);
        $operation = $this->operation([$page->id]);

        // The actor can no longer edit: the approval does not lend them what they lost.
        app(MembershipService::class)->removeSpaceMembership($this->space, $this->user);
        $this->assignSpaceRole($this->space, $this->user, 'viewer');

        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $operation->plan_hash, 'max_usd' => 1, 'scope_hash' => $operation->counts['scope_hash']])->assertForbidden();
        $this->assertSame(AiOperationState::Draft, $operation->refresh()->state);
        $this->assertSame(0, $this->driver->calls);
    }

    #[Test]
    public function an_actor_who_lost_edit_rights_mid_way_pauses_the_operation_before_the_next_batch(): void
    {
        $pages = [$this->entry(['title' => 'One']), $this->entry(['title' => 'Two']), $this->entry(['title' => 'Three'])];
        $this->driver->push(
            $this->update($pages[0], ['summary' => 'S1']),
            function () use ($pages) {
                app(MembershipService::class)->removeSpaceMembership($this->space, $this->user);
                $this->assignSpaceRole($this->space, $this->user, 'viewer');

                return $this->update($pages[1], ['summary' => 'S2']);
            },
            FakeAiDriver::text('Batch one.'),
        );

        $operation = $this->approve(array_map(fn (Content $page) => $page->id, $pages));

        $this->assertSame([AiOperationState::Paused, AiOperationPauseReason::AccessRevoked], [$operation->state, $operation->pause_reason]);
        // Refused, not written.
        $this->assertArrayNotHasKey('summary', $pages[1]->fresh()->getCurrentContent());
        $this->assertSame(1, AiOperationItem::query()->where('target_id', $pages[2]->id)->whereIn('status', AiOperationItemStatus::owed())->count());
    }

    #[Test]
    public function a_budget_stop_still_pauses_and_what_was_written_stays(): void
    {
        $pages = [$this->entry(['title' => 'One']), $this->entry(['title' => 'Two']), $this->entry(['title' => 'Three'])];
        $this->driver->push(
            $this->update($pages[0], ['summary' => 'S1']),
            $this->update($pages[1], ['summary' => 'S2'], cost: 2.0),
        );

        $operation = $this->approve(array_map(fn (Content $page) => $page->id, $pages));

        $this->assertSame([AiOperationState::Paused, AiOperationPauseReason::Budget], [$operation->state, $operation->pause_reason]);
        $this->assertSame('S1', $pages[0]->fresh()->getCurrentContent()['summary']);
        $this->assertSame(1, AiOperationItem::query()->where('target_id', $pages[2]->id)->whereIn('status', AiOperationItemStatus::owed())->count());
    }

    #[Test]
    public function group_decisions_never_touch_content(): void
    {
        $page = $this->entry(['title' => 'One', 'summary' => 'Original']);
        $this->driver->push($this->update($page, ['summary' => 'AI text']), FakeAiDriver::text('Done.'));
        $operation = $this->approve([$page->id]);
        $group = 'update|content|summary|text';

        $this->postJson($this->url("ai/operations/{$operation->id}/review/reject"), ['group' => $group])->assertOk()->assertJsonPath('data.rejected', 0);
        $this->assertSame(AiChangesetItemStatus::Accepted, AiChangesetItem::query()->sole()->status);
        $this->assertSame([], $this->getJson($this->url("ai/operations/{$operation->id}/review"))->json('data'));
    }

    /** @param list<string> $ids */
    private function approve(array $ids, float $maxUsd = 1.0): AiOperation
    {
        $operation = $this->operation($ids);
        $this->postJson($this->url("ai/operations/{$operation->id}/approve"), ['plan_hash' => $operation->plan_hash, 'max_usd' => $maxUsd, 'scope_hash' => $operation->counts['scope_hash']])->assertOk();

        return $operation->refresh();
    }

    /** @param list<string> $ids */
    private function operation(array $ids): AiOperation
    {
        $id = $this->postJson($this->url('ai/operations'), ['kind' => 'general', 'prompt' => 'Rewrite.', 'scope' => ['type' => 'content', 'ids' => $ids]])->assertCreated()->json('data.id');
        $hash = $this->patchJson($this->url("ai/operations/{$id}"), ['plan' => ['summary' => 'Rewrite each summary.']])->assertOk()->json('data.plan_hash');
        $operation = AiOperation::query()->findOrFail($id);
        $operation->plan_hash = $hash;

        return $operation;
    }

    private function startRun(?User $actor = null): AiRun
    {
        return AiRun::query()->forceCreate([
            'space_id' => $this->space->id, 'actor_id' => ($actor ?? $this->user)->id, 'surface' => 'cmdk', 'state' => 'running',
            'setup' => ['title' => 'Translate'], 'transcript' => [],
        ]);
    }

    private function translation(AiRun $run): ProposeTranslationTool
    {
        return app(ProposeTranslationTool::class, ['run' => $run, 'space' => $this->space, 'actor' => $this->user]);
    }

    /** @param array<string, string> $changes */
    private function update(Content $page, array $changes, ?string $versionId = null, ?float $cost = null): TurnResult
    {
        return new TurnResult('', [new ToolCall('call_'.Str::random(6), 'propose_update', [
            'target_type' => 'content',
            'id' => $page->id,
            'version_id' => $versionId ?? $page->current_version_id,
            'changes' => array_map(fn ($path, $value) => ['path' => $path, 'value' => $value], array_keys($changes), $changes),
        ])], new TurnUsage(10, 5, $cost));
    }

    /** @param array<string, string> $values */
    private function entry(array $values): Content
    {
        $content = new Content;
        $content->forceFill([
            'block_id' => $this->page->id, 'name' => 'Entry '.Str::random(4), 'slug' => Str::lower(Str::random(8)),
            'full_slug' => '/'.Str::lower(Str::random(8)), 'language_iso' => 'en',
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
