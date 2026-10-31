<?php

namespace Tests\Feature\Ai;

use App\Enums\AiReasoningEffort;
use App\Models\Management\Space;
use App\Models\Space\AiConversation;
use App\Models\Space\AiRun;
use App\Models\Space\AssetFolder;
use App\Models\Space\Block;
use App\Models\Space\Content;
use App\Models\Space\ContentVersion;
use App\Models\User;
use App\Services\Ai\Dto\AiModelDto;
use App\Services\Ai\ModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeAiDriver;
use Tests\Support\FakeModelRegistry;
use Tests\TestCase;
use Tests\Traits\FakesAi;
use Tests\Traits\SpaceTestingTrait;

class AiReasoningAndChipsTest extends TestCase
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
        app()->instance('currentSpace', $this->space);
        $this->giveSpaceAiConfig($this->space);
        $this->driver = $this->fakeAi();
        $this->actingAs($this->user);
    }

    #[Test]
    public function a_config_validates_and_returns_its_reasoning_effort_and_model_info(): void
    {
        $this->app->instance(ModelRegistry::class, new class($this->driver) extends FakeModelRegistry
        {
            public function findModel(string $fullId): ?AiModelDto
            {
                return new AiModelDto(id: 'fake-model', name: 'Fake Model', driver: 'fake', supportsTools: true, supportsReasoning: true, reasoningRequired: true);
            }
        });
        $config = $this->space->aiConfigs()->sole();

        $this->patchJson($this->url("ai-configs/{$config->id}"), ['reasoning_effort' => 'extreme'])->assertJsonValidationErrors('reasoning_effort');

        $this->patchJson($this->url("ai-configs/{$config->id}"), ['reasoning_effort' => 'off'])
            ->assertOk()
            ->assertJsonPath('data.reasoning_effort', 'off')
            ->assertJsonPath('data.model_info', ['name' => 'Fake Model', 'supports_vision' => false, 'supports_tools' => true, 'supports_reasoning' => true, 'reasoning_required' => true]);
    }

    #[Test]
    public function a_run_uses_its_own_level_over_the_configs_and_never_switches_off_a_model_that_must_reason(): void
    {
        $this->app->instance(ModelRegistry::class, new FakeModelRegistry($this->driver, reasoning: true, reasoningRequired: true));
        $this->space->aiConfigs()->sole()->update(['reasoning_effort' => 'off']);
        $this->driver->push(FakeAiDriver::text('One'), FakeAiDriver::text('Two'));

        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'prompt' => 'Hi', 'reasoning_effort' => 'extreme'])->assertJsonValidationErrors('reasoning_effort');
        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'prompt' => 'Hi'])->streamedContent();
        $thread = AiConversation::query()->sole();
        $this->travel(1)->seconds();
        $this->postJson($this->url('ai/runs'), ['surface' => 'cmdk', 'prompt' => 'Again', 'conversation_id' => $thread->id, 'reasoning_effort' => 'high'])->streamedContent();

        $this->assertSame([AiReasoningEffort::Low, AiReasoningEffort::High], array_map(fn ($options) => $options->reasoning, $this->driver->options));
        $this->assertSame([null, AiReasoningEffort::High], AiRun::query()->orderBy('started_at')->pluck('reasoning_effort')->all());
        $this->assertSame('high', $this->getJson($this->url('ai/threads'))->json('data.0.reasoning_effort'));
    }

    #[Test]
    public function field_block_and_asset_folder_chips_reach_the_prompt_context(): void
    {
        $block = Block::query()->create(['external_id' => (string) Str::uuid(), 'name' => 'Page', 'slug' => 'page', 'type' => 'root', 'schema' => ['title' => ['type' => 'text', 'name' => 'Title']]]);
        $content = new Content;
        $content->forceFill(['block_id' => $block->id, 'name' => 'Home', 'slug' => 'home', 'full_slug' => '/home', 'language_iso' => 'en']);
        $content->id = strtolower((string) Str::ulid());
        $content->current_version_id = ContentVersion::query()->forceCreate(['content_id' => $content->id, 'content' => ['title' => 'Home'], 'created_by_id' => $this->user->id])->id;
        $content->save();
        $folder = AssetFolder::query()->create(['external_id' => (string) Str::uuid(), 'name' => 'Campaign shots']);

        $this->postJson($this->url('ai/runs'), ['surface' => 'dock', 'prompt' => 'Shorter please', 'context' => ['chips' => [
            ['type' => 'field', 'id' => $content->id, 'label' => 'Home: Title', 'field' => 'title'],
            ['type' => 'block', 'id' => $block->id, 'label' => 'Page'],
            ['type' => 'asset_folder', 'id' => $folder->id, 'label' => 'Campaign shots'],
        ]]])->streamedContent();

        $user = collect($this->driver->transcripts[0])->last(fn (array $message) => $message['role'] === 'user')['content'];
        $this->assertStringContainsString('The editor is focused on one field', $user);
        $this->assertStringContainsString('"focused_field":{"entry_id":"'.$content->id.'","field":"title"}', $user);
        $this->assertStringContainsString('"content":{"title":"Home"}', $user);
        $this->assertStringContainsString('"blocks":[{"id":"'.$block->id.'","slug":"page"', $user);
        $this->assertStringContainsString('"name":"Campaign shots"', $user);
        $this->assertSame('title', AiConversation::query()->sole()->context[0]['field']);
    }

    private function url(string $path): string
    {
        return "/mgmt/v1/spaces/{$this->space->id}/{$path}";
    }
}
