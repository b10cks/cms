<?php

namespace Tests\Feature\Ai;

use App\Enums\AiChangesetItemStatus;
use App\Models\Management\Space;
use App\Models\Space\AiChangeset;
use App\Models\Space\AiChangesetItem;
use App\Models\Space\AiRun;
use App\Models\Space\Block;
use App\Models\Space\BlockVersion;
use App\Models\User;
use App\Services\Ai\AiRunService;
use App\Services\Ai\Run\StartRun;
use App\Services\Ai\Run\ToolCall;
use App\Services\Ai\Run\TurnResult;
use App\Services\Ai\Run\TurnUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiBlockProposalTest extends TestCase
{
    use FakesAi;
    use RefreshDatabase;
    use SpaceTestingTrait;

    private Space $space;

    private FakeAiDriver $driver;

    private Block $block;

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

        $this->block = Block::query()->create([
            'external_id' => (string) Str::uuid(),
            'name' => 'Page',
            'slug' => 'page',
            'type' => 'root',
            'schema' => [
                'title' => ['type' => 'text', 'name' => 'Title'],
            ],
            'editor' => [['header' => 'General', 'items' => ['title']], ['header' => 'SEO', 'items' => []]],
        ]);
    }

    #[Test]
    public function adding_a_field_and_editing_another_apply_on_accept_and_version_the_block(): void
    {
        $this->driver->push($this->propose([
            ['path' => 'schema.title', 'value' => ['type' => 'text', 'name' => 'Headline', 'required' => true]],
            ['path' => 'schema.summary', 'value' => ['type' => 'textarea', 'name' => 'Summary']],
        ]), FakeAiDriver::text('Done.'));
        $this->runToEnd();

        $changeset = AiChangeset::query()->sole();
        $this->assertSame(2, $changeset->items()->where('target_type', AiChangesetItem::TARGET_BLOCK)->where('target_id', $this->block->id)->count());

        $this->postJson($this->url("ai/changesets/{$changeset->id}/accept"))->assertOk()->assertJsonPath('accepted', 2);

        $block = $this->block->fresh();
        $this->assertSame('Headline', $block->schema->getField('title')->getLabel());
        $this->assertTrue($block->schema->getField('title')->isRequired());
        $this->assertSame('textarea', $block->schema->getField('summary')->getType());
        // The content editor only shows fields placed on a page.
        $this->assertSame(['title', 'summary'], $block->editor[0]['items']);
        $version = BlockVersion::query()->where('block_id', $block->id)->sole();
        $this->assertSame('Title', $version->data['schema']['title']['name']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): array
    {
        return [
            'removing a field' => [['path' => 'schema.title', 'value' => null], 'removing a field'],
            'renaming a key' => [['path' => 'schema.title', 'value' => ['key' => 'headline', 'type' => 'text', 'name' => 'Title']], 'renaming a field key'],
            'changing the type' => [['path' => 'schema.title', 'value' => ['type' => 'textarea', 'name' => 'Title']], 'changing the type'],
            'changing the slug' => [['path' => 'slug', 'value' => 'landing'], "changing a block's slug"],
        ];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function a_change_that_would_orphan_content_is_refused_and_nothing_is_stored(array $change, string $message): void
    {
        $this->driver->push($this->propose([$change]), FakeAiDriver::text('Gave up.'));
        $run = $this->runToEnd();

        $result = (string) collect($run->transcript)->firstWhere('role', 'tool')['content'];
        $this->assertStringContainsString($message, $result);
        $this->assertStringContainsString('block editor', $result);
        $this->assertSame(0, AiChangesetItem::query()->count());
    }

    #[Test]
    public function a_proposed_block_is_created_on_accept(): void
    {
        $this->driver->push(new TurnResult('', [new ToolCall('call_'.Str::random(6), 'propose_create', [
            'target_type' => 'block',
            'name' => 'Teaser',
            'slug' => 'teaser',
            'type' => 'nestable',
            'schema' => ['heading' => ['type' => 'text', 'name' => 'Heading']],
        ])], new TurnUsage(10, 5)), FakeAiDriver::text('Done.'));
        $this->runToEnd();

        $changeset = AiChangeset::query()->sole();
        $this->postJson($this->url("ai/changesets/{$changeset->id}/accept"))->assertOk()->assertJsonPath('accepted', 1);

        $block = Block::query()->where('slug', 'teaser')->sole();
        $this->assertSame('nestable', $block->type);
        $this->assertSame('text', $block->schema->getField('heading')->getType());
        $this->assertSame([['header' => 'General', 'items' => ['heading']]], $block->editor);
        $this->assertSame($block->id, $changeset->items()->sole()->target_id);
    }

    #[Test]
    public function a_new_block_without_slug_and_type_is_refused_and_nothing_is_stored(): void
    {
        $this->driver->push(new TurnResult('', [new ToolCall('call_'.Str::random(6), 'propose_create', [
            'target_type' => 'block',
            'name' => 'Teaser',
        ])], new TurnUsage(10, 5)), FakeAiDriver::text('Gave up.'));
        $run = $this->runToEnd();

        $result = (string) collect($run->transcript)->firstWhere('role', 'tool')['content'];
        $this->assertStringContainsString('slug:', $result);
        $this->assertStringContainsString('type:', $result);
        $this->assertSame(0, AiChangesetItem::query()->count());
    }

    #[Test]
    public function a_user_without_blocks_manage_cannot_accept(): void
    {
        $this->driver->push($this->propose([['path' => 'name', 'value' => 'Landing page']]), FakeAiDriver::text('Done.'));
        $this->runToEnd();
        $changeset = AiChangeset::query()->sole();

        $editor = User::factory()->create();
        $this->assignSpaceRole($this->space, $editor, 'editor');

        $this->actingAs($editor)->postJson($this->url("ai/changesets/{$changeset->id}/accept"))->assertForbidden();

        $this->assertSame(AiChangesetItemStatus::Pending, $changeset->items()->sole()->status);
        $this->assertSame('Page', $this->block->fresh()->name);
    }

    /** @param  list<array<string, mixed>>  $changes */
    private function propose(array $changes): TurnResult
    {
        return new TurnResult('', [new ToolCall('call_'.Str::random(6), 'propose_update', [
            'target_type' => 'block',
            'id' => 'page',
            'changes' => $changes,
        ])], new TurnUsage(10, 5));
    }

    private function runToEnd(): AiRun
    {
        $service = app(AiRunService::class);
        $run = $service->start(new StartRun($this->space, $this->user, 'cmdk', prompt: 'Improve the page block.'));
        iterator_to_array($service->advance($run), false);

        return $run->refresh();
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }
}
