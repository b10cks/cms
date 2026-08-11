<?php

namespace Tests\Feature\Mgmt;

use App\Jobs\ProcessAutomation;
use App\Mail\Automation\AutomationMessage;
use App\Models\Management\Automation;
use App\Models\Management\AutomationAction;
use App\Models\Management\AutomationExecution;
use App\Models\Management\Space;
use App\Models\Space\Content;
use App\Models\Space\DataSource;
use App\Models\User;
use App\Services\Automation\AutomationContextFactory;
use App\Services\Automation\AutomationUsageService;
use App\Services\Automation\BaseAutomationProcessor;
use App\Services\Automation\Enums\TriggerType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SpaceTestingTrait;

class AutomationTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SpaceTestingTrait;

    protected User $owner;

    protected User $editor;

    protected Space $space;

    protected Space $otherSpace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->editor = User::factory()->create();
        $this->space = Space::factory()->create();
        $this->otherSpace = Space::factory()->create();

        $this->assignSpaceRole($this->space, $this->owner, 'owner');
        $this->assignSpaceRole($this->space, $this->editor, 'editor');
        $this->setUpSpaceTesting($this->space);
    }

    #[Test]
    public function owner_can_create_an_automation_linked_to_an_action_in_the_same_space(): void
    {
        $this->actingAs($this->owner);

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
        ]);

        $response = $this->postJson(route('mgmt.automations.store', $this->space->id), [
            'name' => 'Notify On Publish',
            'description' => 'Dispatch a webhook whenever content changes.',
            'action_id' => $action->id,
            'trigger' => [
                'type' => 'manual',
                'config' => [
                    'payload' => [
                        'channel' => 'ops',
                    ],
                ],
            ],
            'is_active' => true,
            'execution_limit' => 25,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Notify On Publish');
        $response->assertJsonPath('data.action_id', $action->id);
        $response->assertJsonPath('data.trigger.type', 'manual');

        $this->assertDatabaseHas('automations', [
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'name' => 'Notify On Publish',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function editor_cannot_create_automations(): void
    {
        $this->actingAs($this->editor);

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
        ]);

        $response = $this->postJson(route('mgmt.automations.store', $this->space->id), [
            'name' => 'Blocked Automation',
            'action_id' => $action->id,
            'trigger' => [
                'type' => 'manual',
                'config' => [],
            ],
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function automation_creation_rejects_actions_from_other_spaces(): void
    {
        $this->actingAs($this->owner);

        $foreignAction = AutomationAction::factory()->create([
            'space_id' => $this->otherSpace->id,
        ]);

        $response = $this->postJson(route('mgmt.automations.store', $this->space->id), [
            'name' => 'Cross Space Automation',
            'action_id' => $foreignAction->id,
            'trigger' => [
                'type' => 'manual',
                'config' => [],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['action_id']);
    }

    #[Test]
    public function manual_triggers_queue_the_job_with_merged_payload_and_touch_the_timestamp(): void
    {
        $this->actingAs($this->owner);
        Queue::fake();

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'trigger_type' => 'manual',
            'trigger_config' => [
                'payload' => [
                    'channel' => 'ops',
                    'source' => 'configured',
                ],
            ],
            'last_triggered_at' => null,
        ]);

        $response = $this->postJson(route('mgmt.automations.trigger', [
            'space' => $this->space->id,
            'automation' => $automation->id,
        ]), [
            'payload' => [
                'source' => 'request',
                'ticket' => 'INC-42',
            ],
        ]);

        $response->assertOk();
        $this->assertNotNull($automation->fresh()->last_triggered_at);
        Queue::assertPushed(ProcessAutomation::class);

        $execution = AutomationExecution::query()
            ->where('automation_id', $automation->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($execution);
        $this->assertSame('queued', $execution->status);
        $this->assertSame('ops', data_get($execution->context, 'channel'));
        $this->assertSame('manual', data_get($execution->context, 'source'));
        $this->assertSame('INC-42', data_get($execution->context, 'ticket'));
        $this->assertSame('manual', data_get($execution->context, 'trigger.type'));
    }

    #[Test]
    public function execution_history_endpoint_is_scoped_to_the_space(): void
    {
        $this->actingAs($this->owner);

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
        ]);

        $foreignAction = AutomationAction::factory()->create([
            'space_id' => $this->otherSpace->id,
        ]);

        $foreignAutomation = Automation::factory()->create([
            'space_id' => $this->otherSpace->id,
            'action_id' => $foreignAction->id,
        ]);

        $visibleExecution = AutomationExecution::factory()->failed()->create([
            'automation_id' => $automation->id,
        ]);

        AutomationExecution::factory()->completed()->create([
            'automation_id' => $foreignAutomation->id,
        ]);

        $response = $this->getJson(route('mgmt.automation-executions.index', [
            'space' => $this->space->id,
            'status' => 'failed',
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $visibleExecution->id);
    }

    #[Test]
    public function replaying_a_failed_execution_queues_a_new_run(): void
    {
        $this->actingAs($this->owner);
        Queue::fake();

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
        ]);

        $execution = AutomationExecution::factory()->failed()->create([
            'automation_id' => $automation->id,
            'context' => [
                'source' => 'manual',
                'ticket' => 'INC-100',
            ],
        ]);

        $response = $this->postJson(route('mgmt.automation-executions.replay', [
            'space' => $this->space->id,
            'automationExecution' => $execution->id,
        ]));

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'queued');
        $response->assertJsonPath('data.automation_id', $automation->id);

        $queuedExecution = AutomationExecution::query()
            ->where('automation_id', $automation->id)
            ->where('status', 'queued')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($queuedExecution);
        $this->assertSame('replay', data_get($queuedExecution->context, 'source'));
        $this->assertSame($execution->id, data_get($queuedExecution->context, 'replayed_from_execution_id'));
        Queue::assertPushed(ProcessAutomation::class);
    }

    #[Test]
    public function processor_completes_a_previously_queued_execution(): void
    {
        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
            'type' => 'void',
            'config' => [
                'message' => 'Automation {{ automation.name }} finished.',
            ],
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'trigger_type' => 'manual',
            'execution_count' => 0,
        ]);

        $usageService = $this->app->make(AutomationUsageService::class);
        $queuedExecution = $usageService->queueExecution($automation, [
            'source' => 'manual',
            'ticket' => 'INC-7',
        ]);

        $processor = $this->app->make(BaseAutomationProcessor::class);
        $processor->process($automation->id, [
            'source' => 'manual',
            'ticket' => 'INC-7',
            'execution_id' => $queuedExecution->id,
        ]);

        $queuedExecution->refresh();
        $automation->refresh();
        $action->refresh();

        $this->assertSame('completed', $queuedExecution->status);
        $this->assertNotNull($queuedExecution->started_at);
        $this->assertNotNull($queuedExecution->completed_at);
        $this->assertSame(1, $automation->execution_count);
        $this->assertSame('completed', $action->last_execution_status);
    }

    #[Test]
    public function email_actions_render_content_placeholders_with_or_without_inner_spacing(): void
    {
        Mail::fake();

        $action = AutomationAction::factory()->email()->create([
            'space_id' => $this->space->id,
            'config' => [
                'to' => ['ops@example.com'],
                'cc' => [],
                'bcc' => [],
                'reply_to' => [],
                'subject' => 'Content published: {{ content.title }}',
                'body' => 'Slug: {{content.slug}}',
            ],
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'trigger_type' => 'manual',
        ]);

        $processor = $this->app->make(BaseAutomationProcessor::class);
        $processor->process($automation->id, [
            'source' => 'manual',
            'content' => [
                'title' => 'Spring Launch',
                'slug' => 'spring-launch',
            ],
        ]);

        Mail::assertSent(AutomationMessage::class, function (AutomationMessage $mail) {
            return $mail->subjectLine === 'Content published: Spring Launch'
                && $mail->body === 'Slug: spring-launch';
        });
    }

    #[Test]
    public function queued_executions_keep_using_the_snapshotted_action_definition_after_edits(): void
    {
        Mail::fake();

        $action = AutomationAction::factory()->email()->create([
            'space_id' => $this->space->id,
            'config' => [
                'to' => ['ops@example.com'],
                'cc' => [],
                'bcc' => [],
                'reply_to' => [],
                'subject' => 'Queued: {{ content.title }}',
                'body' => 'Original body for {{content.slug}}',
            ],
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'trigger_type' => 'manual',
        ]);

        $execution = $this->app->make(AutomationUsageService::class)->queueExecution($automation, [
            'source' => 'manual',
            'content' => [
                'title' => 'Frozen Snapshot',
                'slug' => 'frozen-snapshot',
            ],
        ]);

        $action->update([
            'type' => 'void',
            'config' => [
                'message' => 'This should not replace the queued email.',
            ],
        ]);

        $this->app->make(BaseAutomationProcessor::class)->process($automation->id, [
            'execution_id' => $execution->id,
        ]);

        Mail::assertSent(AutomationMessage::class, function (AutomationMessage $mail) {
            return $mail->subjectLine === 'Queued: Frozen Snapshot'
                && $mail->body === 'Original body for frozen-snapshot';
        });
    }

    #[Test]
    public function content_trigger_context_exposes_a_title_alias_for_templates(): void
    {
        $content = new Content;
        $content->forceFill([
            'id' => fake()->uuid(),
            'name' => 'Release Notes',
            'slug' => 'release-notes',
        ]);

        $context = $this->app->make(AutomationContextFactory::class)->forModelEvent(
            $content,
            TriggerType::ON_UPDATE,
            before: [
                'id' => $content->id,
                'name' => 'Old Name',
                'slug' => 'old-name',
            ],
            after: [
                'id' => $content->id,
                'name' => 'Release Notes',
                'slug' => 'release-notes',
            ],
            changedColumns: ['name', 'slug'],
            space: $this->space,
        );

        $this->assertSame('Release Notes', data_get($context, 'content.title'));
        $this->assertSame('Old Name', data_get($context, 'previous.title'));
        $this->assertSame('Release Notes', data_get($context, 'record.title'));
    }

    #[Test]
    public function catalog_endpoint_lists_supported_cms_tables_and_columns(): void
    {
        $this->actingAs($this->owner);

        $response = $this->getJson(route('mgmt.automations.trigger-catalog', [
            'space' => $this->space->id,
        ]));

        $response->assertOk();
        $response->assertJsonFragment(['table' => 'comment_reactions']);
        $response->assertJsonFragment(['table' => 'data_sources']);
        $response->assertJsonFragment(['table' => 'contents']);
    }

    #[Test]
    public function creating_a_supported_space_record_triggers_matching_automation(): void
    {
        $this->actingAs($this->owner);
        Queue::fake();

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
            'type' => 'void',
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'trigger_type' => 'on_insert',
            'trigger_config' => [
                'table' => 'data_sources',
            ],
        ]);

        $dataSource = DataSource::factory()->create([
            'name' => 'Pricing Tables',
            'slug' => 'pricing-tables',
        ]);

        $execution = AutomationExecution::query()
            ->where('automation_id', $automation->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($execution);
        $this->assertSame('queued', $execution->status);
        $this->assertSame('on_insert', data_get($execution->context, 'operation'));
        $this->assertSame('data_sources', data_get($execution->context, 'table'));
        $this->assertSame($dataSource->id, data_get($execution->context, 'record.id'));
        Queue::assertPushed(ProcessAutomation::class);
    }

    #[Test]
    public function update_triggers_can_be_limited_to_specific_columns(): void
    {
        $this->actingAs($this->owner);
        Queue::fake();

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
            'type' => 'void',
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'trigger_type' => 'on_update',
            'trigger_config' => [
                'table' => 'data_sources',
                'watch_columns' => ['slug'],
            ],
        ]);

        $dataSource = DataSource::factory()->create([
            'name' => 'Inventory',
            'slug' => 'inventory',
            'description' => 'Initial description',
        ]);

        $dataSource->update([
            'name' => 'Inventory API',
        ]);

        $this->assertDatabaseMissing('automation_executions', [
            'automation_id' => $automation->id,
        ]);

        $dataSource->update([
            'slug' => 'inventory-api',
        ]);

        $execution = AutomationExecution::query()
            ->where('automation_id', $automation->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($execution);
        $this->assertSame(['slug'], data_get($execution->context, 'changed_fields'));
        $this->assertSame('inventory', data_get($execution->context, 'changes.slug.before'));
        $this->assertSame('inventory-api', data_get($execution->context, 'changes.slug.after'));
        Queue::assertPushed(ProcessAutomation::class);
    }

    #[Test]
    public function execution_history_keeps_the_original_automation_and_action_snapshot_after_edits(): void
    {
        $this->actingAs($this->owner);

        $action = AutomationAction::factory()->email()->create([
            'space_id' => $this->space->id,
            'name' => 'Original Email',
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'name' => 'Original Automation',
            'description' => 'Send the original notification.',
        ]);

        $execution = $this->app->make(AutomationUsageService::class)->queueExecution($automation, [
            'source' => 'manual',
        ]);

        $action->update([
            'name' => 'Renamed Action',
            'type' => 'void',
            'config' => [
                'message' => 'Changed after the execution was queued.',
            ],
        ]);

        $automation->update([
            'name' => 'Renamed Automation',
            'description' => 'Updated after the execution was queued.',
        ]);

        $response = $this->getJson(route('mgmt.automation-executions.index', [
            'space' => $this->space->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $execution->id);
        $response->assertJsonPath('data.0.automation.name', 'Original Automation');
        $response->assertJsonPath('data.0.automation.description', 'Send the original notification.');
        $response->assertJsonPath('data.0.automation.action.name', 'Original Email');
        $response->assertJsonPath('data.0.automation.action.type', 'email');
    }

    #[Test]
    public function manual_content_triggers_build_a_record_context_for_the_targeted_content(): void
    {
        $this->actingAs($this->owner);
        Queue::fake();

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
            'type' => 'void',
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'trigger_type' => 'manual',
            'trigger_config' => [
                'table' => 'contents',
            ],
        ]);

        $content = Content::factory()->create([
            'name' => 'Spring Launch',
            'slug' => 'spring-launch',
            'full_slug' => '/spring-launch',
        ]);

        $response = $this->postJson(route('mgmt.automations.trigger', [
            'space' => $this->space->id,
            'automation' => $automation->id,
        ]), [
            'content_id' => $content->id,
        ]);

        $response->assertOk();
        Queue::assertPushed(ProcessAutomation::class);

        $execution = AutomationExecution::query()
            ->where('automation_id', $automation->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($execution);
        $this->assertSame('manual', data_get($execution->context, 'source'));
        $this->assertSame('manual', data_get($execution->context, 'operation'));
        $this->assertSame('contents', data_get($execution->context, 'table'));
        $this->assertSame($content->id, data_get($execution->context, 'record_id'));
        $this->assertSame('Spring Launch', data_get($execution->context, 'record.name'));
        $this->assertSame('Spring Launch', data_get($execution->context, 'content.title'));
        $this->assertSame($this->space->id, data_get($execution->context, 'space.id'));
    }

    #[Test]
    public function manual_content_triggers_enforce_block_restrictions(): void
    {
        $this->actingAs($this->owner);
        Queue::fake();

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
            'type' => 'void',
        ]);

        $content = Content::factory()->create([
            'name' => 'Restricted Item',
            'slug' => 'restricted-item',
            'full_slug' => '/restricted-item',
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'trigger_type' => 'manual',
            'trigger_config' => [
                'table' => 'contents',
                'block_ids' => [fake()->uuid()],
            ],
        ]);

        $response = $this->postJson(route('mgmt.automations.trigger', [
            'space' => $this->space->id,
            'automation' => $automation->id,
        ]), [
            'content_id' => $content->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['content_id']);
        Queue::assertNotPushed(ProcessAutomation::class);

        $automation->update([
            'trigger' => [
                'type' => 'manual',
                'config' => [
                    'table' => 'contents',
                    'block_ids' => [$content->block_id],
                ],
            ],
        ]);

        $this->postJson(route('mgmt.automations.trigger', [
            'space' => $this->space->id,
            'automation' => $automation->id,
        ]), [
            'content_id' => $content->id,
        ])->assertOk();

        Queue::assertPushed(ProcessAutomation::class);
    }

    #[Test]
    public function editors_can_list_and_trigger_manual_automations_but_not_manage_them(): void
    {
        $this->actingAs($this->editor);
        Queue::fake();

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
            'type' => 'void',
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'trigger_type' => 'manual',
            'trigger_config' => [
                'table' => 'contents',
            ],
        ]);

        $this->getJson(route('mgmt.automations.index', [
            'space' => $this->space->id,
            'trigger_type' => 'manual',
        ]))->assertOk();

        $content = Content::factory()->create([
            'name' => 'Editor Item',
            'slug' => 'editor-item',
            'full_slug' => '/editor-item',
        ]);

        $this->postJson(route('mgmt.automations.trigger', [
            'space' => $this->space->id,
            'automation' => $automation->id,
        ]), [
            'content_id' => $content->id,
        ])->assertOk();

        Queue::assertPushed(ProcessAutomation::class);

        $this->patchJson(route('mgmt.automations.update', [
            'space' => $this->space->id,
            'automation' => $automation->id,
        ]), [
            'name' => 'Renamed by editor',
        ])->assertStatus(403);
    }

    #[Test]
    public function content_tree_discovery_query_returns_active_manual_content_actions(): void
    {
        $this->actingAs($this->editor);

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
            'type' => 'void',
        ]);

        $contentAction = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'name' => 'Content Action',
            'trigger_type' => 'manual',
            'trigger_config' => ['table' => 'contents'],
            'is_active' => true,
        ]);

        Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'name' => 'Inactive Content Action',
            'trigger_type' => 'manual',
            'trigger_config' => ['table' => 'contents'],
            'is_active' => false,
        ]);

        Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'name' => 'Plain Manual Automation',
            'trigger_type' => 'manual',
            'trigger_config' => [],
            'is_active' => true,
        ]);

        // Mirrors the ContentTree discovery query verbatim: booleans reach the
        // API as the strings the URLSearchParams serializer produces.
        $response = $this->getJson(route('mgmt.automations.index', [
            'space' => $this->space->id,
            'trigger_type' => 'manual',
            'table' => 'contents',
            'is_active' => 'true',
            'per_page' => 100,
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $contentAction->id);
        $response->assertJsonPath('meta.per_page', 100);
    }

    #[Test]
    public function manual_content_triggers_reject_missing_contents(): void
    {
        $this->actingAs($this->owner);
        Queue::fake();

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
            'type' => 'void',
        ]);

        $automation = Automation::factory()->create([
            'space_id' => $this->space->id,
            'action_id' => $action->id,
            'trigger_type' => 'manual',
            'trigger_config' => [
                'table' => 'contents',
            ],
        ]);

        $this->postJson(route('mgmt.automations.trigger', [
            'space' => $this->space->id,
            'automation' => $automation->id,
        ]), [
            'content_id' => fake()->uuid(),
        ])->assertStatus(422);

        Queue::assertNotPushed(ProcessAutomation::class);
    }

    #[Test]
    public function manual_trigger_config_validates_table_and_block_restrictions(): void
    {
        $this->actingAs($this->owner);

        $action = AutomationAction::factory()->create([
            'space_id' => $this->space->id,
        ]);

        $basePayload = [
            'name' => 'Content Action',
            'action_id' => $action->id,
        ];

        $this->postJson(route('mgmt.automations.store', $this->space->id), $basePayload + [
            'trigger' => [
                'type' => 'manual',
                'config' => ['table' => 'not_a_table'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['trigger.config.table']);

        $this->postJson(route('mgmt.automations.store', $this->space->id), $basePayload + [
            'trigger' => [
                'type' => 'manual',
                'config' => ['block_ids' => [fake()->uuid()]],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['trigger.config.block_ids']);

        $this->postJson(route('mgmt.automations.store', $this->space->id), $basePayload + [
            'trigger' => [
                'type' => 'time_based',
                'config' => ['schedule' => '0 * * * *', 'block_ids' => [fake()->uuid()]],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['trigger.config.block_ids']);

        $this->postJson(route('mgmt.automations.store', $this->space->id), $basePayload + [
            'trigger' => [
                'type' => 'manual',
                'config' => ['table' => 'contents', 'block_ids' => [fake()->uuid()]],
            ],
        ])->assertStatus(201);
    }
}
